<?php

namespace Tests\Feature;

use App\Holodex\HolodexException;
use App\Holodex\SongSync;
use App\Models\Item;
use App\Models\Occurrence;
use App\Models\Reference;
use App\Models\Talent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SongSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['hololearn.holodex.pause_ms' => 0, 'hololearn.holodex.key' => 'test-secret-key', 'hololearn.progress_file' => null]);
        Http::preventStrayRequests();
    }

    private function talent(string $channelId, ?int $priority = null, bool $secondary = false): Talent
    {
        return Talent::query()->create([
            'holodex_channel_id' => $channelId, 'name_en' => "Talent {$channelId}",
            'priority' => $priority, 'is_secondary' => $secondary,
        ]);
    }

    /** @return array<string, mixed> */
    private function song(string $name, string $videoId, ?int $start = 0, ?int $end = 100): array
    {
        return [
            'channel_id' => 'x', 'video_id' => $videoId, 'name' => $name, 'start' => $start, 'end' => $end,
            'itunesid' => null, 'art' => null, 'original_artist' => 'someone',
        ];
    }

    /** @return list<array<string, mixed>> */
    private function songs(int $count, int $from = 0): array
    {
        return array_map(fn (int $i) => $this->song("歌{$i}", "vid{$i}", $i), range($from, $from + $count - 1));
    }

    /**
     * Pages per channel id, chosen by the request's offset.
     *
     * @param  array<string, list<list<array<string, mixed>>>>  $pagesByChannel
     */
    private function fakeSongs(array $pagesByChannel, ?int $total = null): void
    {
        Http::fake(function ($request) use ($pagesByChannel, $total) {
            $body = $request->data();
            $pages = $pagesByChannel[$body['channel_id']] ?? [];
            $page = intdiv((int) $body['offset'], 50);
            $items = $pages[$page] ?? [];

            return Http::response(['total' => $total ?? array_sum(array_map('count', $pages)), 'items' => $items]);
        });
    }

    private function sync(Talent $talent): int
    {
        return app(SongSync::class)->run($talent);
    }

    public function test_song_creates_reference_and_occurrence(): void
    {
        $talent = $this->talent('UCnene');
        $this->fakeSongs(['UCnene' => [[$this->song('花に亡霊 / Ghost In A Flower', 'c-ujFpxFD3k', 4336, 4580)]]]);

        $this->assertSame(1, $this->sync($talent));

        $reference = Reference::sole();
        $this->assertSame('song', $reference->kind);
        $this->assertSame('花に亡霊', $reference->text_ja);
        $this->assertSame('Ghost In A Flower', $reference->alt_text);

        $occurrence = Occurrence::sole();
        $this->assertSame($reference->id, $occurrence->reference_id);
        $this->assertSame($talent->id, $occurrence->talent_id);
        $this->assertSame('c-ujFpxFD3k', $occurrence->video_id);
        $this->assertSame(4336, $occurrence->start_s);
        $this->assertSame(4580, $occurrence->end_s);
        $this->assertSame('song:c-ujFpxFD3k:4336', $occurrence->source_id);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/songs/latest')
            && $request->hasHeader('X-APIKEY', 'test-secret-key')
            && $request->data() === ['channel_id' => 'UCnene', 'paginated' => true, 'limit' => 50, 'offset' => 0]);
    }

    public function test_same_title_for_second_talent_reuses_reference(): void
    {
        $nene = $this->talent('UCnene');
        $polka = $this->talent('UCpolka');
        $this->fakeSongs([
            'UCnene' => [[$this->song('花に亡霊 / Ghost In A Flower', 'v1', 10)]],
            'UCpolka' => [[$this->song('花に亡霊', 'v2', 20)]],
        ]);

        $this->sync($nene);
        $this->sync($polka);

        $this->assertSame(1, Reference::count());
        $this->assertSame([$nene->id, $polka->id], Occurrence::query()->orderBy('id')->pluck('talent_id')->all());
    }

    public function test_title_without_japanese_and_malformed_items_are_skipped(): void
    {
        $talent = $this->talent('UCnene');
        $this->fakeSongs(['UCnene' => [[
            $this->song("Ridin' on Dreams", 'v1'),
            ['video_id' => 'v2', 'start' => 0],
            ['name' => 'フレンズ', 'start' => 0],
            ['name' => ['フレンズ'], 'video_id' => 'v3'],
            ['name' => 'フレンズ', 'video_id' => ['v4']],
            'garbage',
            $this->song('フレンズ', 'v5'),
        ]]]);

        $sync = app(SongSync::class);
        $this->assertSame(1, $sync->run($talent));

        $this->assertSame(['フレンズ'], Reference::query()->pluck('text_ja')->all());
        $this->assertSame(['v5'], Occurrence::query()->pluck('video_id')->all());
        $this->assertSame(1, $sync->skippedNoJapanese);
        $this->assertSame(5, $sync->skippedMalformed);
    }

    public function test_item_without_start_or_end(): void
    {
        $talent = $this->talent('UCnene');
        $item = $this->song('フレンズ', 'v1');
        unset($item['start'], $item['end']);
        $this->fakeSongs(['UCnene' => [[$item, $this->song('きゅうくらりん', 'v2', null, null)]]]);

        $this->sync($talent);

        $occurrences = Occurrence::query()->orderBy('id')->get();
        $this->assertSame(['song:v1:0', 'song:v2:0'], $occurrences->pluck('source_id')->all());
        $this->assertSame([null, null], $occurrences->pluck('start_s')->all());
        $this->assertSame([null, null], $occurrences->pluck('end_s')->all());
    }

    public function test_same_video_and_start_is_one_occurrence(): void
    {
        $talent = $this->talent('UCnene');
        $this->fakeSongs(['UCnene' => [[$this->song('フレンズ', 'v1', 5), $this->song('フレンズ / Friends', 'v1', 5)]]]);

        $this->assertSame(1, $this->sync($talent));
        $this->assertSame(1, Occurrence::count());
    }

    public function test_pages_until_short_page(): void
    {
        $talent = $this->talent('UCnene');
        $this->fakeSongs(['UCnene' => [$this->songs(50), $this->songs(50, 50), $this->songs(3, 100)]], 1000);

        $this->assertSame(103, $this->sync($talent));
        Http::assertSentCount(3);
    }

    public function test_stops_when_offset_reaches_total(): void
    {
        $talent = $this->talent('UCnene');
        $this->fakeSongs(['UCnene' => [$this->songs(50), $this->songs(50, 50), $this->songs(50, 100)]], 100);

        $this->assertSame(100, $this->sync($talent));
        Http::assertSentCount(2);
    }

    public function test_stops_after_400_pages(): void
    {
        $talent = $this->talent('UCnene');
        Http::fake(function ($request) {
            // non-Japanese titles are never stored, so no other stop condition kicks in
            $items = array_map(fn (int $i) => $this->song("Song {$i}", "v{$i}", $i), range(1, 50));

            return Http::response(['total' => 1_000_000, 'items' => $items]);
        });

        $this->sync($talent);

        Http::assertSentCount(400);
    }

    public function test_rerun_creates_nothing_and_stops_at_first_known_page(): void
    {
        $talent = $this->talent('UCnene');
        $list = $this->songs(103);
        Http::fake(function ($request) use (&$list) {
            return Http::response(['total' => 1000, 'items' => array_slice($list, (int) $request->data()['offset'], 50)]);
        });
        $this->sync($talent);

        $this->assertSame(0, $this->sync($talent));
        $this->assertSame(103, Occurrence::count());

        // a new song on top shifts everything by one; page 2 is then fully known
        array_unshift($list, $this->song('新曲', 'new1'));
        $this->assertSame(1, $this->sync($talent));
        $this->assertSame(104, Occurrence::count());
        Http::assertSentCount(3 + 1 + 2);
    }

    public function test_page_of_only_non_japanese_titles_does_not_stop_first_run(): void
    {
        $talent = $this->talent('UCmori');
        $english = array_map(fn (int $i) => $this->song("English {$i}", "en{$i}", $i), range(0, 49));
        $this->fakeSongs(['UCmori' => [$english, [$this->song('フレンズ', 'v1')]]]);

        $this->assertSame(1, $this->sync($talent));
    }

    public function test_response_without_items_throws(): void
    {
        $talent = $this->talent('UCnene');
        Http::fake(['*' => Http::response(['total' => 3])]);

        try {
            $this->sync($talent);
            $this->fail('HolodexException expected');
        } catch (HolodexException $e) {
            $this->assertSame('/songs/latest', $e->path);
        }
    }

    public function test_progress_file_is_written_and_removed(): void
    {
        $file = sys_get_temp_dir().'/holo-progress-'.uniqid();
        config(['hololearn.progress_file' => $file]);
        $talent = $this->talent('UCnene');
        $seen = [];
        Http::fake(function ($request) use ($file, &$seen) {
            $seen[] = is_file($file) ? file_get_contents($file) : null;
            $offset = (int) $request->data()['offset'];

            return Http::response(['total' => 60, 'items' => $this->songs($offset === 0 ? 50 : 10, $offset)]);
        });

        $this->sync($talent);

        $this->assertSame([null, "50 60 Songs\n"], $seen);
        $this->assertFileDoesNotExist($file);
    }

    public function test_command_syncs_all_talents_by_priority_and_continues_after_failure(): void
    {
        Log::spy();
        $this->talent('UClast');
        $this->talent('UCbroken', 2);
        $this->talent('UCsub', 1, secondary: true);
        $order = [];
        Http::fake(function ($request) use (&$order) {
            $channel = $request->data()['channel_id'];
            $order[] = $channel;

            return match ($channel) {
                'UCbroken' => Http::response(['message' => 'nope'], 500),
                default => Http::response(['total' => 1, 'items' => [$this->song("歌 {$channel}", "v{$channel}")]]),
            };
        });

        $this->artisan('holodex:sync', ['--songs' => true])
            ->expectsOutputToContain('UCbroken')
            ->assertFailed();

        $this->assertSame(['UCsub', 'UCbroken', 'UClast'], $order);
        $this->assertSame(2, Occurrence::count());
        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context) => $context['status'] === 500
            && $context['path'] === '/songs/latest'
            && $context['channel_id'] === 'UCbroken'
            && ! str_contains(json_encode($context) ?: '', 'test-secret-key'));
    }

    public function test_command_with_channel_option_syncs_only_that_talent(): void
    {
        $this->talent('UCnene');
        $this->talent('UCother');
        $this->fakeSongs(['UCnene' => [[$this->song('フレンズ', 'v1')]], 'UCother' => [[$this->song('歌', 'v2')]]]);

        $this->artisan('holodex:sync', ['--songs' => true, '--channel' => 'UCnene'])->assertSuccessful();

        $this->assertSame(['v1'], Occurrence::query()->pluck('video_id')->all());
    }

    public function test_command_with_unknown_channel_fails(): void
    {
        $this->artisan('holodex:sync', ['--songs' => true, '--channel' => 'UCnope'])->assertFailed();
    }

    private function storedSong(Talent $talent, string $textJa, string $sourceId, string $status = 'new'): Occurrence
    {
        $reference = Reference::firstOrCreateFor('song', $textJa, null);
        $reference->update(['status' => $status]);

        return Occurrence::query()->create([
            'reference_id' => $reference->id, 'talent_id' => $talent->id, 'video_id' => 'v', 'source_id' => $sourceId,
        ]);
    }

    public function test_recleaned_title_moves_occurrence_and_drops_orphaned_reference(): void
    {
        $talent = $this->talent('UCnene');
        $moved = $this->storedSong($talent, 'ファンサ／Fansa', 'song:v1:0');
        $annotated = $this->storedSong($talent, 'ジェンガ／Jenga', 'song:v2:0', 'annotated');
        $dropped = $this->storedSong($talent, 'from Y to Y feat.初音ミク', 'song:v3:0');
        $this->fakeSongs(['UCnene' => [[
            $this->song('ファンサ／Fansa', 'v1'), $this->song('ジェンガ／Jenga', 'v2'), $this->song('from Y to Y feat.初音ミク', 'v3'),
        ]]]);

        $sync = app(SongSync::class);
        $this->assertSame(0, $sync->run($talent, full: true));

        $this->assertSame('ファンサ', $moved->fresh()?->reference?->text_ja);
        $this->assertSame('ジェンガ', $annotated->fresh()?->reference?->text_ja);
        $this->assertNull($dropped->fresh(), 'title without Japanese after re-cleaning loses its occurrence');
        // orphans go regardless of status; annotations are re-importable from versioned files
        $this->assertEqualsCanonicalizing(['ファンサ', 'ジェンガ'], Reference::query()->pluck('text_ja')->all());
        $this->assertSame(3, $sync->deletedReferences);
    }

    public function test_incremental_run_after_aborted_first_run_stops_early_and_full_run_fetches_the_rest(): void
    {
        $talent = $this->talent('UCnene');
        $list = $this->songs(120);
        $broken = true;
        Http::fake(function ($request) use ($list, &$broken) {
            $offset = (int) $request->data()['offset'];
            if ($broken && $offset >= 50) {
                return Http::response([], 502);
            }

            return Http::response(['total' => 120, 'items' => array_slice($list, $offset, 50)]);
        });

        try {
            $this->sync($talent);
            $this->fail('HolodexException expected');
        } catch (HolodexException) {
        }
        $this->assertSame(50, Occurrence::count());
        $broken = false;

        // documented limit: the first page is fully known, so the incremental run stops there
        $this->assertSame(0, $this->sync($talent));
        $this->assertSame(50, Occurrence::count());

        $this->assertSame(70, app(SongSync::class)->run($talent, full: true));
        $this->assertSame(120, Occurrence::count());
    }

    public function test_command_full_option_walks_all_pages(): void
    {
        $talent = $this->talent('UCnene');
        $list = $this->songs(120);
        Http::fake(fn ($request) => Http::response(['total' => 120, 'items' => array_slice($list, (int) $request->data()['offset'], 50)]));
        app(SongSync::class)->run($talent);

        $this->artisan('holodex:sync', ['--songs' => true])->assertSuccessful();
        $this->artisan('holodex:sync', ['--songs' => true, '--full' => true])->assertSuccessful();

        Http::assertSentCount(3 + 1 + 3);
    }

    public function test_artist_only_title_is_skipped_and_counted(): void
    {
        $talent = $this->talent('UCnene');
        $overdose = $this->song('Overdose / なとり', 'v1');
        $overdose['original_artist'] = 'なとり';
        $yoasobi = $this->song('夜に駆ける / Yoru ni Kakeru - YOASOBI', 'v2');
        $yoasobi['original_artist'] = 'YOASOBI';
        $this->fakeSongs(['UCnene' => [[$overdose, $yoasobi]]]);

        $sync = app(SongSync::class);
        $this->assertSame(1, $sync->run($talent));

        $this->assertSame(['夜に駆ける' => 'Yoru ni Kakeru'], Reference::query()->pluck('alt_text', 'text_ja')->all());
        $this->assertSame(1, $sync->skippedArtistOnly);
        $this->assertSame(0, $sync->skippedNoJapanese);
    }

    public function test_same_source_id_twice_in_one_run_first_wins(): void
    {
        $talent = $this->talent('UCnene');
        $this->fakeSongs(['UCnene' => [[$this->song('フレンズ', 'v1', 5), $this->song('別の歌', 'v1', 5)]]]);

        $sync = app(SongSync::class);
        $sync->run($talent);
        $sync->run($talent, full: true);

        $this->assertSame('フレンズ', Occurrence::sole()->reference?->text_ja);
        $this->assertSame(['フレンズ'], Reference::query()->pluck('text_ja')->all());
        $this->assertSame(1, $sync->skippedDuplicate);
        $this->assertSame(0, $sync->deletedReferences);
    }

    public function test_no_progress_file_without_total(): void
    {
        $file = sys_get_temp_dir().'/holo-progress-'.uniqid();
        config(['hololearn.progress_file' => $file]);
        $talent = $this->talent('UCnene');
        $seen = [];
        Http::fake(function ($request) use ($file, &$seen) {
            $seen[] = is_file($file);
            $offset = (int) $request->data()['offset'];

            return Http::response(['items' => $this->songs($offset === 0 ? 50 : 10, $offset)]);
        });

        $this->assertSame(60, $this->sync($talent));

        $this->assertSame([false, false], $seen);
        $this->assertFileDoesNotExist($file);
    }

    public function test_command_logs_response_without_items_and_continues(): void
    {
        Log::spy();
        $this->talent('UCbroken', 1);
        $this->talent('UCok', 2);
        Http::fake(fn ($request) => $request->data()['channel_id'] === 'UCbroken'
            ? Http::response(['total' => 3])
            : Http::response(['total' => 1, 'items' => [$this->song('フレンズ', 'v1')]]));

        $this->artisan('holodex:sync', ['--songs' => true])->assertFailed();

        $this->assertSame(1, Occurrence::count());
        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context) => $context['status'] === 200
            && $context['path'] === '/songs/latest'
            && $context['channel_id'] === 'UCbroken');
    }

    public function test_reference_key_is_normalized(): void
    {
        $tilde = Reference::firstOrCreateFor('song', 'secret base ～君がくれたもの～', null);
        $wave = Reference::firstOrCreateFor('song', 'secret base 〜君がくれたもの〜', null);
        $wide = Reference::firstOrCreateFor('song', 'ＡＢＣ 花', null);
        $narrow = Reference::firstOrCreateFor('song', ' abc   花', null);

        $this->assertSame($tilde->id, $wave->id);
        $this->assertSame($wide->id, $narrow->id);
        $this->assertSame(['secret base ～君がくれたもの～', 'ＡＢＣ 花'], Reference::query()->orderBy('id')->pluck('text_ja')->all());
        $this->assertSame(sha1('abc 花'), $wide->text_key);
    }

    public function test_key_migration_merges_duplicates_into_lowest_id(): void
    {
        $talent = $this->talent('UCnene');
        $old = fn (string $text, string $status = 'new') => Reference::query()->create(['kind' => 'song', 'text_ja' => $text, 'text_key' => sha1($text), 'status' => $status]);
        $keep = $old('secret base ～君がくれたもの～');
        $dup = $old('secret base 〜君がくれたもの〜', 'annotated');
        $other = $old('ＡＢＣ 花');
        $name = Reference::query()->create(['kind' => 'name', 'text_ja' => 'ＡＢＣ 花', 'text_key' => sha1('ＡＢＣ 花')]);
        foreach ([[$keep, 's1'], [$dup, 's2'], [$name, 's3']] as [$reference, $sourceId]) {
            Occurrence::query()->create(['reference_id' => $reference->id, 'talent_id' => $talent->id, 'source_id' => $sourceId]);
        }
        $item = Item::query()->create(['kind' => 'kanji', 'characters' => '君', 'readings' => ['きみ'], 'meanings' => ['you']]);
        $item->references()->attach($dup->id, ['position' => 3]);
        $item->references()->attach($keep->id, ['position' => 1]);
        $item->references()->attach($dup->id, ['position' => 1]);

        (require database_path('migrations/2026_11_23_200005_normalize_reference_keys.php'))->up();

        $this->assertSame([$keep->id, $other->id, $name->id], Reference::query()->orderBy('id')->pluck('id')->all());
        $this->assertSame([$keep->id, $keep->id, $name->id], Occurrence::query()->orderBy('source_id')->pluck('reference_id')->all());
        $this->assertSame(Reference::keyFor('secret base 〜君がくれたもの〜'), $keep->fresh()?->text_key);
        $this->assertSame(sha1('abc 花'), $other->fresh()?->text_key);
        $this->assertSame(sha1('abc 花'), $name->fresh()?->text_key);
        $this->assertSame([1, 3], $item->references()->orderByPivot('position')->get()->map(fn (Reference $r) => [$r->id, $r->pivot->position])->filter(fn ($p) => $p[0] === $keep->id)->pluck(1)->all());
    }

    public function test_reference_texts_are_text_columns(): void
    {
        // sqlite ignores varchar lengths, so check the declared type
        $this->assertSame('text', Schema::getColumnType('refs', 'text_ja'));
        $this->assertSame('text', Schema::getColumnType('refs', 'alt_text'));
    }

    public function test_scheduler_runs_incremental_monday_to_saturday_and_full_on_sunday(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $e) => str_contains((string) $e->command, 'holodex:sync'))
            ->mapWithKeys(fn (Event $e) => [mb_trim(mb_substr((string) $e->command, mb_strpos((string) $e->command, 'holodex:sync'))) => $e]);

        $this->assertEqualsCanonicalizing(['holodex:sync', 'holodex:sync --full'], $events->keys()->all());
        $this->assertSame('30 3 * * 1-6', $events['holodex:sync']->expression); // never next to the full run
        $this->assertSame('30 4 * * 0', $events['holodex:sync --full']->expression);
        $this->assertTrue($events->every(fn (Event $e) => $e->withoutOverlapping));
    }
}
