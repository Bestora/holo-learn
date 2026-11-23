<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Occurrence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Favorites, the specials setting and dead occurrences. */
class FavoritesTest extends TestCase
{
    use BuildsLearning;
    use RefreshDatabase;

    public function test_guests_are_redirected(): void
    {
        $this->get(route('favorites.index'))->assertRedirect(route('login'));
        $this->put(route('favorites.update'))->assertRedirect(route('login'));
        $this->put(route('specials.update'))->assertRedirect(route('login'));
        $this->post(route('occurrences.dead', 1))->assertRedirect(route('login'));
    }

    public function test_index_lists_non_secondary_talents_by_priority_then_name(): void
    {
        $zeta = $this->talent('Vestia Zeta', group: 'holoh3ro');
        $this->talent('Secondary', secondary: true);
        $suisei = $this->talent('Hoshimachi Suisei', priority: 8);
        $calli = $this->talent('Mori Calliope', priority: 2);
        $ame = $this->talent('Amelia Watson');
        $user = $this->learner();
        $user->favorites()->attach($suisei);

        $this->actingAs($user)->get(route('favorites.index'))->assertInertia(fn (AssertableInertia $page) => $page
            ->component('favorites/index')
            ->where('talents.0.id', $calli->id)
            ->where('talents.1.id', $suisei->id)
            ->where('talents.1.is_favorite', true)
            ->where('talents.2.id', $ame->id)
            ->where('talents.3', [
                'id' => $zeta->id, 'name_ja' => 'Vestia Zeta（ja）', 'name_en' => 'Vestia Zeta', 'photo_url' => $zeta->photo_url,
                'group_name' => 'holoh3ro', 'is_favorite' => false,
            ])
            ->has('talents', 4));
    }

    public function test_update_replaces_favorites(): void
    {
        $a = $this->talent('A');
        $b = $this->talent('B');
        $user = $this->learner();
        $user->favorites()->attach($a);

        $this->actingAs($user)->put(route('favorites.update'), ['talent_ids' => [$b->id]])->assertRedirect(route('favorites.index'));
        $this->assertSame([$b->id], $user->favorites()->pluck('talents.id')->all());

        $this->put(route('favorites.update'), ['talent_ids' => []])->assertRedirect();
        $this->assertSame([], $user->favorites()->pluck('talents.id')->all());
    }

    public function test_update_rejects_unknown_and_secondary_talents(): void
    {
        $secondary = $this->talent('S', secondary: true);
        $ok = $this->talent('OK');
        $user = $this->learner();
        $user->favorites()->attach($ok);
        $this->actingAs($user);

        $this->putJson(route('favorites.update'), ['talent_ids' => [999]])->assertStatus(422)->assertJsonValidationErrors(['talent_ids.0']);
        $this->putJson(route('favorites.update'), ['talent_ids' => [$ok->id, $secondary->id]])->assertStatus(422)
            ->assertJsonPath('errors', ['talent_ids.1' => ['Dieses Talent kann nicht Favorit werden.']]);
        $this->putJson(route('favorites.update'), [])->assertStatus(422)->assertJsonValidationErrors(['talent_ids']);
        $this->assertSame([$ok->id], $user->favorites()->pluck('talents.id')->all());
    }

    public function test_specials_setting(): void
    {
        $user = $this->learner();
        $this->actingAs($user);

        $this->put(route('specials.update'), ['enabled' => false])->assertRedirect();
        $this->assertFalse($user->fresh()->specials_enabled);
        $this->put(route('specials.update'), ['enabled' => true])->assertRedirect();
        $this->assertTrue($user->fresh()->specials_enabled);
        $this->putJson(route('specials.update'), ['enabled' => 'vielleicht'])->assertStatus(422);
    }

    public function test_marking_occurrences_dead_is_throttled_and_logged(): void
    {
        Log::spy();
        $occurrence = $this->makeRef([[$this->makeItem('hiragana', 'ね', ['ne']), 0]])->occurrences()->sole();
        $user = $this->learner();
        $this->actingAs($user);

        for ($i = 0; $i < 30; $i++) {
            $this->post(route('occurrences.dead', $occurrence))->assertNoContent();
        }
        $this->post(route('occurrences.dead', $occurrence))->assertTooManyRequests();

        Log::shouldHaveReceived('info')->with('occurrence marked dead', ['occurrence_id' => $occurrence->id, 'user_id' => $user->id])->atLeast()->once();
    }

    public function test_marking_an_occurrence_dead_is_idempotent(): void
    {
        $this->freezeTime();
        $reference = $this->makeRef([[$this->makeItem('hiragana', 'ね', ['ne']), 0]]);
        $occurrence = $reference->occurrences()->sole();
        $this->actingAs($this->learner());

        $this->post(route('occurrences.dead', $occurrence))->assertNoContent();
        $deadAt = $occurrence->fresh()?->dead_at;
        $this->assertNotNull($deadAt);

        $this->travel(1)->hour();
        $this->post(route('occurrences.dead', $occurrence))->assertNoContent();
        $this->assertEquals($deadAt, Occurrence::query()->findOrFail($occurrence->id)->dead_at);
        $this->post(route('occurrences.dead', 999))->assertNotFound();
        $this->assertSame(1, Item::query()->count());
    }
}
