<?php
declare(strict_types=1);

namespace ShopVoice\Inspection;

use ShopVoice\Auth\AuthException;
use ShopVoice\Auth\Session;
use ShopVoice\Db\Db;
use ShopVoice\Intent\IntentProvider;
use ShopVoice\Support\Clock;
use ShopVoice\Support\Id;
use ShopVoice\Support\Logger;

/**
 * Inspection mode (§8).
 *
 * A tech doing an inspection walks the car; he is not looking at every item at
 * once, and requiring the wake word between findings would kill it. So "start
 * inspection" opens a persistent listening session and the timeout logic
 * inverts: silence ends the current *item*, not the session.
 *
 * Three rules follow from that, and they are the whole design:
 *
 *   - findings arrive in any order, because Russ's route varies by vehicle. A
 *     system reading items in list order would fight him on every car.
 *   - a landed finding gets a confirmation chirp, not a spoken readback.
 *     Thirty spoken readbacks would drive him mad. Speech is reserved for the
 *     cases where the parse is actually uncertain.
 *   - at end_inspection, read back only what is missing. Short, and it is the
 *     one moment prompting is genuinely useful.
 */
final class InspectionService
{
    public const ACK_CHIRP = 'chirp';
    public const ACK_SPEAK = 'speak';

    /** Below this, we ask instead of filing the finding somewhere wrong. */
    private const SLOT_CONFIDENCE_THRESHOLD = 0.6;

    public function __construct(
        private Db $db,
        private IntentProvider $provider,
        private Logger $logger,
        private Clock $clock = new Clock(),
        private int $idleSeconds = 300,
    ) {
    }

    /** @return array{inspection_id:string,fields:list<array<string,mixed>>,spoken:string} */
    public function start(Session $session, string $templateId = 'tpl_default'): array
    {
        if (!$session->hasContext()) {
            throw new AuthException('No repair order is loaded — which vehicle am I inspecting?', 409);
        }

        // An inspection already open on this session is resumed, not duplicated:
        // saying "start inspection" twice is a thing that happens in a bay.
        $open = $this->openFor($session);
        if ($open !== null) {
            return [
                'inspection_id' => (string) $open['id'],
                'fields' => $this->fields($templateId),
                'spoken' => 'Inspection is already running.',
            ];
        }

        $id = Id::generate($this->clock);
        $now = $this->clock->iso();

        $this->db->insert('inspections', [
            'id' => $id,
            'template_id' => $templateId,
            'session_id' => $session->id,
            'user_id' => $session->userId,
            'ro_number' => (string) $session->contextRoNumber,
            'order_id' => $session->contextOrderId,
            'vehicle_id' => $session->contextVehicleId,
            'status' => 'open',
            'started_at' => $now,
        ]);

        $this->logger->info('inspection.started', [
            'inspection_id' => $id,
            'ro_number' => $session->contextRoNumber,
            'user_id' => $session->userId,
        ]);

        return [
            'inspection_id' => $id,
            'fields' => $this->fields($templateId),
            'spoken' => sprintf('Inspection open on %s. Call them out.', $session->contextLabel ?? 'this one'),
        ];
    }

