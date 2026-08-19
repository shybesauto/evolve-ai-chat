<?php
declare(strict_types=1);

namespace ShopVoice\Auth;

/**
 * A shift session: one user, on one tablet, with at most one RO loaded.
 *
 * The sticky context lives here rather than on the device because it follows
 * the user, not the tablet (§5) — which is what makes "context clears on user
 * switch" fall out for free instead of needing to be remembered.
 */
final class Session implements \JsonSerializable
{
    public function __construct(
        public readonly string $id,
        public readonly string $deviceId,
        public readonly string $userId,
        public readonly string $userName,
        public readonly string $role,
        public readonly string $claimedVia,          // login | voice_switch
        public readonly ?string $highRiskUnlockedAt,
        public readonly ?string $contextRoNumber,
        public readonly ?string $contextOrderId,
        public readonly ?string $contextVehicleId,
        public readonly ?string $contextLabel,
        public readonly ?string $contextLoadedAt,
        public readonly string $startedAt,
        public readonly string $lastActivityAt,
        public readonly ?string $endedAt = null,
        public readonly ?string $endReason = null,
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row, string $userName, string $role): self
    {
        return new self(
            id: (string) $row['id'],
            deviceId: (string) $row['device_id'],
            userId: (string) $row['user_id'],
            userName: $userName,
            role: $role,
            claimedVia: (string) $row['claimed_via'],
            highRiskUnlockedAt: $row['high_risk_unlocked_at'] !== null ? (string) $row['high_risk_unlocked_at'] : null,
            contextRoNumber: $row['context_ro_number'] !== null ? (string) $row['context_ro_number'] : null,
            contextOrderId: $row['context_order_id'] !== null ? (string) $row['context_order_id'] : null,
            contextVehicleId: $row['context_vehicle_id'] !== null ? (string) $row['context_vehicle_id'] : null,
            contextLabel: $row['context_label'] !== null ? (string) $row['context_label'] : null,
            contextLoadedAt: $row['context_loaded_at'] !== null ? (string) $row['context_loaded_at'] : null,
            startedAt: (string) $row['started_at'],
            lastActivityAt: (string) $row['last_activity_at'],
            endedAt: $row['ended_at'] !== null ? (string) $row['ended_at'] : null,
            endReason: $row['end_reason'] !== null ? (string) $row['end_reason'] : null,
        );
    }

    public function hasContext(): bool
    {
        return $this->contextRoNumber !== null;
    }

    public function isActive(): bool
    {
        return $this->endedAt === null;
    }

    /** A voice claim alone is spoofable, so it buys reads and low-risk writes only (§7). */
    public function needsIdentityUnlock(): bool
    {
        return $this->claimedVia === 'voice_switch' && $this->highRiskUnlockedAt === null;
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'user' => ['id' => $this->userId, 'name' => $this->userName, 'role' => $this->role],
            'device_id' => $this->deviceId,
            'claimed_via' => $this->claimedVia,
            'needs_identity_unlock' => $this->needsIdentityUnlock(),
            'context' => $this->hasContext() ? [
                'ro_number' => $this->contextRoNumber,
                'order_id' => $this->contextOrderId,
                'vehicle_id' => $this->contextVehicleId,
                'label' => $this->contextLabel,
                'loaded_at' => $this->contextLoadedAt,
            ] : null,
            'started_at' => $this->startedAt,
            'last_activity_at' => $this->lastActivityAt,
        ];
    }
}
