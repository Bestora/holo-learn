<?php

namespace App\Annotations;

use App\Japanese\Kana;
use App\Models\DictKanji;
use App\Models\DictWord;
use App\Models\Item;
use App\Models\Reference;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Links a verified reference to its learning items: every kana of the text, and for each
 * ok word with kanji its kanji, their radicals and (not for names) the word itself.
 */
class Decomposer
{
    /**
     * KRADFILE variant forms → the kanji whose meaning they carry; takes precedence over the
     * variant's own entry. Only pairs that occur in dict_kanji.radicals and whose parent has
     * meanings (爫→爪, 牜→牛 never occur).
     */
    private const RADICAL_PARENTS = [
        '亻' => '人', '𠆢' => '人', '氵' => '水', '艹' => '艸', '扌' => '手', '忄' => '心', '辶' => '辵',
        '刂' => '刀', '犭' => '犬', '礻' => '示', '衤' => '衣', '罒' => '网', '灬' => '火', '攵' => '攴',
        '阝' => '阜', '⺌' => '小', '丷' => '八',
    ];

    public function decompose(Reference $ref): void
    {
        DB::transaction(function () use ($ref) {
            $ref->items()->detach();
            if ($ref->status !== 'verified' || $ref->annotation === null) {
                return;
            }
            $rows = $this->rows($ref);
            if ($rows !== []) {
                $ref->items()->newPivotQuery()->insert(array_map(
                    fn (array $row) => [...$row, 'reference_id' => $ref->id],
                    $rows,
                ));
            }
        });
    }

    /** @return list<array{item_id: int, position: int, context_reading: ?string}> */
    private function rows(Reference $ref): array
    {
        $words = $ref->annotation->words ?? [];
        $aligned = Verifier::align($ref->text_ja, array_column($words, 'surface'));
        if ($aligned === null) {
            return []; // the verifier rejects this as surface_mismatch
        }

        $chars = mb_str_split($ref->text_ja);
        $kana = Item::query()->whereIn('kind', ['hiragana', 'katakana'])->whereIn('characters', array_unique($chars))
            ->get()->keyBy('characters');
        $rows = [];
        foreach ($chars as $position => $char) {
            if (isset($kana[$char])) {
                $rows[] = ['item_id' => $kana[$char]->id, 'position' => $position, 'context_reading' => null];
            }
        }

        $kanjiWords = array_values(array_filter(array_keys($words), fn ($i) => ($words[$i]['ok'] ?? false) && Kana::kanjiIn($words[$i]['surface']) !== []));
        if ($kanjiWords === []) {
            return $rows;
        }

        $dictKanji = $this->dictKanji($kanjiWords, $words);
        $vocab = $ref->kind === 'name' ? collect() : $this->vocab($kanjiWords, $words);

        // names: a kanji carries the reading of its whole unit, so a wrong split inside a
        // unit (小/たか + 鳥遊/なし) cannot attach a per-word reading
        $unitReadings = [];
        foreach ($words as $word) {
            if (isset($word['unit'])) {
                $unitReadings[$word['unit']] = ($unitReadings[$word['unit']] ?? '').$word['reading'];
            }
        }

        foreach ($kanjiWords as $i) {
            $reading = $words[$i]['reading'];
            $kanjiReading = isset($words[$i]['unit']) ? $unitReadings[$words[$i]['unit']] : $reading;
            foreach ($aligned[$i] as [$char, $position]) {
                $kanji = $dictKanji[$char] ?? null;
                if ($kanji === null || Kana::kanjiIn($char) === []) {
                    continue; // unknown kanji, or kana/々 inside the word
                }
                $rows[] = ['item_id' => $this->item('kanji', $char, $kanji->readings['plain'], self::meanings($kanji->meanings))->id, 'position' => $position, 'context_reading' => $kanjiReading];
                foreach ($kanji->radicals as $radical) {
                    $source = $dictKanji[self::RADICAL_PARENTS[$radical] ?? ''] ?? $dictKanji[$radical] ?? null;
                    $rows[] = ['item_id' => $this->item('radical', $radical, [], $source === null ? [] : self::meanings($source->meanings))->id, 'position' => $position, 'context_reading' => null];
                }
            }
            if (isset($vocab[$i])) {
                $rows[] = ['item_id' => $vocab[$i]->id, 'position' => $aligned[$i][0][1], 'context_reading' => $reading];
            }
        }

        return $rows;
    }

