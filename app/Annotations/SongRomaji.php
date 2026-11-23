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
 * - every other word must lie in a romaji run (one word : 1–6 tokens, or several words : one
 *   token) whose joined reading matches the joined tokens.
 * Kanji-free titles are not checked. If no real alignment exists but the alt has the shape of one
 * (runs over tokens that convert to kana), it is a mismatch whatever alt_kind says. Pure, no database.
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
        // only kanji readings need the romaji: kana_mismatch already ties kana words to their surface
        if ($alt === null || array_filter($words, fn (array $w) => Kana::kanjiIn($w['surface']) !== []) === []) {
            return null;
        }

        $alt = (string) Normalizer::normalize($alt, Normalizer::FORM_KC);
        $classified = self::classify($words);
        $tokens = self::tokens($alt, $classified);
        $memo = [];
        if (self::aligns($classified, $tokens, 0, 0, false, $memo)) {
            return true;
        }
        // The alt has the shape of a romanization of this title (romaji where the words need it)
        // but the readings do not match it: a mismatch, whatever alt_kind claims.
        $memo = [];
        if (self::aligns($classified, $tokens, 0, 0, true, $memo)) {
            return false;
        }

        // Still romaji (and so a mismatch) when the alt converts completely to kana once Latin
        // words of the title are taken out. The alt_kind claim is not trusted.
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
     * @return list<array{class: 'L'|'K'|'M'|'J', key: string, reading: string, spoken: string}>
     */
    private static function classify(array $words): array
    {
        return array_map(function (array $w) {
            $key = Verifier::normalize($w['surface']);

            return [
                'class' => match (true) {
                    ! Kana::hasJapanese($w['surface']) => 'L',
                    preg_match('/^[\x{30A1}-\x{30FA}ー]+$/u', $key) === 1 => 'K',
                    // mixed (ロケット団, のアクエリオン, Ahoy我ら); ゐ/ゑ in a katakana word (チョコレゐト) are styling
                    preg_match('/[\x{30A1}-\x{30FA}a-z0-9]/u', $key) === 1
                        && preg_match('/[\x{3041}-\x{308F}\x{3092}-\x{3096}々'.Kana::KANJI.']/u', $key) === 1 => 'M',
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
     * The third field marks a token that opens a subtitle (after whitespace and - ( ～ 「 …).
     *
     * @param  list<array{class: 'L'|'K'|'M'|'J', key: string, reading: string, spoken: string}>  $words
     * @return list<array{0: string, 1: bool, 2: bool}>
     */
    private static function tokens(string $alt, array $words): array
    {
        $tokens = [];
        $end = 0;
        preg_match_all("/[\p{L}\p{M}\p{N}'’]+/u", $alt, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as [$token, $offset]) {
            $subtitle = preg_match('/\s[-(\[（【「『~～]/u', substr($alt, $end, $offset - $end)) === 1;
            $end = $offset + strlen($token);
            if (($token = trim($token, "'’")) !== '') {
                $tokens[] = [$token, false, $subtitle];
            }
        }
        foreach ($words as $word) {
            $key = $word['key'];
            if ($word['class'] !== 'L' || mb_strlen($key) < 2 || in_array($key, array_map(fn (array $t) => Verifier::normalize($t[0]), $tokens), true)) {
                continue;
            }
            foreach ($tokens as $k => [$text, $glued, $subtitle]) {
                $lower = mb_strtolower($text);
                $cut = match (true) {
                    str_starts_with($lower, $key) => mb_strlen($key),
                    str_ends_with($lower, $key) => mb_strlen($text) - mb_strlen($key),
                    default => null,
                };
                if ($cut !== null) {
                    array_splice($tokens, $k, 1, [[mb_substr($text, 0, $cut), $glued, $subtitle], [mb_substr($text, $cut), true, false]]);
                    break;
                }
            }
        }

        return $tokens;
    }

    /**
     * Whether words from $i and tokens from $t can be partitioned into Latin steps, katakana
     * wildcards and romaji runs. With $shape a run only needs tokens that convert completely to
     * kana, not the readings. Memoised over ($i, $t): at most words × (words + 6) run checks.
     *
     * @param  list<array{class: 'L'|'K'|'M'|'J', key: string, reading: string, spoken: string}>  $words
     * @param  list<array{0: string, 1: bool, 2: bool}>  $tokens
     * @param  array<string, bool>  $memo
     */
    private static function aligns(array $words, array $tokens, int $i, int $t, bool $shape, array &$memo): bool
    {
        $n = count($words);
        $m = count($tokens);
        if ($i === $n) {
            // the shape allows a trailing subtitle (Ohayou -love story-), not just any leftover
            return $t === $m || ($shape && $tokens[$t][2]);
        }
        $key = $i.','.$t;
        if (isset($memo[$key])) {
            return $memo[$key];
        }

        $word = $words[$i];
        if ($word['class'] === 'L') {
            $equal = $word['key'] !== '' && $t < $m && Verifier::normalize($tokens[$t][0]) === $word['key'];

            return $memo[$key] = self::aligns($words, $tokens, $i + 1, $t + ($equal ? 1 : 0), $shape, $memo);
        }

        // Real: a katakana word takes exactly one token; more would let it eat the first token of a
        // split kanji word. Shape: katakana and mixed words take 1–3 tokens unconverted.
        $wildcard = match ($word['class']) {
            'K' => $shape ? 3 : 1,
            'M' => $shape ? 3 : 0,
            default => 0,
        };
        for ($b = 1; $b <= $wildcard && $t + $b <= $m; $b++) {
            if (self::aligns($words, $tokens, $i + 1, $t + $b, $shape, $memo)) {
                return $memo[$key] = true;
            }
        }

        // A run is one word against 1–6 tokens or several words against one token; never many
        // to many, which would let a reading shift between words (ほうしょうかい + ぞくだん).
        // ponytail: no word cap (ねねねねねねねね is eight words in one token); bounded by the title
        $reading = $spoken = '';
        for ($a = 1; $i + $a <= $n && $words[$i + $a - 1]['class'] !== 'L'; $a++) {
            $reading .= $words[$i + $a - 1]['reading'];
            $spoken .= $words[$i + $a - 1]['spoken'];
            $parts = [];
            for ($b = 1; $b <= ($a === 1 ? self::MAX_RUN_TOKENS : 1) && $t + $b <= $m; $b++) {
                // each separate token converted on its own (no syllable across As|You, Kin|Iro
                // keeps ん); split-off pieces of one token rejoined
                [$text, $glued] = $tokens[$t + $b - 1];
                if ($glued && $b > 1) {
                    $parts[count($parts) - 1] .= $text;
                } else {
                    $parts[] = $text;
                }
                if (self::runMatches(implode('', array_map(Kana::fromRomaji(...), $parts)), $reading, $spoken, $shape)
                    && self::aligns($words, $tokens, $i + $a, $t + $b, $shape, $memo)) {
                    return $memo[$key] = true;
                }
            }
        }

        return $memo[$key] = false;
    }

    private static function runMatches(string $romaji, string $reading, string $spoken, bool $shape): bool
    {
        if ($shape) {
            // plausible length too: `A` (1 kana) is no romanization of ざんこくなてんしの (9)
            [$r, $t] = [mb_strlen(Kana::loose($reading)), mb_strlen(Kana::loose($romaji))];

            return Kana::isKanaOnly($romaji) && $t >= intdiv($r + 1, 2) && $t <= 2 * $r;
        }
        foreach (array_unique([$reading, $spoken, self::mergeN($reading), self::mergeN($spoken)]) as $variant) {
            if (Kana::matchesRomaji($variant, $romaji)) {
                return true;
            }
        }

        return false;
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
