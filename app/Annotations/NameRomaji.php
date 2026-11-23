<?php

namespace App\Annotations;

use App\Japanese\Kana;

/**
 * Checks the readings of a name's kanji against its romaji. Consecutive words with kanji
 * form a unit; every unit must occur in the romaji in text order without overlap, with the
 * romaji parts in given or reversed order. Pure, no database.
 */
class NameRomaji
{
    /**
     * @param  list<array{surface: string, reading: string}>  $words
     * @return bool|null null when the name has no kanji (English-style kana such as Marine for マリン would never match)
     */
    public static function matches(?string $alt, array $words): ?bool
    {
        $units = [];
        foreach (self::units($words) as $i => $unit) {
            if ($unit !== null) {
                $units[$unit][] = $words[$i]['reading'];
            }
        }
        if ($units === []) {
            return null;
        }
        if ($alt === null) {
            return false;
        }

        $parts = array_map(fn (string $part) => Kana::fromRomaji(self::completeRomaji($part)), preg_split('/\s+/u', trim($alt)) ?: []);
        // loose() collapses えい across word borders (にのまえ|いなにす), so both sides are
        // compared per word/part and as a whole
        $candidates = array_map(fn (array $readings) => array_unique([implode('', array_map(Kana::loose(...), $readings)), Kana::loose(implode('', $readings))]), $units);

        // ponytail: substring match within a romaji part can still accept a wrong reading that
        // happens to be contained in it; only in-order, non-overlapping placement is enforced.
        foreach ([$parts, array_reverse($parts)] as $order) {
            foreach ([implode('', array_map(Kana::loose(...), $order)), Kana::loose(implode('', $order))] as $romaji) {
                if (self::inOrder($candidates, $romaji)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Unit index per word: maximal runs of consecutive words with kanji, null for other words.
     *
     * @param  list<array{surface: string, reading: string}>  $words
     * @return list<?int>
     */
    public static function units(array $words): array
    {
        $units = [];
        $next = 0;
        $previous = null;
        foreach ($words as $word) {
            $previous = Kana::kanjiIn($word['surface']) === [] ? null : ($previous ?? $next++);
            $units[] = $previous;
        }

        return $units;
    }

    /**
     * Romaji part prepared for matching only: apostrophes not after n dropped, and a final
     * consonant fromRomaji() cannot convert completed to a syllable (Ina'nis → inanisu).
     */
    private static function completeRomaji(string $part): string
    {
        $part = preg_replace("/(?<![nN])['’]/u", '', $part) ?? $part;
        $syllables = ['s' => 'su', 'k' => 'ku', 'l' => 'ru', 'r' => 'ru', 'm' => 'mu', 'z' => 'zu', 'b' => 'bu', 'p' => 'pu', 'g' => 'gu', 'f' => 'fu', 't' => 'to', 'd' => 'do'];

        return preg_replace_callback('/([sklrmzbpgftd])[^\p{L}]*$/iu', fn (array $m) => $syllables[strtolower($m[1])], $part) ?? $part;
    }

    /**
     * Whether every unit occurs in $romaji, in order and without overlap; each unit may
     * match in any of its candidate spellings (earliest end wins, which is optimal).
     *
     * @param  array<int, array<string>>  $units
     */
    private static function inOrder(array $units, string $romaji): bool
    {
        $offset = 0;
        foreach ($units as $candidates) {
            $end = null;
            foreach ($candidates as $candidate) {
                $at = $candidate === '' ? false : strpos($romaji, $candidate, $offset);
                if ($at !== false && ($end === null || $at + strlen($candidate) < $end)) {
                    $end = $at + strlen($candidate);
                }
            }
            if ($end === null) {
                return false;
            }
            $offset = $end;
        }

        return true;
    }
}