    /**
     * The kanji of the given words, their radicals and the radicals' parent kanji.
     *
     * @param  list<int>  $indexes
     * @param  list<array<string, mixed>>  $words
     * @return Collection<string, DictKanji>
     */
    private function dictKanji(array $indexes, array $words): Collection
    {
        $characters = array_merge(...array_map(fn ($i) => Kana::kanjiIn($words[$i]['surface']), $indexes));
        $kanji = DictKanji::query()->whereIn('character', array_unique($characters))->get();
        $radicals = $kanji->pluck('radicals')->flatten()->unique()->all();
        $more = [...$radicals, ...array_values(array_intersect_key(self::RADICAL_PARENTS, array_flip($radicals)))];

        return $kanji->concat(DictKanji::query()->whereIn('character', array_diff($more, $characters))->get())
            ->keyBy('character');
    }

    /**
     * Vocab item per word index: the surface if it is in the dictionary with the word's
     * reading, else the lemma (the verifier accepted the word through it).
     *
     * @param  list<int>  $indexes
     * @param  list<array<string, mixed>>  $words
     * @return array<int, Item>
     */
    private function vocab(array $indexes, array $words): array
    {
        $spellings = array_merge(...array_map(fn ($i) => array_filter([$words[$i]['surface'], $words[$i]['lemma'] ?? null]), $indexes));
        $dictionary = DictWord::query()->whereIn('spelling', $spellings)->where('is_name', false)->orderBy('id')->get()->groupBy('spelling');

        $items = [];
        foreach ($indexes as $i) {
            $word = $words[$i];
            foreach ([[$word['surface'], $word['reading']], [$word['lemma'] ?? null, $word['lemma_reading'] ?? null]] as [$spelling, $reading]) {
                $rows = $dictionary[$spelling] ?? collect();
                $match = $rows->first(fn (DictWord $w) => Kana::toHiragana($w->reading) === $reading);
                if ($match !== null) {
                    $readings = array_values(array_unique($rows->map(fn (DictWord $w) => Kana::toHiragana($w->reading))->all()));
                    $meanings = array_slice(array_values(array_unique(array_merge(...$match->glosses))), 0, 5);
                    $items[$i] = $this->item('vocab', $spelling, $readings, $meanings);

                    break;
                }
            }
        }

        return $items;
    }

    /**
     * Meanings with radical-catalogue notes stripped ("legs radical (no. 10)" → "legs");
     * entries left empty or numeric ("radical number 9") are dropped.
     *
     * @param  list<string>  $meanings
     * @return list<string>
     */
    private static function meanings(array $meanings): array
    {
        $stripped = preg_replace([
            '/^\s*kangxi radical\s*\d*/i',
            '/(\s*radical)?\s*\((no\.?|number)\s*\d+\)?\s*$/i', // "(no. 10)", "radical (number 10"
            '/\s*radical\s+(no\.?|number)\s*\d+\s*$/i',
            '/\s*\bradical\s*$/i',
        ], '', $meanings);

        return array_values(array_unique(array_filter(array_map('trim', $stripped), fn (string $m) => preg_match('/^\d*$/', $m) !== 1)));
    }

    /**
     * @param  list<string>  $readings
     * @param  list<string>  $meanings
     */
    private function item(string $kind, string $characters, array $readings, array $meanings): Item
    {
        // existing items keep their readings and meanings
        return Item::query()->firstOrCreate(['kind' => $kind, 'characters' => $characters], ['readings' => $readings, 'meanings' => $meanings]);
    }
}
