<?php
declare(strict_types=1);

namespace ShopVoice\Support;

/** Read-only settings bag, built once at boot from .env plus defaults. */
final class Config
{
    /** @param array<string,mixed> $values */
    public function __construct(private array $values)
    {
    }

    public static function fromEnv(): self
    {
        return new self([
            'app.env' => Env::get('APP_ENV', 'production'),
            'app.debug' => Env::bool('APP_DEBUG', false),
            'app.timezone' => Env::get('APP_TIMEZONE', 'America/Detroit'),

            'db.dsn' => Env::get('DB_DSN', 'sqlite::memory:'),
            'db.user' => Env::get('DB_USER'),
            'db.pass' => Env::get('DB_PASS'),

            'shopmonkey.mode' => Env::get('SHOPMONKEY_MODE', 'fixture'),
            'shopmonkey.token' => Env::get('SHOPMONKEY_TOKEN'),
            'shopmonkey.base_url' => Env::get('SHOPMONKEY_BASE_URL', 'https://api.shopmonkey.cloud/v3/'),

            'intent.provider' => Env::get('INTENT_PROVIDER', 'mock'),
            'intent.confidence_threshold' => Env::float('INTENT_CONFIDENCE_THRESHOLD', 0.70),

            'groq.key' => Env::get('GROQ_API_KEY'),
            'groq.model' => Env::get('GROQ_MODEL', 'llama-3.3-70b-versatile'),
            'openai.key' => Env::get('OPENAI_API_KEY'),
            'openai.model' => Env::get('OPENAI_MODEL', 'gpt-4o-mini'),
            'anthropic.key' => Env::get('ANTHROPIC_API_KEY'),
            'anthropic.model' => Env::get('ANTHROPIC_MODEL', 'claude-haiku-4-5-20251001'),

            'notes.store' => Env::get('NOTE_STORE', 'local'),

            'session.idle_minutes' => Env::int('SESSION_IDLE_MINUTES', 720),
            'session.nightly_logout_at' => Env::get('NIGHTLY_LOGOUT_AT', '03:00'),
            'undo.window_seconds' => Env::int('UNDO_WINDOW_SECONDS', 30),
            'inspection.idle_seconds' => Env::int('INSPECTION_IDLE_SECONDS', 300),

            'alldata.user' => Env::get('ALLDATA_USER'),
            'alldata.pass' => Env::get('ALLDATA_PASS'),
        ]);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->values[$key] ?? null;
        return is_string($value) && $value !== '' ? $value : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->values[$key] ?? null;
        return is_numeric($value) ? (int) $value : $default;
    }

    public function float(string $key, float $default = 0.0): float
    {
        $value = $this->values[$key] ?? null;
        return is_numeric($value) ? (float) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->values[$key] ?? null;
        return is_bool($value) ? $value : $default;
    }

    public function with(string $key, mixed $value): self
    {
        $clone = $this->values;
        $clone[$key] = $value;
        return new self($clone);
    }
}
