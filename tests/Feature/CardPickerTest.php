<?php

namespace Tests\Feature;

use App\Learning\CardPicker;
use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CardPickerTest extends TestCase
{
    use BuildsLearning;
    use RefreshDatabase;

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRandom(7);
        $this->item = $this->makeItem('hiragana', 'ね', ['ne']);
    }

    /** @return array<int, int> reference id => times picked */
    private function tally(int $runs = 400): array
    {
        $user = auth()->user() ?? $this->learner();
        $picker = app(CardPicker::class);
        $counts = [];
        for ($i = 0; $i < $runs; $i++) {
            $id = $picker->pick($user, $this->item, null, [])['reference']->id;
            $counts[$id] = ($counts[$id] ?? 0) + 1;
        }

        return $counts;
    }

    public function test_favorite_talents_are_weighted_three_to_one(): void
    {
        $nene = $this->talent('Momosuzu Nene');
        $favored = $this->makeRef([[$this->item, 0]], talent: $nene);
        $others = $this->withRefs($this->item, 2);
        $user = $this->learner();
        $user->favorites()->attach($nene);
        $this->actingAs($user);

        $counts = $this->tally();

        // expected 3/5, 1/5, 1/5 of 400
        $this->assertGreaterThan(200, $counts[$favored->id]);
        foreach ($others as $other) {
            $this->assertLessThan(120, $counts[$other->id]);
        }
    }

    public function test_without_favorites_priority_talents_are_preferred(): void
    {
        $preferred = $this->makeRef([[$this->item, 0]], talent: $this->talent('Kiara', priority: 5));
        $this->withRefs($this->item, 2);
        $this->actingAs($this->learner());

        $this->assertGreaterThan(200, $this->tally()[$preferred->id]);
    }

    public function test_occurrence_of_a_preferred_talent_is_chosen_and_dead_ones_never(): void
    {
        $nene = $this->talent('Nene');
        $reference = $this->makeRef([[$this->item, 0]], talent: $this->talent('Other'));
        $favorite = $this->occurrence($reference, $nene);
        $this->occurrence($reference, $nene, dead: true);
        $user = $this->learner();
        $user->favorites()->attach($nene);
        $picker = app(CardPicker::class);

        for ($i = 0; $i < 30; $i++) {
            $this->assertSame($favorite->id, $picker->pick($user, $this->item, null, [])['occurrence']?->id);
        }
    }

    public function test_last_and_lesson_references_are_avoided_then_relaxed(): void
    {
        [$a, $b, $c] = $this->withRefs($this->item, 3)->all();
        $user = $this->learner();
        $picker = app(CardPicker::class);

        for ($i = 0; $i < 20; $i++) {
            $this->assertSame($c->id, $picker->pick($user, $this->item, $a->id, [$b->id])['reference']->id);
            $this->assertNotSame($a->id, $picker->pick($user, $this->item, $a->id, [$b->id, $c->id])['reference']->id); // lesson rule relaxed first
        }
        $this->assertNull($picker->pick($user, $this->makeItem('hiragana', 'の', ['no']), null, []));
    }
}
