<?php

namespace App\Annotations;

use App\Japanese\Kana;
use Normalizer;

/**
 * Checks a song title's reading against its romaji by aligning the annotation words with the
 * alt's tokens, in order, nothing left over on either side:
 * - a Latin word consumes the next token if it equals its surface, else none (never compared);
 * - a katakana word may consume one token unchecked (the English spelling of a loanword;
 *   its reading is already tied to its surface by kana_mismatch);
 * - every other word must lie in a run of non-Latin words whose joined reading matches
 *   1–6 joined tokens as romaji. Pure, no database.
 */
class SongRomaji
{
    private const MAX_RUN_TOKENS = 6;

    /**
     * @param  list<array{surface: string, reading: string}>  $words
     * @return bool|null null when no romaji check applies
     */
    public static function matches(?string $alt, array $words, string $altKind): ?bool
    {
        if ($alt === null) {
            return null;
        }

        $alt = (string) Normalizer::normalize($alt, Normalizer::FORM_KC);
        $classified = self::classify($words);
        $memo = [];
        if (self::aligns($classified, self::tokens($alt, $classified), 0, 0, $memo)) {
            return true;
        }

        // Not aligned: still romaji (and so a mismatch) when the alt converts completely to kana
        // once Latin words of the title are taken out. The alt_kind claim is not trusted.
        $removed = false;
        foreach ($words as $word) {
            $surface = trim((string) Normalizer::normalize($word['surface'], Normalizer::FORM_KC));
            if ($surface !== '' && preg_match('/^[\p{L}\p{N}]+$/u', $surface) === 1 && ! Kana::hasJapanese($surface)) {
                // one whole-token occurrence, case-insensitive
                $alt = preg_replace('/(?<![\p{L}\p{N}])'.preg_quote($surface, '/').'(?![\p{L}\p{N}])/iu', ' ', $alt, 1, $count) ?? $alt;
                $removed = $removed || $count > 0;
            }
        }
        if ($removed && Verifier::normalize($alt) === '') {
            return null; // only Latin words were romanized: the Japanese part has none
        }

        return $altKind === 'romaji' || Kana::isKanaOnly(Kana::fromRomaji($alt)) ? false : null;
    }

    /**
     * @param  list<array{surface: string, reading: string}>  $words
     * @return list<array{class: 'L'|'K'|'J', key: string, reading: string, spoken: string}>
     */
    private static function classify(array $words): array
    {
        return array_map(function (array $w) {
            $key = Verifier::normalize($w['surface']);

            return [
                'class' => match (true) {
                    ! Kana::hasJapanese($w['surface']) => 'L',
                    preg_match('/^[\x{30A1}-\x{30FA}ー]+$/u', $key) === 1 => 'K',
                    default => 'J',
                },
                'key' => $key,
                'reading' => $w['reading'],
                // romaji writes the particle words は/へ/を as spoken (wa/e/o)
                'spoken' => $w['surface'] === $w['reading'] ? (Verifier::PARTICLES[$w['reading']] ?? $w['reading']) : $w['reading'],
            ];
        }, $words);
    }

    /**
     * The alt split on whitespace and symbols (apostrophes kept inside a token), each token with
     * whether it is glued to the previous one. A Latin word of the title glued to romaji in one
     * token (YUMEbiyori, Ainikite) is split off; a run over both pieces still sees the original.
     *
     * @param  list<array{class: 'L'|'K'|'J', key: string, reading: string, spoken: string}>  $words
     * @return list<array{0: string, 1: bool}>
     */
    private static function tokens(string $alt, array $words): array
    {
        $tokens = [];
        foreach (preg_split("/[^\p{L}\p{M}\p{N}'’]+/u", $alt) ?: [] as $token) {
            if (($token = trim($token, "'’")) !== '') {
                $tokens[] = [$token, false];
            }
        }
        foreach ($words as $word) {
            $key = $word['key'];
            if ($word['class'] !== 'L' || mb_strlen($key) < 2 || in_array($key, array_map(fn (array $t) => Verifier::normalize($t[0]), $tokens), true)) {
                continue;
            }
            foreach ($tokens as $k => [$text, $glued]) {
                $lower = mb_strtolower($text);
                $cut = match (true) {
                    str_starts_with($lower, $key) => mb_strlen($key),
                    str_ends_with($lower, $key) => mb_strlen($text) - mb_strlen($key),
                    default => null,
                };
                if ($cut !== null) {
                    array_splice($tokens, $k, 1, [[mb_substr($text, 0, $cut), $glued], [mb_substr($text, $cut), true]]);
                    break;
                }
            }
        }

        return $tokens;
    }

    /**
     * Whether words from $i and tokens from $t can be partitioned into Latin steps, katakana
     * wildcards and romaji runs. Memoised over ($i, $t): at most words² × tokens × 6 run checks.
     *
     * @param  list<array{class: 'L'|'K'|'J', key: string, reading: string, spoken: string}>  $words
     * @param  list<array{0: string, 1: bool}>  $tokens
     * @param  array<string, bool>  $memo
     */
    private static function aligns(array $words, array $tokens, int $i, int $t, array &$memo): bool
    {
        $n = count($words);
        $m = count($tokens);
        if ($i === $n) {
            return $t === $m;
        }
        $key = $i.','.$t;
        if (isset($memo[$key])) {
            return $memo[$key];
        }

        $word = $words[$i];
        if ($word['class'] === 'L') {
            $equal = $word['key'] !== '' && $t < $m && Verifier::normalize($tokens[$t][0]) === $word['key'];

            return $memo[$key] = self::aligns($words, $tokens, $i + 1, $t + ($equal ? 1 : 0), $memo);
        }

        // exactly one token: more would let it eat the first token of a split kanji word
        if ($word['class'] === 'K' && $t < $m && self::aligns($words, $tokens, $i + 1, $t + 1, $memo)) {
            return $memo[$key] = true;
        }

        $reading = $spoken = '';
        // ponytail: no word cap (ねねねねねねねね is eight words in one token); bounded by the title
        for ($a = 0; $i + $a < $n && $words[$i + $a]['class'] !== 'L'; $a++) {
            $reading .= $words[$i + $a]['reading'];
            $spoken .= $words[$i + $a]['spoken'];
            $joined = '';
            for ($b = 1; $b <= self::MAX_RUN_TOKENS && $t + $b <= $m; $b++) {
                // separate tokens joined with a space so a token-final n stays ん, as in the whole alt
                [$text, $glued] = $tokens[$t + $b - 1];
                $joined .= ($b === 1 || $glued ? '' : ' ').$text;
                $romaji = Kana::fromRomaji($joined);
                foreach (array_unique([$reading, $spoken, self::mergeN($reading), self::mergeN($spoken)]) as $variant) {
                    if (Kana::matchesRomaji($variant, $romaji) && self::aligns($words, $tokens, $i + $a + 1, $t + $b, $memo)) {
                        return $memo[$key] = true;
                    }
                }
            }
        }

        return $memo[$key] = false;
    }

    /**
     * ん before a vowel or y as romaji without n' spells it (れんあい as Renai = れない).
     * ponytail: all-or-nothing per run; a run mixing "n'a" and "na" for ん is rejected.
     */
    private static function mergeN(string $reading): string
    {
        return strtr(Kana::toHiragana($reading), [
            'んあ' => 'な', 'んい' => 'に', 'んう' => 'ぬ', 'んえ' => 'ね', 'んお' => 'の',
            'んや' => 'にゃ', 'んゆ' => 'にゅ', 'んよ' => 'にょ',
        ]);
    }
}
