<?php

declare(strict_types=1);

namespace App\Support\Text;

/**
 * Case-folding that survives Turkish.
 *
 * Turkish has four i letters — i, ı, İ, I — and Unicode's default lowercasing does the
 * wrong thing with two of them. `mb_strtolower('İ')` does not produce `i`: it produces
 * `i` followed by U+0307 COMBINING DOT ABOVE, a two-character sequence that looks
 * identical on screen and matches nothing.
 *
 * That is not a theoretical problem here. A seller uploading a spreadsheet with a column
 * headed "İndirimli fiyat" would have it folded to "i̇ndirimli fiyat", the combining dot
 * stripped to a space by the punctuation pass, and the result "i ndirimli fiyat" would
 * match no alias — so the column would be silently unmapped and the discount prices would
 * never arrive. Nobody would see an error; the prices would just be missing.
 *
 * The fix is ordering: fold the Turkish letters to ASCII **while they are still uppercase**,
 * and lowercase what remains. Everything in this class exists to make that ordering
 * impossible to get wrong twice.
 */
final class TurkishText
{
    /**
     * Turkish letters, upper and lower, mapped to their ASCII neighbours.
     *
     * The dotless ı and the dotted İ are the two that matter; the rest are here so a
     * seller who typed their headers on an English keyboard matches one who did not.
     */
    private const FOLD = [
        'İ' => 'i', 'I' => 'i', 'ı' => 'i',
        'Ğ' => 'g', 'ğ' => 'g',
        'Ü' => 'u', 'ü' => 'u',
        'Ş' => 's', 'ş' => 's',
        'Ö' => 'o', 'ö' => 'o',
        'Ç' => 'c', 'ç' => 'c',
    ];

    /**
     * Lowercases text without mangling Turkish.
     *
     * Keeps the Turkish letters as Turkish letters — this is for comparing two pieces of
     * Turkish, not for reducing them to ASCII. Use {@see fold()} for that.
     */
    public function lower(string $value): string
    {
        // The two the default rules get wrong, handled before mb_strtolower sees them.
        $value = strtr($value, ['İ' => 'i', 'I' => 'ı']);

        return mb_strtolower($value, 'UTF-8');
    }

    /**
     * Reduces text to lowercase ASCII letters and digits, for matching.
     *
     * "İndirimli Fiyat", "indirimli fiyat" and "INDIRIMLI FIYAT" all become the same
     * string. Punctuation and runs of whitespace collapse to single separators, because
     * neither carries meaning in a column name or a colour.
     */
    public function fold(string $value, string $separator = ' '): string
    {
        // Folded first, while İ and I are still single uppercase characters. Doing this
        // after lowercasing is the bug this class exists to prevent.
        $value = strtr(trim($value), self::FOLD);

        $value = mb_strtolower($value, 'UTF-8');

        $value = preg_replace('/[^a-z0-9]+/u', $separator, $value) ?? $value;

        return trim(
            preg_replace('/'.preg_quote($separator, '/').'+/', $separator, $value) ?? $value,
            $separator,
        );
    }
}
