<?php
declare(strict_types=1);

namespace ShopVoice\Undo;

use ShopVoice\Auth\Session;
use ShopVoice\Db\Db;
use ShopVoice\Support\Clock;
use ShopVoice\Support\Id;
use ShopVoice\Support\Logger;

/**
 * The 30-second undo window (§6).
 *
 * Cheap to build, and it catches the "wait, wrong RO" moment — which is the one
 * a tech notices immediately and cannot otherwise fix without washing his hands
 * and finding a keyboard.
 *
 * Scope is deliberately narrow: low-risk writes only. A high-risk change
 * already took a deliberate screen tap, so it does not need a voice escape
 * hatch, and giving it one would mean a spoken sentence could reverse something
 * a tap was required to make.
 */
final class UndoService
{
    /** @var array<string,callable(string):bool> */
    private array $reversers = [];

    public function __construct(
        private Db $db,
        private Logger $logger,
        private Clock $clock = new Clock(),
        private int $windowSeconds = 30,
    ) {
    }

    /** @param callable(string):bool $reverser */
    public function register(string $targetType, callable $reverser): void
    {
        $this->reversers[$targetType] = $reverser;
    }

    /** Record a reversible write. Called right after the write lands. */
    public function record(Session $session, string $action, string $targetType, string $targetId, string $summary): void
    {
        $now = $this->clock->now();

        $this->db->insert('undo_log', [
            'id' => Id::generate($this->clock),
            'session_id' => $session->id,
            'user_id' => $session->userId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'summary' => mb_substr($summary, 0, 180),
            'created_at' => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'expires_at' => $now->modify("+{$this->windowSeconds} seconds")
                ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        ]);
    }

    /**
     * "Undo that" — reverse the most recent still-reversible write.
     *
     * @return array{undone:bool,spoken:string,action:?string}
     */
    public function undoLast(Session $session): array
    {
        $now = $this->clock->iso();

        $row = $this->db->first(
            'SELECT * FROM undo_log
             WHERE session_id = :session AND consumed_at IS NULL AND expires_at >= :now
             ORDER BY created_at DESC LIMIT 1',
            ['session' => $session->id, 'now' => $now]
        );

        if ($row === null) {
            // Distinguish "nothing to undo" from "too late" — they mean
            // different things to a tech, and the second one needs the tablet.
            $expired = $this->db->first(
                'SELECT * FROM undo_log
                 WHERE session_id = :session AND consumed_at IS NULL
                 ORDER BY created_at DESC LIMIT 1',
                ['session' => $session->id]
            );

            return [
                'undone' => false,
                'spoken' => $expired === null
                    ? "There's nothing to undo."
                    : 'That was more than 30 seconds ago — you will have to fix it on the tablet.',
                'action' => null,
            ];
        }

        $targetType = (string) $row['target_type'];
        $reverser = $this->reversers[$targetType] ?? null;

        if ($reverser === null) {
            $this->logger->error('undo.no_reverser', ['target_type' => $targetType]);
            return ['undone' => false, 'spoken' => "I can't undo that one.", 'action' => (string) $row['action']];
        }

        $reversed = $reverser((string) $row['target_id']);

        $this->db->update('undo_log', ['consumed_at' => $now], ['id' => $row['id']]);

        $this->logger->info('undo.applied', [
            'action' => $row['action'],
            'target_type' => $targetType,
            'target_id' => $row['target_id'],
            'reversed' => $reversed,
        ]);

        return [
            'undone' => $reversed,
            'spoken' => $reversed
                ? sprintf('Undone — %s.', $row['summary'])
                : "That one was already gone.",
            'action' => (string) $row['action'],
        ];
    }

    /** Seconds left on the current undo, for the tablet's countdown. */
    public function remainingSeconds(Session $session): int
    {
        $row = $this->db->first(
            'SELECT expires_at FROM undo_log
             WHERE session_id = :session AND consumed_at IS NULL AND expires_at >= :now
             ORDER BY created_at DESC LIMIT 1',
            ['session' => $session->id, 'now' => $this->clock->iso()]
        );

        if ($row === null) {
            return 0;
        }

        $expires = strtotime((string) $row['expires_at']);
        return $expires === false ? 0 : max(0, $expires - $this->clock->timestamp());
    }
}
