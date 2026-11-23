<?php

namespace App\Annotations;

use App\Japanese\Kana;

/**
 * Checks the readings of a name's kanji against its romaji. Consecutive words with kanji
 * form a unit; each unit must equal one or more consecutive whole romaji parts, in text
 * order. Parts left over may only stand where kana words are (English-style kana such as
 * Marine for マリン never matches), and every such gap must leave a part. Pure, no database.
 */
class NameRomaji
{
    /**
     * @param  list<array{surface: string, reading: string}>  $words
     * @return bool|null null when the name has no kanji
     */
    public static function matches(?string $alt, array $words): ?bool
    {
        $units = [];   // reading per unit, in text order
        $fillers = []; // gap index (0 = before the first unit) => has non-kanji words
        foreach (self::units($words) as $i => $unit) {
            if ($unit === null) {
                $fillers[count($units)] = true;
            } else {
                $units[$unit] = ($units[$unit] ?? '').$words[$i]['reading'];
            }
        }
        if ($units === []) {
            return null;
        }
        if ($alt === null) {
            return false;
        }

        $parts = array_values(array_filter(
            array_map(fn (string $part) => Kana::fromRomaji(self::completeRomaji($part)), preg_split('/\s+/u', trim($alt)) ?: []),
            fn (string $kana) => $kana !== '',
        ));

        return self::place(array_values($units), $fillers, $parts, 0, 0);
    }

    /**
     * Whether units $j.. can consume whole parts from $p on, honouring the gap rules.
     *
     * @param  list<string>  $units
     * @param  array<int, bool>  $fillers
     * @param  list<string>  $parts
     */
    private static function place(array $units, array $fillers, array $parts, int $j, int $p): bool
    {
        $n = count($parts);
        $gapHasFiller = $fillers[$j] ?? false;
        if ($j === count($units)) {
            return $gapHasFiller ? $p < $n : $p === $n;
        }

        // a gap with kana words leaves at least one part, a gap without leaves none
        for ($a = $gapHasFiller ? $p + 1 : $p; $a < $n; $a++) {
            for ($b = $a; $b < $n; $b++) {
                if (self::equals($units[$j], array_slice($parts, $a, $b - $a + 1)) && self::place($units, $fillers, $parts, $j + 1, $b + 1)) {
                    return true;
                }
            }
            if (! $gapHasFiller) {
                break;
            }
        }

        return false;
    }

    /** @param  list<string>  $parts */
    private static function equals(string $reading, array $parts): bool
    {
        return $reading !== '' && Kana::matchesRomaji($reading, implode('', $parts));
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
}
