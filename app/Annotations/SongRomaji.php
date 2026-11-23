<?php

namespace App\Annotations;

use App\Japanese\Kana;
use Normalizer;

/**
 * Checks a song title's reading against its romaji. Latin words of the title that the alt
 * repeats (Ahoy, S) are taken out of both sides first; they are never taught (ok = false),
 * so only the Japanese words are compared. Pure, no database.
 */
class SongRomaji
{
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
        $kept = [];
        foreach ($words as $word) {
            $surface = trim((string) Normalizer::normalize($word['surface'], Normalizer::FORM_KC));
            $latin = $surface !== '' && preg_match('/^[\p{L}\p{N}]+$/u', $surface) === 1 && ! Kana::hasJapanese($surface);
            $count = 0;
            if ($latin) {
                // one whole-token occurrence, case-insensitive
                $alt = preg_replace('/(?<![\p{L}\p{N}])'.preg_quote($surface, '/').'(?![\p{L}\p{N}])/iu', ' ', $alt, 1, $count) ?? $alt;
            }
            if ($count === 0) {
                $kept[] = $word;
            }
        }
        if (count($kept) < count($words) && Verifier::normalize($alt) === '') {
            return null; // only Latin words were romanized: the Japanese part has none
        }

        // The alt_kind claim is not trusted: an alt that converts completely to kana is romaji.
        $romaji = Kana::fromRomaji($alt);
        if ($altKind !== 'romaji' && ! Kana::isKanaOnly($romaji)) {
            return null;
        }
        $reading = implode('', array_column($kept, 'reading'));
        // romaji writes the particle words は/へ/を as spoken (wa/e/o)
        $spoken = implode('', array_map(fn (array $w) => $w['surface'] === $w['reading'] ? (Verifier::PARTICLES[$w['reading']] ?? $w['reading']) : $w['reading'], $kept));

        return Kana::matchesRomaji($reading, $romaji) || Kana::matchesRomaji($spoken, $romaji);
    }
}
