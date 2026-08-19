<?php
declare(strict_types=1);

namespace ShopVoice\Voice;

use ShopVoice\Auth\Session;

/**
 * One answer to one utterance, in both channels at once.
 *
 * Every read in this system follows the same shape: a short spoken summary and
 * full detail on screen (§5). Making that a single response object rather than
 * two code paths is what keeps the two from drifting.
 */
final class VoiceResponse implements \JsonSerializable
{
    /**
     * @param array<string,mixed> $screen
     * @param array<string,mixed>|null $confirm
     */
    public function __construct(
        public readonly string $speak,
        public readonly array $screen = [],
        public readonly ?array $confirm = null,
        public readonly ?string $ack = null,          // chirp | speak
        public readonly ?Session $session = null,
        public readonly int $undoSeconds = 0,
        public readonly ?string $view = null,         // which screen the tablet should show
        /** @var array<string,mixed>|null */
        public readonly ?array $intent = null,
    ) {
    }

    /** @param array<string,mixed> $screen */
    public function withScreen(array $screen): self
    {
        return new self($this->speak, $screen, $this->confirm, $this->ack, $this->session, $this->undoSeconds, $this->view, $this->intent);
    }

    public function withSession(?Session $session, int $undoSeconds = 0): self
    {
        return new self($this->speak, $this->screen, $this->confirm, $this->ack, $session, $undoSeconds, $this->view, $this->intent);
    }

    /** @param array<string,mixed>|null $intent */
    public function withIntent(?array $intent): self
    {
        return new self($this->speak, $this->screen, $this->confirm, $this->ack, $this->session, $this->undoSeconds, $this->view, $intent);
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'speak' => $this->speak,
            'ack' => $this->ack,
            'view' => $this->view,
            'screen' => (object) $this->screen,
            'confirm' => $this->confirm,
            'undo_seconds' => $this->undoSeconds,
            'session' => $this->session,
            'intent' => $this->intent,
        ];
    }
}
