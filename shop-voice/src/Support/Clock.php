<?php
declare(strict_types=1);

namespace ShopVoice\Support;

/**
 * Injectable clock. Undo windows, session expiry and inspection idle timeouts
 * are all time-dependent rules; tests need to move time without sleeping.
 */
class Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now');
    }

    public function timestamp(): int
    {
        return $this->now()->getTimestamp();
    }

    /** ISO-8601 in UTC — the only format that crosses the wire. */
    public function iso(): string
    {
        return $this->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
