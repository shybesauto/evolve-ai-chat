<?php
declare(strict_types=1);

namespace ShopVoice\Resolver;

/**
 * Scope defaulting (§5).
 *
 * Default to open orders: it is ~90% of bay queries and collapses most
 * ambiguity, because only so many cars are in the shop at once. Widen when the
 * tech is clearly talking about the past.
 *
 * The model is expected to infer scope and pass it in the intent; this keyword
 * pass is the fallback for when it does not, and the reason no keyword is
 * *required* of the tech.
 */
final class Scope
{
    private const HISTORY_LANGUAGE = [
        'history', 'past', 'previous', 'previously', 'last time', 'before',
        'ever done', 'we done', 'we ever', 'used to', 'back in', 'old ro',
        'ago', 'when did we', 'have we',
    ];

    public static function wantsHistory(string $transcript): bool
    {
        $haystack = strtolower($transcript);
        foreach (self::HISTORY_LANGUAGE as $phrase) {
            if (str_contains($haystack, $phrase)) {
                return true;
            }
        }
        return false;
    }
}
