<?php

namespace App\Annotations;

use App\Japanese\Kana;
use App\Models\DictWord;
use App\Models\Reference;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Normalizer;

/**
 * Checks an AI annotation against the text, the romaji and the dictionary.
 * Reference-level errors reject it; word-level doubts only clear the word's ok flag.
 * When in doubt a word is not ok: a wrong reading must never be taught.
 */
class Verifier
{
    /** Particles whose reading differs from their kana. */
    private const PARTICLES = ['は' => 'わ', 'へ' => 'え', 'を' => 'お'];

    /** @return list<string> error codes, empty when verified */
    public function verify(Reference $ref): array
    {
        $annotation = $ref->annotation;
        if ($annotation === null) {
            return ['no_annotation'];
        }

        $words = $annotation->words;
        $errors = [];

        $readings = array_column($words, 'reading');
        if (! Kana::isKanaOnly($annotation->reading_kana) || array_filter($readings, fn ($r) => ! Kana::isKanaOnly($r)) !== []) {
            $errors[] = 'reading_not_kana';
        }
        if (self::align($ref->text_ja, array_column($words, 'surface')) === null) {
            $errors[] = 'surface_mismatch';
        }
        if (implode('', $readings) !== $annotation->reading_kana) {
            $errors[] = 'reading_mismatch';
        }

        $kanaOk = [];
        foreach ($words as $i => $word) {
            if (Kana::kanjiIn($word['surface']) === [] && Kana::hasJapanese($word['surface'])) {
                $kanaOk[$i] = $this->kanaReadsAs(self::normalize($word['surface']), $word['reading']);
            }
        }
        if (in_array(false, $kanaOk, true)) {
            $errors[] = 'kana_mismatch';
        }

        $romajiOk = $this->romajiOk($ref, $annotation->reading_kana, $words, $annotation->alt_kind);
        if ($romajiOk === false) {
            $errors[] = 'romaji_mismatch';
        }

        $dictionary = $this->dictionary($words, $ref->kind === 'name');
        $units = NameRomaji::units($words);
        foreach ($words as $i => $word) {
            $words[$i]['unit'] = $ref->kind === 'name' ? $units[$i] : null;
            $words[$i]['ok'] = match (true) {
                isset($kanaOk[$i]) => $kanaOk[$i],
                Kana::kanjiIn($word['surface']) === [] => false, // Latin, digits, symbols
                $romajiOk === true && $ref->kind === 'name' => true,
                default => $this->inDictionary($word, $dictionary),
            };
        }

        DB::transaction(function () use ($ref, $annotation, $words, $errors) {
            $annotation->update(['words' => $words, 'check_errors' => $errors === [] ? null : $errors]);
            $ref->update(['status' => $errors === [] ? 'verified' : 'rejected']);
            if ($errors !== []) {
                $ref->items()->detach();
            }
        });

        return $errors;
    }

    /**
     * Pairs every letter of the surfaces with its character position in $text, ignoring
     * whitespace, punctuation and symbols on both sides (NFKC, Latin lowercased).
     *
     * @param  list<string>  $surfaces
     * @return list<list<array{string, int}>>|null per surface: [normalized char, position]; null if they do not spell $text
     */
    public static function align(string $text, array $surfaces): ?array
    {
        $target = [];
        foreach (mb_str_split($text) as $position => $char) {
            foreach (mb_str_split(self::normalize($char)) as $c) {
                $target[] = [$c, $position];
            }
        }

        $aligned = [];
        $t = 0;
        foreach ($surfaces as $surface) {
            $chars = [];
            foreach (mb_str_split(self::normalize($surface)) as $c) {
                if (($target[$t][0] ?? null) !== $c) {
                    return null;
                }
                $chars[] = $target[$t++];
            }
            $aligned[] = $chars;
        }

        return $t === count($target) ? $aligned : null;
    }

