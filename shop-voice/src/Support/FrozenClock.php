<?php
declare(strict_types=1);

namespace ShopVoice\Support;

/** Test double for Clock: lets undo windows and session expiry be exercised without sleeping. */
final class FrozenClock extends Clock
{
    public function __construct(private \DateTimeImmutable $at)
    {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->at;
    }

    public function advance(int $seconds): void
    {
        $this->at = $this->at->modify("+{$seconds} seconds");
    }

    public function set(\DateTimeImmutable $at): void
    {
        $this->at = $at;
    }
}
