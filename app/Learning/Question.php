<?php

namespace App\Learning;

use App\Japanese\Kana;
use App\Models\Item;

/** What a card asks about an item, and which answers count. Pure. */
class Question
{
    public static function isKana(Item $item): bool
    {
        return in_array($item->kind, ['hiragana', 'katakana'], true);
    }

    /** Katakana-only vocab (アイドル): only its meaning is asked. */
    public static function isLoanword(Item $item): bool
    {
        return $item->kind === 'vocab' && Kana::isKanaOnly($item->characters) && preg_match('/[\x{3041}-\x{3096}]/u', $item->characters) === 0;
    }

    /** Kind, with loanwords as their own group (for distractors). */
    public static function group(Item $item): string
    {
        return self::isLoanword($item) ? 'loanword' : $item->kind;
    }

    /** reading|meaning */
    public static function field(Item $item, int $stage): string
    {
        return match (true) {
            self::isKana($item) => 'reading',
            $item->kind === 'radical', self::isLoanword($item) => 'meaning',
            default => $stage % 2 === 0 ? 'reading' : 'meaning',
        };
    }

    /** choice|typed (before a missing-distractor fallback) */
    public static function mode(Item $item, int $stage): string
    {
        return $stage <= 1 || $item->kind === 'radical' ? 'choice' : 'typed';
    }

    /**
     * Accepted answers. Kana: romaji readings; kanji: the reading in this reference (for names the
     * unit reading); vocab: that or any dictionary reading; meaning: every meaning.
     *
     * @param  list<string>  $contextReadings  pivot context readings of the item in the shown reference
     * @return list<string>
     */
    public static function expected(Item $item, string $field, array $contextReadings): array
    {
        return match (true) {
            $field === 'meaning' => $item->meanings,
            self::isKana($item) => $item->readings,
            $item->kind === 'kanji' => $contextReadings ?: $item->readings,
            default => array_values(array_unique([...$contextReadings, ...$item->readings])),
        };
    }

    /** Short meaning for an option: the shortest of the first three without parenthesised parts. */
    public static function displayMeaning(Item $item): string
    {
        $meanings = array_map(
            fn (string $m) => mb_trim(preg_replace('/\s+/u', ' ', preg_replace('/\([^)]*\)/u', '', $m) ?? '') ?? ''),
            array_slice($item->meanings, 0, 3),
        );
        $meanings = array_filter($meanings, fn (string $m) => $m !== '');
        usort($meanings, fn (string $a, string $b) => mb_strlen($a) <=> mb_strlen($b));

        return $meanings[0] ?? '';
    }

    /**
     * The option that is correct in choice mode.
     *
     * @param  list<string>  $expected
     */
    public static function correctOption(Item $item, string $field, array $expected): string
    {
        return $field === 'meaning' ? self::displayMeaning($item) : $expected[0];
    }
}
