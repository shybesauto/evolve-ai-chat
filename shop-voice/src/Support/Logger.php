<?php
declare(strict_types=1);

namespace ShopVoice\Support;

/**
 * Line-delimited JSON log, one file per day, written outside the web root.
 *
 * Rejected intents land here on purpose: §4 says unrecognised actions are
 * logged for prompt tuning, and that log is the only feedback loop the intent
 * prompt has.
 */
final class Logger
{
    public function __construct(
        private string $dir,
        private Clock $clock = new Clock(),
    ) {
    }

    /** @param array<string,mixed> $context */
    public function info(string $event, array $context = []): void
    {
        $this->write('info', $event, $context);
    }

    /** @param array<string,mixed> $context */
    public function warn(string $event, array $context = []): void
    {
        $this->write('warn', $event, $context);
    }

    /** @param array<string,mixed> $context */
    public function error(string $event, array $context = []): void
    {
        $this->write('error', $event, $context);
    }

    /** @param array<string,mixed> $context */
    private function write(string $level, string $event, array $context): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0750, true) && !is_dir($this->dir)) {
            return; // Logging must never take the bay offline.
        }

        $line = json_encode([
            'ts' => $this->clock->iso(),
            'level' => $level,
            'event' => $event,
            'context' => self::redact($context),
        ], JSON_UNESCAPED_SLASHES);

        if ($line === false) {
            return;
        }

        $file = $this->dir . '/shopvoice-' . $this->clock->now()->format('Y-m-d') . '.log';
        @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Strip anything token-shaped before it reaches disk. Log files get emailed
     * around when something breaks.
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private static function redact(array $context): array
    {
        $secret = ['token', 'api_key', 'apikey', 'password', 'pass', 'pin', 'authorization', 'secret'];
        $out = [];
        foreach ($context as $key => $value) {
            if (in_array(strtolower((string) $key), $secret, true)) {
                $out[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $out[$key] = self::redact($value);
            } else {
                $out[$key] = $value;
            }
        }
        return $out;
    }
}
