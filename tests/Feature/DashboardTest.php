<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use BuildsLearning;
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk();
    }

    public function test_stats(): void
    {
        $this->freezeTime();
        $ne = $this->makeItem('hiragana', 'ね', ['ne']);
        $this->withRefs($ne, 3);
        $no = $this->makeItem('hiragana', 'の', ['no']);
        $this->withRefs($no, 3);
        $flower = $this->makeItem('kanji', '花', ['はな'], ['flower'], 10);
        $this->withRefs($flower, 4);
        $this->withRefs($this->makeItem('radical', '乙', [], [], 10), 5); // never available
        $this->makeItem('katakana', 'ネ', ['ne'], [], 1); // kana: always available
        $this->withRefs($this->makeItem('kanji', '海', ['うみ'], ['sea'], 10), 1); // kanji: needs 2
        $radical = $this->makeItem('radical', '艹', [], ['grass'], 10);
        $this->withRefs($radical, 1);
        $user = $this->learner();
        $user->forceFill(['streak_days' => 3, 'last_lesson_on' => now()->setTimezone('Europe/Berlin')->subDay()->toDateString()])->save();
        $this->review($user, $ne, 2, now()->subMinute());
        $this->review($user, $flower, 1, now()->addHour());
        $gone = $this->makeItem('kanji', '亡', ['ぼう'], ['deceased'], 10);
        $this->withRefs($gone, 2, 'rejected');
        $this->review($user, $gone, 2, now()->subHour()); // no verified reference: cannot become a card, not counted

        $this->actingAs($user)->get(route('dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
            ->component('dashboard')
            ->where('stats', [
                'streak_days' => 3,
                'due_count' => 1,
                'learned' => ['hiragana' => 1, 'katakana' => 0, 'radical' => 0, 'kanji' => 2, 'vocab' => 0],
                'available' => ['hiragana' => 2, 'katakana' => 1, 'radical' => 1, 'kanji' => 1, 'vocab' => 0],
            ]));

        $user->forceFill(['last_lesson_on' => now()->subDays(3)->toDateString()])->save();
        $this->get(route('dashboard'))->assertInertia(fn (AssertableInertia $page) => $page->where('stats.streak_days', 0));
    }
}