    /**
     * Record one finding, in whatever order it was called.
     *
     * @return array{ack:string,spoken:?string,item:?array<string,mixed>,field_key:?string}
     */
    public function addItem(Session $session, string $transcript, ?string $inspectionId = null): array
    {
        $inspection = $inspectionId !== null
            ? $this->requireInspection($inspectionId)
            : $this->openFor($session);

        if ($inspection === null || $inspection['status'] !== 'open') {
            throw new AuthException('No inspection is open.', 409);
        }

        if ($this->hasTimedOut($inspection)) {
            $this->close((string) $inspection['id'], 'timed_out');
            throw new AuthException('That inspection timed out — say "start inspection" again.', 409);
        }

        $fields = $this->fields((string) $inspection['template_id']);
        $slot = $this->provider->slotInspectionItem($transcript, array_map(
            static fn (array $f): array => [
                'field_key' => $f['field_key'],
                'label' => $f['label'],
                'unit' => $f['unit'],
            ],
            $fields
        ));

        $fieldKey = $slot['field_key'] ?? null;
        $confidence = (float) ($slot['confidence'] ?? 0.0);
        $known = array_column($fields, 'field_key');

        if ($fieldKey === null || !in_array($fieldKey, $known, true) || $confidence < self::SLOT_CONFIDENCE_THRESHOLD) {
            // Uncertain parse: this is the case that earns a spoken response.
            $this->logger->info('inspection.item_unclear', [
                'inspection_id' => $inspection['id'],
                'confidence' => $confidence,
                'field_key' => $fieldKey,
            ]);
            return [
                'ack' => self::ACK_SPEAK,
                'spoken' => 'Which item was that for?',
                'item' => null,
                'field_key' => null,
            ];
        }

        $now = $this->clock->iso();
        $itemId = Id::generate($this->clock);

        // Re-calling an item corrects it. A tech who says "actually, front pads
        // three millimetres" means the second number, so the first is retired
        // rather than kept alongside it.
        $this->db->run(
            'UPDATE inspection_items SET undone_at = :now
             WHERE inspection_id = :inspection AND field_key = :field AND undone_at IS NULL',
            ['now' => $now, 'inspection' => $inspection['id'], 'field' => $fieldKey]
        );

        $this->db->insert('inspection_items', [
            'id' => $itemId,
            'inspection_id' => (string) $inspection['id'],
            'field_key' => $fieldKey,
            'value' => $slot['value'] ?? null,
            'condition_code' => $slot['condition'] ?? null,
            // Verbatim, same rule as notes: the tech's words are the record.
            'raw_transcript' => $transcript,
            'recorded_at' => $now,
        ]);

        $this->db->update('inspections', ['last_item_at' => $now], ['id' => $inspection['id']]);

        return [
            'ack' => self::ACK_CHIRP,
            'spoken' => null,
            'item' => [
                'id' => $itemId,
                'field_key' => $fieldKey,
                'label' => $this->labelFor($fields, $fieldKey),
                'value' => $slot['value'] ?? null,
                'condition' => $slot['condition'] ?? null,
                'recorded_at' => $now,
            ],
            'field_key' => $fieldKey,
        ];
    }

    /**
     * Close the inspection and read back only what is missing (§8).
     *
     * @return array{spoken:string,missing:list<string>,recorded:list<array<string,mixed>>,deferred:list<array<string,mixed>>}
     */
    public function end(Session $session, ?string $inspectionId = null): array
    {
        $inspection = $inspectionId !== null
            ? $this->requireInspection($inspectionId)
            : $this->openFor($session);

        if ($inspection === null) {
            throw new AuthException('No inspection is open.', 409);
        }

        $fields = $this->fields((string) $inspection['template_id']);
        $recorded = $this->items((string) $inspection['id']);
        $recordedKeys = array_column($recorded, 'field_key');

        $missing = [];
        foreach ($fields as $field) {
            if ((int) $field['required'] === 1 && !in_array($field['field_key'], $recordedKeys, true)) {
                $missing[] = (string) ($field['spoken_label'] ?: strtolower((string) $field['label']));
            }
        }

        $this->close((string) $inspection['id'], 'ended');

        return [
            'spoken' => self::speakMissing($missing),
            'missing' => $missing,
            'recorded' => $recorded,
            // Yellow findings and declined services both become deferred work (§8).
            'deferred' => array_values(array_filter(
                $recorded,
                static fn (array $i): bool => in_array($i['condition'], ['yellow', 'red'], true)
            )),
        ];
    }

    /** Sweep sessions that went quiet — ~5 minutes idle closes an inspection (§8). */
    public function expireIdle(): int
    {
        $rows = $this->db->all("SELECT * FROM inspections WHERE status = 'open'");
        $closed = 0;
        foreach ($rows as $row) {
            if ($this->hasTimedOut($row)) {
                $this->close((string) $row['id'], 'timed_out');
                $closed++;
            }
        }
        return $closed;
    }

