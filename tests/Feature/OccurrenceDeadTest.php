<?php

namespace Tests\Feature;

use App\Models\Occurrence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** A player error only marks an occurrence dead once YouTube's oEmbed confirms the video is gone. */
class OccurrenceDeadTest extends TestCase
{
    use BuildsLearning;
    use RefreshDatabase;

    private function candidate(): Occurrence
    {
        return $this->makeRef([[$this->makeItem('hiragana', 'ね', ['ne']), 0]])->occurrences()->sole();
    }

    public function test_a_missing_video_is_marked_dead(): void
    {
        Http::preventStrayRequests();
        Http::fake(['www.youtube.com/oembed*' => Http::response('Not Found', 404)]);
        $occurrence = $this->candidate();

        $this->actingAs($this->learner())->post(route('occurrences.dead', $occurrence))->assertNoContent();

        $this->assertNotNull($occurrence->fresh()?->dead_at);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://www.youtube.com/oembed?url='.urlencode('https://www.youtube.com/watch?v='.$occurrence->video_id).'&format=json'
            && $request->hasHeader('User-Agent', 'holo-learn (+https://holo-learn.u0x.de)'));
    }

    public function test_a_private_or_not_embeddable_video_is_marked_dead(): void
    {
        Http::preventStrayRequests();
        Http::fake(['www.youtube.com/oembed*' => Http::sequence()->push('Unauthorized', 401)->push('Forbidden', 403)]);
        $first = $this->candidate();
        $second = $this->candidate();
        $this->actingAs($this->learner());

        $this->post(route('occurrences.dead', $first))->assertNoContent();
        $this->post(route('occurrences.dead', $second))->assertNoContent();

        $this->assertNotNull($first->fresh()?->dead_at);
        $this->assertNotNull($second->fresh()?->dead_at);
    }

    public function test_a_live_video_stays_alive(): void
    {
        Http::preventStrayRequests();
        Http::fake(['www.youtube.com/oembed*' => Http::response(['title' => 'Karaoke'], 200)]);
        $occurrence = $this->candidate();

        $this->actingAs($this->learner())->post(route('occurrences.dead', $occurrence))->assertNoContent();

        $this->assertNull($occurrence->fresh()?->dead_at);
    }

    public function test_other_statuses_and_timeouts_change_nothing(): void
    {
        Http::preventStrayRequests();
        Http::fake(['www.youtube.com/oembed*' => Http::sequence()
            ->push('Server Error', 500)
            ->pushFailedConnection('timed out')]);
        $occurrence = $this->candidate();
        $this->actingAs($this->learner());

        $this->post(route('occurrences.dead', $occurrence))->assertNoContent();
        $this->post(route('occurrences.dead', $occurrence))->assertNoContent();

        $this->assertNull($occurrence->fresh()?->dead_at);
    }
}
