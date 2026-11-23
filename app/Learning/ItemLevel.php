<?php

namespace App\Learning;

/**
 * Learning level (lower = learned earlier) and special flag of an item, from dictionary
 * metadata. Kana 0–1, katakana loanwords 2 (common) or 3, kanji by JLPT 10–13, by school grade 21–28,
 * everything else 40 and special (an optional bonus, never blocks the basics).
 * Every method returns [level, is_special].
 */
final class ItemLevel
{
    public const RARE = 40;

    /** @return array{int, bool} */
    public static function kana(string $kind): array
    {
        return [$kind === 'hiragana' ? 0 : 1, false];
    }

    /**
     * @param  ?int  $jlpt  KANJIDIC2's old four-level scale: 4 = easiest (≈ N5) … 1 = hardest
     * @param  ?int  $grade  1–6 Kyōiku, 8 remaining Jōyō, 9–10 Jinmeiyō (name kanji)
     * @return array{int, bool}
     */
    public static function kanji(?int $jlpt, ?int $grade): array
    {
        return match (true) {
            $jlpt !== null => [10 + (4 - $jlpt), false],
            $grade !== null && $grade >= 1 && $grade <= 6 => [20 + $grade, false],
            $grade === 8 => [28, false],
            default => [self::RARE, true],
        };
    }

    /**
     * @param  list<array{int, bool}>  $kanji  per kanji of the word (unknown kanji: kanji(null, null));
     *                                         none = a katakana loanword
     * @return array{int, bool}
     */
    public static function vocab(bool $common, array $kanji): array
    {
        if ($kanji === []) {
            return [$common ? 2 : 3, false]; // loanwords: early vocabulary, never special
        }

        return [max(array_column($kanji, 0)), ! $common || in_array(true, array_column($kanji, 1), true)];
    }

    /**
     * A radical comes with the first kanji that uses it.
     *
     * @param  list<array{int, bool}>  $kanji  per kanji item containing the radical
     * @return array{int, bool}
     */
    public static function radical(array $kanji): array
    {
        if ($kanji === []) {
            return [self::RARE, true];
        }

        return [min(array_column($kanji, 0)), ! in_array(false, array_column($kanji, 1), true)];
    }
}
