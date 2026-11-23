<?php

namespace App\Learning;

use App\Japanese\Kana;

/** Grades typed answers. Pure. */
class AnswerChecker
{
    /**
     * @param  list<string>  $expected  kana readings, or Hepburn romaji for kana items
     */
    public static function reading(string $input, array $expected): bool
    {
        $input = mb_trim($input);
        if ($input === '') {
            return false;
        }

        $compact = mb_strtolower(preg_replace('/\s+/u', '', $input) ?? '');
        $kana = self::asKana($input);

        foreach ($expected as $reading) {
            if ($compact === mb_strtolower($reading) || Kana::looselyEqual($kana, self::asKana($reading))) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $meanings */
    public static function meaning(string $input, array $meanings): bool
    {
        $input = self::normalizeMeaning($input);
        if ($input === '') {
            return false;
        }

        foreach ($meanings as $meaning) {
            $meaning = self::normalizeMeaning($meaning);
            if ($meaning === '') {
                continue;
            }
            if ($input === $meaning || levenshtein($input, $meaning) <= (mb_strlen($meaning) >= 8 ? 2 : 1)) {
                return true;
            }
        }

        return false;
    }

    /** Lowercase, parenthesised parts and a leading to/a/an/the removed, spaces collapsed. */
    public static function normalizeMeaning(string $meaning): string
    {
        $s = mb_strtolower(preg_replace('/\([^)]*\)/u', ' ', $meaning) ?? '');
        $s = mb_trim(preg_replace('/\s+/u', ' ', $s) ?? '');

        return preg_replace('/^(to|a|an|the)(\s+|$)/u', '', $s) ?? '';
    }

    /** Comparison key for a reading option: kana (romaji converted), long vowels collapsed. */
    public static function readingKey(string $reading): string
    {
        return Kana::loose(self::asKana($reading));
    }

    private static function asKana(string $text): string
    {
        return preg_match('/[a-z]/i', $text) === 1 ? Kana::fromRomaji($text) : $text;
    }
}
