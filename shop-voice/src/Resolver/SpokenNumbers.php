<?php
declare(strict_types=1);

namespace ShopVoice\Resolver;

/**
 * Turns spoken number words into digits.
 *
 * Whisper usually emits digits, but not reliably for the way a tech actually
 * says an RO number: "forty four seventy one" and "four four seven one" are
 * both common, and both currently arrive as words. Getting this wrong is the
 * expensive error class (§6: 4471 heard as 4470), which is also why the RO is
 * read back on load.
 *
 * Two rules do the real work:
 *   - a tens word followed by a unit is one number ("forty four" -> 44), so a
 *     run of number words concatenates as 44 + 71 rather than 40 4 70 1;
 *   - a lone small number word is left as a word, so "just one second" does not
 *     become "just 1 second" in a transcript we store verbatim.
 */
final class SpokenNumbers
{
    private const UNITS = [
        'zero' => 0, 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4,
        'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9,
        'ten' => 10, 'eleven' => 11, 'twelve' => 12, 'thirteen' => 13,
        'fourteen' => 14, 'fifteen' => 15, 'sixteen' => 16, 'seventeen' => 17,
        'eighteen' => 18, 'nineteen' => 19,
    ];

    private const TENS = [
        'twenty' => 20, 'thirty' => 30, 'forty' => 40, 'fourty' => 40, 'fifty' => 50,
        'sixty' => 60, 'seventy' => 70, 'eighty' => 80, 'ninety' => 90,
    ];

    /** Spoken as a digit only inside a run: "four oh seven" but not a stray "oh". */
    private const WEAK = ['oh' => 0, 'o' => 0];

    /**
     * @param bool $preserveLoneSmallNumbers keep a single small number word as a
     *        word ("just one second"). Turn this off where the surrounding text
     *        guarantees a number is meant — a measurement, for instance, where
     *        "four millimetres" is unambiguous.
     */
    public static function digitize(string $text, bool $preserveLoneSmallNumbers = true): string
    {
        $tokens = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];

        /** @var list<string> $runWords  original words in the current number run */
        $runWords = [];
        $runDigits = '';

        $flush = static function () use (&$runWords, &$runDigits, &$out, $preserveLoneSmallNumbers): void {
            if ($runDigits === '') {
                return;
            }
            // A single small number word on its own is more likely English than
            // an identifier. Put it back.
            if ($preserveLoneSmallNumbers && count($runWords) === 1 && strlen($runDigits) === 1) {
                $out[] = $runWords[0];
            } else {
                $out[] = $runDigits;
            }
            $runWords = [];
            $runDigits = '';
        };

        for ($i = 0; $i < count($tokens); $i++) {
            $raw = $tokens[$i];
            $word = strtolower(trim($raw, " \t\n\r\0\x0B.,!?;:"));

            // "forty four" -> 44
            if (isset(self::TENS[$word])) {
                $value = self::TENS[$word];
                $nextWord = isset($tokens[$i + 1])
                    ? strtolower(trim($tokens[$i + 1], " \t\n\r\0\x0B.,!?;:"))
                    : '';
                if ($nextWord !== '' && isset(self::UNITS[$nextWord]) && self::UNITS[$nextWord] < 10 && self::UNITS[$nextWord] > 0) {
                    $value += self::UNITS[$nextWord];
                    $runWords[] = $raw;
                    $runWords[] = $tokens[$i + 1];
                    $i++;
                } else {
                    $runWords[] = $raw;
                }
                $runDigits .= (string) $value;
                continue;
            }

            if (isset(self::UNITS[$word])) {
                $runWords[] = $raw;
                $runDigits .= (string) self::UNITS[$word];
                continue;
            }

            if (isset(self::WEAK[$word]) && $runDigits !== '') {
                $runWords[] = $raw;
                $runDigits .= (string) self::WEAK[$word];
                continue;
            }

            // Digits already in the transcript continue a run: "RO 44 71".
            if (preg_match('/^\d+$/', $word) && $runDigits !== '') {
                $runWords[] = $raw;
                $runDigits .= $word;
                continue;
            }

            $flush();
            $out[] = $raw;
        }
        $flush();

        return implode(' ', $out);
    }
}