    /** Letters, marks and digits only (keeps kana, kanji, ー, 々), NFKC, Latin lowercased. */
    public static function normalize(string $text): string
    {
        $text = (string) Normalizer::normalize($text, Normalizer::FORM_KC);

        return preg_replace('/[^\p{L}\p{M}\p{N}]+/u', '', mb_strtolower($text)) ?? '';
    }

    private function kanaReadsAs(string $surface, string $reading): bool
    {
        if (! Kana::isKanaOnly($surface)) {
            return false; // Japanese without kanji but not kana-only (e.g. 々 alone): unverifiable
        }
        $surface = Kana::toHiragana($surface);
        if ((self::PARTICLES[$surface] ?? null) === $reading) {
            return true;
        }
        $historic = ['ゑ' => 'え', 'ゐ' => 'い']; // pronounced え/い today (クロヱ = くろえ)

        return Kana::looselyEqual(self::expandLongVowels(strtr($surface, $historic)), self::expandLongVowels(strtr($reading, $historic)));
    }

    /**
     * ー → a vowel that Kana::loose() collapses the same way (けーき → けいき, そー → そう),
     * so "らーめん" and "らあめん" compare equal.
     */
    private static function expandLongVowels(string $kana): string
    {
        return preg_replace_callback('/(.)ー/u', function (array $m) {
            $vowel = substr(Kana::toRomaji($m[1]), -1);

            return $m[1].(['a' => 'あ', 'i' => 'い', 'u' => 'う', 'e' => 'い', 'o' => 'う'][$vowel] ?? 'ー');
        }, $kana) ?? $kana;
    }

    /**
     * @param  list<array{surface: string, reading: string}>  $words
     * @return bool|null null when no romaji check applies
     */
    private function romajiOk(Reference $ref, string $reading, array $words, string $altKind): ?bool
    {
        if ($ref->kind === 'song') {
            if ($altKind !== 'romaji' || $ref->alt_text === null) {
                return null;
            }

            return Kana::looselyEqual(Kana::fromRomaji($ref->alt_text), $reading);
        }

        return NameRomaji::matches($ref->alt_text, $words);
    }

    /**
     * Dictionary rows for all surfaces and lemmas of the annotation, grouped by spelling.
     *
     * @param  list<array<string, mixed>>  $words
     * @return Collection<array-key, Collection<int, DictWord>>
     */
    private function dictionary(array $words, bool $names): Collection
    {
        $spellings = array_unique([...array_column($words, 'surface'), ...array_column($words, 'lemma')]);

        return DictWord::query()->whereIn('spelling', $spellings)
            ->when(! $names, fn ($q) => $q->where('is_name', false))
            ->get()->toBase()->groupBy('spelling');
    }

    /**
     * @param  array<string, mixed>  $word
     * @param  Collection<array-key, Collection<int, DictWord>>  $dictionary
     */
    private function inDictionary(array $word, Collection $dictionary): bool
    {
        $hasReading = fn (string $spelling, string $reading, bool $namesToo) => ($dictionary[$spelling] ?? collect())
            ->contains(fn (DictWord $w) => ($namesToo || ! $w->is_name) && Kana::toHiragana($w->reading) === Kana::toHiragana($reading));

        if (isset($dictionary[$word['surface']])) {
            return $hasReading($word['surface'], $word['reading'], true);
        }
        if (! isset($word['lemma'], $word['lemma_reading'])) {
            return false;
        }

        // Conjugated form: the dictionary form must exist, share the kanji, and its stem
        // must start the reading (歌う → 歌った ok; 来る → 来た fails, which is safe).
        $stem = mb_substr($word['lemma_reading'], 0, -1);

        return $hasReading($word['lemma'], $word['lemma_reading'], false)
            && Kana::kanjiIn($word['surface']) === Kana::kanjiIn($word['lemma'])
            && $stem !== '' && str_starts_with($word['reading'], $stem);
    }
}
