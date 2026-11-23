<?php

namespace App\Annotations;

use App\Japanese\Kana;
use App\Learning\ItemLevel;
use App\Models\DictKanji;
use App\Models\Item;
use App\Models\Reference;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * @phpstan-type Wanted array{readings: list<string>, meanings: list<string>, level: int, is_special: bool}
 *
 * Links a verified reference to its learning items: every kana of the text, and for each
 * ok word with kanji its kanji, their radicals and (not for names) the word itself; in song
 * titles also katakana words from the dictionary (loanwords like アイドル).
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
        $wanted = []; // "kind|characters" => attributes for a new item, null = link only if it exists
        $links = $this->links($ref, $wanted);
        $items = $this->items($wanted);

        $rows = [];
        foreach ($links as [$key, $position, $context]) {
            if (isset($items[$key])) {
                $rows[] = ['item_id' => $items[$key]->id, 'position' => $position, 'context_reading' => $context];
            }
        }

        return $rows;
    }

    /**
     * Existing items for all wanted keys, missing non-kana ones created; 3 queries at most.
     * Existing items keep their readings, meanings and level, except that a radical's
     * level is lowered when a lower-level kanji uses it.
     *
     * @param  array<string, ?Wanted>  $wanted
     * @return Collection<string, Item>
     */
    private function items(array $wanted): Collection
    {
        if ($wanted === []) {
            return collect();
        }
        $find = function (array $keys) {
            $pairs = array_map(fn (string $key) => explode('|', $key, 2), $keys);

            return Item::query()->whereIn('kind', array_unique(array_column($pairs, 0)))->whereIn('characters', array_unique(array_column($pairs, 1)))
                ->get()->toBase()->keyBy(fn (Item $item) => "{$item->kind}|{$item->characters}")->only($keys);
        };

        $items = $find(array_keys($wanted));
        $write = array_filter(array_diff_key($wanted, $items->all()));
        foreach ($items as $key => $item) {
            if ($item->kind === 'radical' && $wanted[$key] !== null && $wanted[$key]['level'] < $item->level) {
                $write[$key] = [...$wanted[$key], 'readings' => $item->readings, 'meanings' => $item->meanings];
            }
        }
        if ($write !== []) {
            // existing rows only get level and is_special; readings and meanings are never overwritten
            Item::query()->upsert(array_map(function (string $key, array $attributes) {
                [$kind, $characters] = explode('|', $key, 2);

                return ['kind' => $kind, 'characters' => $characters, 'readings' => json_encode($attributes['readings'], JSON_UNESCAPED_UNICODE), 'meanings' => json_encode($attributes['meanings'], JSON_UNESCAPED_UNICODE), 'level' => $attributes['level'], 'is_special' => $attributes['is_special']];
            }, array_keys($write), $write), ['kind', 'characters'], ['level', 'is_special']);
            $items = $items->merge($find(array_keys($write)));
        }

        return $items;
    }

    /**
     * @param  array<string, ?Wanted>  $wanted
     * @return list<array{string, int, ?string}> [item key, position, context reading]
     */
    private function links(Reference $ref, array &$wanted): array
    {
        $words = $ref->annotation->words ?? [];
        $aligned = Verifier::align($ref->text_ja, array_column($words, 'surface'));
        if ($aligned === null) {
            return []; // the verifier rejects this as surface_mismatch
        }

        $rows = [];
        foreach (mb_str_split($ref->text_ja) as $position => $char) {
            if (Kana::isKanaOnly($char)) {
                $key = (Kana::toHiragana($char) === $char ? 'hiragana' : 'katakana').'|'.$char;
                $wanted[$key] = null; // seeded kana only; small kana and ー have no item
                $rows[] = [$key, $position, null];
            }
        }

        $kanjiWords = array_values(array_filter(array_keys($words), fn ($i) => ($words[$i]['ok'] ?? false) && Kana::kanjiIn($words[$i]['surface']) !== []));
        $loanwords = $ref->kind !== 'song' ? [] : array_values(array_filter(array_keys($words), fn ($i) => ($words[$i]['ok'] ?? false) && preg_match('/^[ァ-ヺー]{2,}$/u', $words[$i]['surface']) === 1));
        if ($kanjiWords === [] && $loanwords === []) {
            return $rows;
        }

        $dictKanji = $this->dictKanji($kanjiWords, $words);
        $vocab = $ref->kind === 'name' ? [] : VocabMatcher::match($kanjiWords, $loanwords, $words, $dictKanji);

        // names: a kanji carries the reading of its whole unit, so a wrong split inside a
        // unit (小/たか + 鳥遊/なし) cannot attach a per-word reading
        $unitReadings = [];
        foreach ($words as $word) {
            if (isset($word['unit'])) {
                $unitReadings[$word['unit']] = ($unitReadings[$word['unit']] ?? '').$word['reading'];
            }
        }

        $radicalKanji = []; // radical => levels of the kanji using it
        foreach ($kanjiWords as $i) {
            $kanjiReading = isset($words[$i]['unit']) ? $unitReadings[$words[$i]['unit']] : $words[$i]['reading'];
            foreach ($aligned[$i] as [$char, $position]) {
                $kanji = $dictKanji[$char] ?? null;
                if ($kanji === null || Kana::kanjiIn($char) === []) {
                    continue; // unknown kanji, or kana/々 inside the word
                }
                $level = ItemLevel::kanji($kanji->jlpt, $kanji->grade);
                $rows[] = [self::want($wanted, 'kanji', $char, $kanji->readings['plain'], self::meanings($kanji->meanings), $level), $position, $kanjiReading];
                foreach ($kanji->radicals as $radical) {
                    $radicalKanji[$radical][] = $level;
                    $source = $dictKanji[self::RADICAL_PARENTS[$radical] ?? ''] ?? $dictKanji[$radical] ?? null;
                    $rows[] = [self::want($wanted, 'radical', $radical, [], $source === null ? [] : self::meanings($source->meanings), ItemLevel::radical($radicalKanji[$radical])), $position, null];
                }
            }
        }
        foreach ($vocab as $i => $v) {
            $rows[] = [self::want($wanted, 'vocab', $v['spelling'], $v['readings'], $v['meanings'], $v['level']), $aligned[$i][0][1], $words[$i]['reading']];
        }

        return $rows;
    }

    /**
     * The kanji of the given words and their lemmas, their radicals and the radicals' parent kanji.
     *
     * @param  list<int>  $indexes
     * @param  list<array<string, mixed>>  $words
     * @return Collection<string, DictKanji>
     */
    private function dictKanji(array $indexes, array $words): Collection
    {
        $characters = array_merge(...array_map(fn ($i) => Kana::kanjiIn($words[$i]['surface'].($words[$i]['lemma'] ?? '')), $indexes));
        $kanji = DictKanji::query()->whereIn('character', array_unique($characters))->get();
        $radicals = $kanji->pluck('radicals')->flatten()->unique()->all();
        $more = [...$radicals, ...array_values(array_intersect_key(self::RADICAL_PARENTS, array_flip($radicals)))];

        return $kanji->concat(DictKanji::query()->whereIn('character', array_diff($more, $characters))->get())
            ->keyBy('character');
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
     * The first readings and meanings for a key win; the level is the latest (a radical's
     * level drops with each lower-level kanji).
     *
     * @param  array<string, ?Wanted>  $wanted
     * @param  list<string>  $readings
     * @param  list<string>  $meanings
     * @param  array{int, bool}  $level
     */
    private static function want(array &$wanted, string $kind, string $characters, array $readings, array $meanings, array $level): string
    {
        $key = "{$kind}|{$characters}";
        $wanted[$key] = [...$wanted[$key] ?? ['readings' => $readings, 'meanings' => $meanings], 'level' => $level[0], 'is_special' => $level[1]];

        return $key;
    }
}
