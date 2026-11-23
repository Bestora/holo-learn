<?php

namespace App\Annotations;

use App\Japanese\Kana;
use App\Learning\ItemLevel;
use App\Models\DictKanji;
use App\Models\DictWord;
use Illuminate\Support\Collection;

/** Finds the dictionary entry behind an annotated word, for its vocab item. One query. */
class VocabMatcher
{
    /**
     * Kanji words: the surface if it is in the dictionary with the word's reading, else the
     * lemma (the verifier accepted the word through it). Loanwords: the surface if it is in
     * the dictionary, with the meanings of all its rows.
     *
     * @param  list<int>  $indexes  words with kanji
     * @param  list<int>  $loanwords  katakana words
     * @param  list<array<string, mixed>>  $words
     * @param  Collection<string, DictKanji>  $dictKanji  at least the kanji of the words and lemmas
     * @return array<int, array{spelling: string, readings: list<string>, meanings: list<string>, level: array{int, bool}}> per word index
     */
    public static function match(array $indexes, array $loanwords, array $words, Collection $dictKanji): array
    {
        $spellings = array_merge(array_map(fn ($i) => $words[$i]['surface'], $loanwords), ...array_map(fn ($i) => array_filter([$words[$i]['surface'], $words[$i]['lemma'] ?? null]), $indexes));
        $dictionary = DictWord::query()->whereIn('spelling', $spellings)->where('is_name', false)->orderBy('id')->get()->groupBy('spelling');

        $vocab = [];
        foreach ($loanwords as $i) {
            $rows = $dictionary[$words[$i]['surface']] ?? null;
            if ($rows !== null) {
                $meanings = array_slice(array_values(array_unique(array_merge(...$rows->flatMap(fn (DictWord $w) => $w->glosses)->all()))), 0, 5);
                $vocab[$i] = ['spelling' => $words[$i]['surface'], 'readings' => [Kana::toHiragana($words[$i]['reading'])], 'meanings' => $meanings, 'level' => ItemLevel::vocab($rows->contains('common', true), [])];
            }
        }
        foreach ($indexes as $i) {
            $word = $words[$i];
            foreach ([[$word['surface'], $word['reading']], [$word['lemma'] ?? null, $word['lemma_reading'] ?? null]] as [$spelling, $reading]) {
                $rows = $dictionary[$spelling] ?? collect();
                $match = $rows->first(fn (DictWord $w) => Kana::toHiragana($w->reading) === $reading);
                if ($match !== null) {
                    $kanji = array_map(fn (string $char) => ItemLevel::kanji($dictKanji[$char]->jlpt ?? null, $dictKanji[$char]->grade ?? null), Kana::kanjiIn($spelling));
                    $vocab[$i] = [
                        'spelling' => $spelling,
                        'readings' => array_values(array_unique($rows->map(fn (DictWord $w) => Kana::toHiragana($w->reading))->all())),
                        'meanings' => array_slice(array_values(array_unique(array_merge(...$match->glosses))), 0, 5),
                        'level' => ItemLevel::vocab($rows->contains('common', true), $kanji),
                    ];

                    break;
                }
            }
        }

        return $vocab;
    }
}
