<?php
declare(strict_types=1);

namespace ShopVoice\Auth;

use ShopVoice\Intent\RiskTier;

/**
 * The answer to "may this session do this, and how must it be confirmed?"
 *
 * Two separate gates, which are easy to conflate and must not be:
 *
 *   confirmation  — §6. How a write is confirmed at all. Low-risk takes a
 *                   spoken readback and a verbal yes; high-risk takes a screen
 *                   tap, every time, for everyone. No command bypasses it and
 *                   no user can opt out.
 *
 *   identity      — §7. Whether we believe who is speaking. Only applies after
 *                   a voice switch, only to the first high-risk write, and is
 *                   cleared by a tap or PIN on the tablet.
 *
 * A high-risk write immediately after a voice switch trips both.
 */
final class Authorization implements \JsonSerializable
{
    public function __construct(
        public readonly bool $allowed,
        public readonly RiskTier $tier,
        public readonly bool $requiresScreenTap,
        public readonly bool $requiresIdentityUnlock,
        public readonly ?string $reason = null,
    ) {
    }

    public static function decide(Session $session, RiskTier $tier): self
    {
        if (!$session->isActive()) {
            return new self(false, $tier, false, false, 'session_ended');
        }

        if ($tier->requiresScreenTap()) {
            return new self(
                true,
                $tier,
                true,
                $session->needsIdentityUnlock(),
                'high_risk'
            );
        }

        // Reads and low-risk writes are available immediately, including
        // straight after a voice switch — that is what makes the switch usable.
        return new self(true, $tier, false, false, null);
    }

    /** Can this be completed by voice alone right now? */
    public function voiceOnly(): bool
    {
        return $this->allowed && !$this->requiresScreenTap && !$this->requiresIdentityUnlock;
    }

    public function spokenPrompt(): ?string
    {
        if (!$this->allowed) {
            return 'That session has ended — log back in on the tablet.';
        }
        if ($this->requiresIdentityUnlock) {
            return 'I need a PIN on the tablet before I change anything on the order.';
        }
        if ($this->requiresScreenTap) {
            return 'Tap to confirm on the tablet.';
        }
        return null;
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'allowed' => $this->allowed,
            'tier' => $this->tier->value,
            'requires_screen_tap' => $this->requiresScreenTap,
            'requires_identity_unlock' => $this->requiresIdentityUnlock,
            'reason' => $this->reason,
        ];
    }
}
