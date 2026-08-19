<?php
declare(strict_types=1);

namespace ShopVoice\Support;

/**
 * Sortable, application-generated identifiers.
 *
 * Time-prefixed so rows sort by creation without a secondary index, and
 * generatable on the tablet — a note dictated offline needs an id before it has
 * ever seen the server (§10).
 */
final class Id
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ'; // Crockford base32

    public static function generate(?Clock $clock = null): string
    {
        $clock ??= new Clock();
        $ms = $clock->timestamp() * 1000;

        $time = '';
        for ($i = 0; $i < 10; $i++) {
            $time = self::ALPHABET[$ms % 32] . $time;
            $ms = intdiv($ms, 32);
        }

        $random = '';
        for ($i = 0; $i < 16; $i++) {
            $random .= self::ALPHABET[random_int(0, 31)];
        }

        return $time . $random;
    }

    /** Short, unambiguous code a person reads off a screen and types on a tablet. */
    public static function enrollmentCode(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTVWXYZ23456789'; // no I/L/O/0/1
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return substr($code, 0, 4) . '-' . substr($code, 4);
    }

    public static function token(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
