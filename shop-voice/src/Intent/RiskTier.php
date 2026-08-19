<?php
declare(strict_types=1);

namespace ShopVoice\Intent;

/**
 * Confirmation is a system rule, not a user choice (§6). The tier decides how
 * an action may be confirmed, and no command bypasses it.
 */
enum RiskTier: string
{
    case Read = 'read';
    case LowRiskWrite = 'low_risk';
    case HighRisk = 'high_risk';
    case Mode = 'mode';
    case Session = 'session';

    /** Spoken readback + a verbal "yes" is enough. */
    public function allowsVoiceConfirmation(): bool
    {
        return $this !== self::HighRisk;
    }

    /** A spoken "yes" is never sufficient here — it takes a tap on the tablet. */
    public function requiresScreenTap(): bool
    {
        return $this === self::HighRisk;
    }

    /** Only low-risk writes are reversible by "undo that" (§6). */
    public function isUndoable(): bool
    {
        return $this === self::LowRiskWrite;
    }

    public function isWrite(): bool
    {
        return $this === self::LowRiskWrite || $this === self::HighRisk;
    }
}
