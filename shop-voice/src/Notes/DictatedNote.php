<?php
declare(strict_types=1);

namespace ShopVoice\Notes;

/**
 * One dictation, in both its versions (§6).
 *
 * The internal note is the tech's raw transcript, verbatim and never rewritten.
 * The customer-facing version is a model draft that never posts on its own.
 */
final class DictatedNote implements \JsonSerializable
{
    public function __construct(
        public readonly string $id,
        public readonly string $clientUuid,
        public readonly string $roNumber,
        public readonly ?string $orderId,
        public readonly string $userId,
        public readonly string $rawTranscript,
        public readonly ?string $customerDraft,
        public readonly ?string $customerDraftEdited,
        public readonly ?string $accuracyApprovedAt,
        public readonly ?string $customerApprovedAt,
        public readonly string $syncState,
        public readonly string $dictatedAt,
        public readonly ?string $undoneAt = null,
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (string) $row['id'],
            clientUuid: (string) $row['client_uuid'],
            roNumber: (string) $row['ro_number'],
            orderId: $row['order_id'] !== null ? (string) $row['order_id'] : null,
            userId: (string) $row['user_id'],
            rawTranscript: (string) $row['raw_transcript'],
            customerDraft: $row['customer_draft'] !== null ? (string) $row['customer_draft'] : null,
            customerDraftEdited: $row['customer_draft_edited'] !== null ? (string) $row['customer_draft_edited'] : null,
            accuracyApprovedAt: $row['accuracy_approved_at'] !== null ? (string) $row['accuracy_approved_at'] : null,
            customerApprovedAt: $row['customer_approved_at'] !== null ? (string) $row['customer_approved_at'] : null,
            syncState: (string) $row['sync_state'],
            dictatedAt: (string) $row['dictated_at'],
            undoneAt: $row['undone_at'] !== null ? (string) $row['undone_at'] : null,
        );
    }

    /** What the customer would actually see: the advisor's edit wins over the draft. */
    public function customerText(): ?string
    {
        return $this->customerDraftEdited ?? $this->customerDraft;
    }

    public function stage(): string
    {
        return match (true) {
            $this->undoneAt !== null => 'undone',
            $this->customerApprovedAt !== null => 'customer_approved',
            $this->accuracyApprovedAt !== null => 'awaiting_advisor',
            default => 'awaiting_tech',
        };
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'client_uuid' => $this->clientUuid,
            'ro_number' => $this->roNumber,
            'order_id' => $this->orderId,
            'user_id' => $this->userId,
            'internal_note' => $this->rawTranscript,
            'customer_draft' => $this->customerText(),
            'customer_draft_is_edited' => $this->customerDraftEdited !== null,
            'stage' => $this->stage(),
            'accuracy_approved_at' => $this->accuracyApprovedAt,
            'customer_approved_at' => $this->customerApprovedAt,
            'sync_state' => $this->syncState,
            'dictated_at' => $this->dictatedAt,
        ];
    }
}
