<?php

namespace Tests\Feature;

use App\Learning\NewItems;
use App\Models\Item;
use App\Models\User;
use Carbon\CarbonImmutable;
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

    public function test_order_and_eligibility(): void
    {
        $this->withRefs($this->makeItem('kanji', 'B', ['b'], ['b'], 10), 3);
        $this->withRefs($this->makeItem('kanji', 'A', ['a'], ['a'], 10), 4); // more references first
        $this->withRefs($this->makeItem('kanji', 'C', ['c'], ['c'], 3), 3);  // lower level first
        $few = $this->makeItem('kanji', 'D', ['d'], ['d'], 1);
        $this->withRefs($few, 2);
        $this->withRefs($few, 5, 'rejected');
        $this->withRefs($this->makeItem('radical', 'R', [], [], 0), 5);    // radical without meanings
        $this->withRefs($this->makeItem('radical', 'Q', [], ['q'], 11), 3);

        $this->assertSame(['C', 'A', 'B', 'Q'], $this->newItems()->eligible()->pluck('characters')->all());
        $this->assertSame(['hiragana' => 0, 'katakana' => 0, 'radical' => 1, 'kanji' => 3, 'vocab' => 0], $this->newItems()->availableCounts());

        $user = $this->learner();
        $this->review($user, Item::query()->where('characters', 'C')->sole());
        $this->assertSame('A', $this->newItems()->next($user)?->characters);
    }
}
