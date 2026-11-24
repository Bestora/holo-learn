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
     * lemma (the verifier accepted the word through it). Loanwords: see loanword().
     *
     * @param  list<int>  $indexes  words with kanji
     * @param  list<int>  $loanwords  katakana words
     * @param  list<array<string, mixed>>  $words
     * @param  Collection<string, DictKanji>  $dictKanji  at least the kanji of the words and lemmas
     * @return array<int, array{spelling: string, reading: string, readings: list<string>, meanings: list<string>, level: array{int, bool}}> per word index; reading = the one that matched
     */
    public static function match(array $indexes, array $loanwords, array $words, Collection $dictKanji): array
    {
        $spellings = array_merge(array_map(fn ($i) => $words[$i]['surface'], $loanwords), ...array_map(fn ($i) => array_filter([$words[$i]['surface'], $words[$i]['lemma'] ?? null]), $indexes));
        $dictionary = DictWord::query()->whereIn('spelling', $spellings)->where('is_name', false)->orderBy('id')->get()->groupBy('spelling');

        $vocab = [];
        foreach ($loanwords as $i) {
            $entry = self::loanword($words[$i]['surface'], $dictionary[$words[$i]['surface']] ?? collect());
            if ($entry !== null) {
                $meanings = array_slice(array_values(array_unique(array_merge(...$entry->glosses))), 0, 5);
                $reading = Kana::toHiragana($words[$i]['reading']);
                $vocab[$i] = ['spelling' => $words[$i]['surface'], 'reading' => $reading, 'readings' => [$reading], 'meanings' => $meanings, 'level' => ItemLevel::vocab($entry->common, [])];
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
                        'reading' => $reading,
                        'readings' => self::entryReadings($rows, $match),
                        'meanings' => array_slice(array_values(array_unique(array_merge(...$match->glosses))), 0, 5),
                        'level' => ItemLevel::vocab($rows->contains('common', true), $kanji),
                    ];

                    break;
                }
            }
        }

        return $vocab;
    }

    /**
     * Readings of the matched entry only, never of a homograph with the same spelling (一番:
     * いちばん, not ひとつがい). dict_words has no entry id, so an entry is the rows with the
     * match's glosses; a reading whose senses differ is left out, which errs on the safe side.
     *
     * @param  Collection<int, DictWord>  $rows  all rows of the spelling
     * @return list<string>
     */
    public static function entryReadings(Collection $rows, DictWord $match): array
    {
        return array_values(array_unique($rows->filter(fn (DictWord $w) => $w->glosses === $match->glosses)->map(fn (DictWord $w) => Kana::toHiragana($w->reading))->all()));
    }

    /**
     * The one entry a katakana word unambiguously means, so no wrong meaning is taught
     * (オラ "hola" for おら "I", レイ "lei" for the name Rei): the only common entry, else the
     * only entry at all if the word has 4+ characters (short words are too ambiguous).
     *
     * @param  Collection<int, DictWord>  $rows
     */
    private static function loanword(string $surface, Collection $rows): ?DictWord
    {
        $entries = $rows->sortByDesc('common')->unique(fn (DictWord $w) => $w->reading.json_encode($w->glosses)); // duplicate rows = one entry
        $common = $entries->where('common', true);

        return match (true) {
            $common->count() === 1 => $common->first(),
            $entries->count() === 1 && mb_strlen($surface) >= 4 => $entries->first(),
            default => null,
        };
    }
}