    /** @return array<string,mixed>|null */
    public function openFor(Session $session): ?array
    {
        return $this->db->first(
            "SELECT * FROM inspections WHERE session_id = :session AND status = 'open'
             ORDER BY started_at DESC LIMIT 1",
            ['session' => $session->id]
        );
    }

    /** Undo target for a single finding (§6). */
    public function undoItem(string $itemId): bool
    {
        $row = $this->db->first('SELECT * FROM inspection_items WHERE id = :id', ['id' => $itemId]);
        if ($row === null || $row['undone_at'] !== null) {
            return false;
        }
        $this->db->update('inspection_items', ['undone_at' => $this->clock->iso()], ['id' => $itemId]);
        return true;
    }

    /** @return list<array<string,mixed>> */
    public function fields(string $templateId): array
    {
        return $this->db->all(
            'SELECT field_key, label, spoken_label, unit, required, sort_order
             FROM inspection_template_items WHERE template_id = :t ORDER BY sort_order',
            ['t' => $templateId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function items(string $inspectionId): array
    {
        $rows = $this->db->all(
            'SELECT i.id, i.field_key, i.value, i.condition_code, i.raw_transcript, i.recorded_at, t.label
             FROM inspection_items i
             LEFT JOIN inspection_template_items t ON t.field_key = i.field_key
             WHERE i.inspection_id = :id AND i.undone_at IS NULL
             ORDER BY i.recorded_at',
            ['id' => $inspectionId]
        );

        return array_map(static fn (array $r): array => [
            'id' => $r['id'],
            'field_key' => $r['field_key'],
            'label' => $r['label'],
            'value' => $r['value'],
            'condition' => $r['condition_code'],
            'raw_transcript' => $r['raw_transcript'],
            'recorded_at' => $r['recorded_at'],
        ], $rows);
    }

    private function close(string $inspectionId, string $status): void
    {
        $this->db->update(
            'inspections',
            ['status' => $status, 'ended_at' => $this->clock->iso()],
            ['id' => $inspectionId]
        );
        $this->logger->info('inspection.closed', ['inspection_id' => $inspectionId, 'status' => $status]);
    }

    /** @param array<string,mixed> $inspection */
    private function hasTimedOut(array $inspection): bool
    {
        $last = (string) ($inspection['last_item_at'] ?? $inspection['started_at']);
        $cutoff = $this->clock->now()
            ->modify("-{$this->idleSeconds} seconds")
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
        return $last < $cutoff;
    }

    /** @return array<string,mixed> */
    private function requireInspection(string $id): array
    {
        $row = $this->db->first('SELECT * FROM inspections WHERE id = :id', ['id' => $id]);
        if ($row === null) {
            throw new AuthException('Inspection not found.', 404);
        }
        return $row;
    }

    /** @param list<array<string,mixed>> $fields */
    private function labelFor(array $fields, string $fieldKey): string
    {
        foreach ($fields as $field) {
            if ($field['field_key'] === $fieldKey) {
                return (string) $field['label'];
            }
        }
        return $fieldKey;
    }

    /**
     * The missing-items readback is only useful if it stays short (§8).
     *
     * On a nearly-complete inspection that is two or three items and reads
     * exactly like the spec's example. On a barely-started one it would be a
     * fifteen-item list nobody can hold in their head, so past four it names a
     * few and sends him to the sheet.
     *
     * @param list<string> $missing
     */
    private static function speakMissing(array $missing): string
    {
        if ($missing === []) {
            return 'That is everything. Inspection closed.';
        }

        if (count($missing) <= 4) {
            return "I don't have " . self::orList($missing) . '.';
        }

        $named = array_slice($missing, 0, 3);
        $rest = count($missing) - 3;

        return sprintf(
            "I don't have %s, and %d more on the sheet.",
            self::orList($named),
            $rest
        );
    }

    /** @param list<string> $items */
    private static function orList(array $items): string
    {
        if (count($items) === 1) {
            return $items[0];
        }
        $last = array_pop($items);
        return implode(', ', $items) . ' or ' . $last;
    }
}
