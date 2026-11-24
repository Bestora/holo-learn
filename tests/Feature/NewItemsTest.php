<?php

namespace Tests\Feature;

use App\Learning\NewItems;
use App\Models\DictKanji;
use App\Models\Item;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\KanaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewItemsTest extends TestCase
{
    use BuildsLearning;
    use RefreshDatabase;

    private function newItems(): NewItems
    {
        return app(NewItems::class);
    }

    /** Takes $count new items in a row as if each intro was confirmed. @return list<string> */
    private function walk(User $user, int $count): array
    {
        $taken = [];
        for ($i = 0; $i < $count && ($item = $this->newItems()->next($user)); $i++) {
            $this->review($user, $item);
            $taken[] = $item->characters;
        }

        return $taken;
    }

    private function items(string $prefix, int $count, int $level, bool $special): void
    {
        foreach (range(1, $count) as $i) {
            $this->withRefs($this->makeItem('kanji', "{$prefix}{$i}", ['よみ'], ['m'], $level, $special), 3);
        }
    }

    public function test_specials_are_at_most_every_fifth_new_item(): void
    {
        config(['hololearn.new_items_per_day' => 100]);
        $this->items('S', 3, 10, true);  // specials sort first
        $this->items('N', 10, 11, false);
        $user = $this->learner();

        $taken = $this->walk($user, 13);

        $this->assertSame(['N1', 'N2', 'N3', 'N4', 'S1', 'N5', 'N6', 'N7', 'N8', 'S2', 'N9', 'N10', 'S3'], $taken);
    }

    public function test_no_specials_when_disabled(): void
    {
        config(['hololearn.new_items_per_day' => 100]);
        $this->items('S', 2, 10, true);
        $this->items('N', 6, 11, false);
        $user = $this->learner();
        $user->forceFill(['specials_enabled' => false])->save();

        $this->assertSame(['N1', 'N2', 'N3', 'N4', 'N5', 'N6'], $this->walk($user, 10));
    }

    public function test_specials_are_allowed_when_nothing_else_is_eligible(): void
    {
        $this->items('S', 2, 10, true);

        $this->assertSame(['S1', 'S2'], $this->walk($this->learner(), 5));
    }

    public function test_daily_limit_uses_local_days(): void
    {
        config(['hololearn.new_items_per_day' => 2]);
        $this->items('N', 5, 10, false);
        $user = $this->learner();

        $this->travelTo(CarbonImmutable::parse('2026-11-23 22:00', 'UTC')); // 23:00 in Berlin
        $this->assertSame(['N1', 'N2'], $this->walk($user, 5));

        $this->travelTo(CarbonImmutable::parse('2026-11-23 22:59', 'UTC'));
        $this->assertNull($this->newItems()->next($user));

        $this->travelTo(CarbonImmutable::parse('2026-11-23 23:01', 'UTC')); // 00:01 on Nov 24 in Berlin
        $this->assertSame(['N3', 'N4'], $this->walk($user, 5));
    }

    public function test_order_and_eligibility_with_per_kind_thresholds(): void
    {
        $this->makeItem('hiragana', 'あ', ['a']);                                  // kana: always eligible
        $this->withRefs($this->makeItem('kanji', 'B', ['b'], ['b'], 10), 2);      // kanji: 2 references
        $this->withRefs($this->makeItem('kanji', 'A', ['a'], ['a'], 10), 3);      // more references first
        $this->withRefs($this->makeItem('kanji', 'C', ['c'], ['c'], 3), 2);       // lower level first
        $few = $this->makeItem('kanji', 'D', ['d'], ['d'], 1);
        $this->withRefs($few, 1);                                                 // 1 verified: too few
        $this->withRefs($few, 5, 'rejected');
        $twice = $this->makeItem('kanji', 'E', ['e'], ['e'], 1);
        $this->makeRef([[$twice, 0], [$twice, 1]]);                               // one reference, linked twice
        $this->withRefs($this->makeItem('vocab', 'V', ['v'], ['v'], 11), 1);      // vocab: 1 reference, after the level's kanji and radicals
        $this->withRefs($this->makeItem('radical', 'R', [], [], 0), 5);          // radical without meanings
        $this->withRefs($this->makeItem('radical', 'Q', [], ['q'], 11), 1);

        $this->assertSame(['あ', 'C', 'A', 'B', 'Q', 'V'], $this->newItems()->eligible()->pluck('characters')->all());
        $this->assertSame(['hiragana' => 1, 'katakana' => 0, 'radical' => 1, 'kanji' => 3, 'vocab' => 1], $this->newItems()->availableCounts());

        $user = $this->learner();
        $this->review($user, Item::query()->where('characters', 'あ')->sole());
        $this->review($user, Item::query()->where('characters', 'C')->sole());
        $this->assertSame('A', $this->newItems()->next($user)?->characters);
        $this->assertSame(['A', 'B', 'V'], $this->newItems()->eligible($user)->pluck('characters')->all()); // Q: no kanji of it reviewed
    }

    public function test_every_kana_is_taught_in_gojuon_order_regardless_of_references(): void
    {
        $this->seed(KanaSeeder::class);
        $hiragana = fn (string $c) => Item::query()->where(['kind' => 'hiragana', 'characters' => $c])->sole();
        $this->withRefs($hiragana('ね'), 3);
        $this->withRefs($hiragana('に'), 4);

        $eligible = $this->newItems()->eligible($this->learner());

        $this->assertSame(142, $eligible->count());
        $this->assertSame(['あ', 'い', 'う', 'え', 'お', 'か'], $eligible->take(6)->pluck('characters')->all());
        $this->assertSame(['ん', 'が'], $eligible->slice(45, 2)->pluck('characters')->values()->all());
        $this->assertSame(array_fill(0, 71, 'hiragana'), $eligible->take(71)->pluck('kind')->all());
        $this->assertSame(['ア', 'イ', 'ウ'], $eligible->slice(71, 3)->pluck('characters')->values()->all());
    }

    public function test_kanji_come_before_vocabulary_within_a_level(): void
    {
        $this->withRefs($this->makeItem('vocab', '少女', ['しょうじょ'], ['girl'], 10), 5);
        $this->withRefs($this->makeItem('kanji', '少', ['しょう'], ['few'], 10), 2);
        $this->withRefs($this->makeItem('kanji', '好', ['こう'], ['like'], 10), 3);
        $this->withRefs($this->makeItem('vocab', '好き', ['すき'], ['liked'], 10), 1);
        $this->withRefs($this->makeItem('vocab', '人', ['ひと'], ['person'], 9), 1);

        $this->assertSame(['人', '好', '少', '少女', '好き'], $this->newItems()->eligible()->pluck('characters')->all());
    }

    private function kanjiWithRadicals(string $kanji, array $radicals): Item
    {
        DictKanji::query()->forceCreate(['character' => $kanji, 'readings' => ['on' => [], 'kun' => [], 'nanori' => [], 'plain' => []], 'meanings' => ['m'], 'radicals' => $radicals]);
        $item = $this->makeItem('kanji', $kanji, ['よ'], ["m{$kanji}"], 10);
        $this->withRefs($item, 2);

        return $item;
    }

    public function test_radicals_follow_their_kanji(): void
    {
        config(['hololearn.new_items_per_day' => 100]);
        foreach (['亻' => 'person', '匕' => 'spoon', '艹' => 'grass'] as $radical => $meaning) {
            $this->withRefs($this->makeItem('radical', $radical, [], [$meaning], 10), 3);
        }
        $this->kanjiWithRadicals('花', ['亻', '匕', '艹']);
        $user = $this->learner();

        $this->assertSame(['花'], $this->newItems()->eligible($user)->pluck('characters')->all()); // no radical before its kanji
        $this->assertSame(['花', '亻', '匕', '艹'], $this->walk($user, 10)); // then they come
    }

    public function test_never_two_radicals_within_three_new_items_while_others_exist(): void
    {
        config(['hololearn.new_items_per_day' => 100]);
        foreach (['亻' => 'person', '匕' => 'spoon', '艹' => 'grass'] as $radical => $meaning) {
            $this->withRefs($this->makeItem('radical', $radical, [], [$meaning], 10), 3); // more references: sorted first
        }
        $this->kanjiWithRadicals('花', ['亻', '匕', '艹']);
        foreach (['海', '空', '星', '月'] as $kanji) {
            $this->kanjiWithRadicals($kanji, []);
        }

        $this->assertSame(['花', '海', '亻', '空', '星', '匕', '月', '艹'], $this->walk($this->learner(), 10));
    }
}
