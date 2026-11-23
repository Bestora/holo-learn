<?php

namespace Tests\Feature;

use App\Holodex\HolodexException;
use App\Holodex\TalentSync;
use App\Models\Occurrence;
use App\Models\Reference;
use App\Models\Talent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class TalentSyncTest extends TestCase
{
    use RefreshDatabase;

    private const NENE = 'UCAWSyEs_Io8MtpY3m-zqILA';

    private const SUISEI = 'UC5CwaMl1eIgY8h02uZw7u8A';

    protected function setUp(): void
    {
        parent::setUp();
        config(['hololearn.holodex.pause_ms' => 0, 'hololearn.holodex.key' => 'test-secret-key']);
        Http::preventStrayRequests();
    }

    /** @return array<string, mixed> */
    private function channel(string $id, string $name, ?string $english, ?string $group = 'hololive'): array
    {
        return [
            'id' => $id, 'name' => $name, 'english_name' => $english, 'group' => $group,
            'photo' => "https://img/{$id}", 'inactive' => false, 'org' => 'Hololive',
        ];
    }

    /** @return list<array<string, mixed>> */
    private function filler(int $count, int $start = 0): array
    {
        return array_map(
            fn (int $i) => $this->channel("UCfiller{$i}", "Talent {$i} Ch. ".mb_chr(0x4E00 + $i), "Talent {$i}"),
            range($start, $start + $count - 1),
        );
    }

    /** @param  list<list<array<string, mixed>>>  $pages */
    private function fakePages(array $pages): void
    {
        Http::fake(function ($request) use ($pages) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $page = intdiv((int) ($query['offset'] ?? 0), 50);

            return Http::response($pages[$page] ?? []);
        });
    }

    private function sync(): void
    {
        app(TalentSync::class)->run();
    }

    public function test_pages_until_short_page_and_rerun_creates_no_duplicates(): void
    {
        $this->fakePages([$this->filler(50), $this->filler(3, 50)]);

        $this->sync();
        $this->assertSame(53, Talent::count());

        $this->sync();
        $this->assertSame(53, Talent::count());
        $this->assertSame(53, Reference::count());
        $this->assertSame(53, Occurrence::count());

        Http::assertSent(fn ($request) => $request->hasHeader('X-APIKEY', 'test-secret-key')
            && $request->hasHeader('User-Agent', 'holo-learn (+https://holo-learn.u0x.de)')
            && str_contains($request->url(), 'org=Hololive')
            && str_contains($request->url(), 'type=vtuber')
            && str_contains($request->url(), 'limit=50'));
    }

    public function test_rerun_updates_changed_fields(): void
    {
        $changed = $this->channel(self::NENE, 'Nene Ch.桃鈴ねね', 'Momosuzu Nene', 'hololive 5th');
        $changed['inactive'] = true;
        $changed['photo'] = 'https://img/new';
        Http::fake(['*' => Http::sequence()
            ->push([$this->channel(self::NENE, 'Nene Ch.桃鈴ねね', 'Momosuzu Nene')])
            ->push([$changed])]);

        $this->sync();
        $this->sync();

        $talent = Talent::sole();
        $this->assertSame('hololive 5th', $talent->group_name);
        $this->assertTrue($talent->inactive);
        $this->assertSame('https://img/new', $talent->photo_url);
    }

    public function test_japanese_channel_name_creates_name_reference(): void
    {
        $this->fakePages([[$this->channel(self::NENE, 'Nene Ch.桃鈴ねね', 'Momosuzu Nene')]]);

        $this->sync();

        $talent = Talent::sole();
        $this->assertSame('桃鈴ねね', $talent->name_ja);
        $this->assertSame('Momosuzu Nene', $talent->name_en);
        $this->assertFalse($talent->is_secondary);

        $reference = Reference::sole();
        $this->assertSame('name', $reference->kind);
        $this->assertSame('桃鈴ねね', $reference->text_ja);
        $this->assertSame(sha1('桃鈴ねね'), $reference->text_key);
        $this->assertSame('Momosuzu Nene', $reference->alt_text);
        $this->assertSame('new', $reference->status);

        $occurrence = $reference->occurrences()->sole();
        $this->assertSame('name:'.self::NENE, $occurrence->source_id);
        $this->assertSame($talent->id, $occurrence->talent_id);
        $this->assertNull($occurrence->video_id);
        $this->assertSame(['桃鈴ねね'], $talent->references()->pluck('text_ja')->all());
    }

    public function test_name_override_supplies_japanese_name(): void
    {
        $this->fakePages([[$this->channel(self::SUISEI, 'Suisei Channel', 'Hoshimachi Suisei')]]);

        $this->sync();

        $this->assertSame('星街すいせい', Talent::sole()->name_ja);
        $this->assertSame('星街すいせい', Reference::sole()->text_ja);
        $this->assertSame('name:'.self::SUISEI, Occurrence::sole()->source_id);
    }

    public function test_channel_without_japanese_is_main_talent_without_name_reference(): void
    {
        $this->fakePages([[$this->channel('UCmori', 'Mori Calliope Ch. hololive-EN', 'Mori Calliope', 'English (Myth)')]]);

        $this->sync();

        $talent = Talent::sole();
        $this->assertFalse($talent->is_secondary);
        $this->assertNull($talent->name_ja);
        $this->assertSame(0, Reference::count());
    }

    public function test_secondary_channels_get_no_name_reference(): void
    {
        $this->fakePages([[
            $this->channel('UCkiarasub', 'Takanashi Kiara SubCh.', 'Takanashi Kiara SubCh.', 'English (Myth)'),
            $this->channel('UCregloss', 'hololive ReGLOSS', null, 'Official'),
            $this->channel('UCofficial', 'ホロライブ公式', 'hololive', 'Official'),
            $this->channel('UCmisc', 'ホロライブ ミスク', 'Misc', 'Misc'),
            $this->channel('UCsubparen', 'ホロ太郎 (Sub)', 'Holotaro'),
            $this->channel('UCsubaru', 'Subaru Ch. 大空スバル', 'Oozora Subaru', 'hololive 2nd'),
        ]]);

        $this->sync();

        $secondary = Talent::query()->where('is_secondary', true)->pluck('holodex_channel_id')->sort()->values()->all();
        $this->assertSame(['UCkiarasub', 'UCmisc', 'UCofficial', 'UCregloss', 'UCsubparen'], $secondary);
        $this->assertSame(['大空スバル'], Reference::query()->pluck('text_ja')->all());
    }

    public function test_holostars_channels_are_not_imported(): void
    {
        $this->fakePages([[
            $this->channel('UCuproar', 'Some Ch. 誰か', 'Someone', 'HOLOSTARS UPROAR!!'),
            $this->channel('UCstarsen', 'HOLOSTARS English', null, null),
            $this->channel('UCstarsname', 'holostars公式', 'Official', 'Official'),
            $this->channel(self::NENE, 'Nene Ch.桃鈴ねね', 'Momosuzu Nene'),
        ]]);

        $this->sync();

        $this->assertSame([self::NENE], Talent::query()->pluck('holodex_channel_id')->all());
    }

    public function test_priority_channel_gets_its_list_position(): void
    {
        $this->fakePages([[
            $this->channel(self::NENE, 'Nene Ch.桃鈴ねね', 'Momosuzu Nene'),
            $this->channel('UCt9H_RpQzhxzlyBxFqrdHqA', 'FUWAMOCO Ch. hololive-EN', 'Fuwawa & Mococo Abyssgard'),
            $this->channel('UCother', 'Other Ch. 他', 'Other'),
        ]]);

        $this->sync();

        $this->assertSame(14, Talent::query()->where('holodex_channel_id', self::NENE)->value('priority'));
        $this->assertSame(1, Talent::query()->where('holodex_channel_id', 'UCt9H_RpQzhxzlyBxFqrdHqA')->value('priority'));
        $this->assertNull(Talent::query()->where('holodex_channel_id', 'UCother')->value('priority'));
    }

    public function test_http_error_throws_and_keeps_stored_talents(): void
    {
        Http::fake(function ($request) {
            return str_contains($request->url(), 'offset=50')
                ? Http::response(['message' => 'Forbidden'], 403)
                : Http::response($this->filler(50));
        });

        try {
            $this->sync();
            $this->fail('HolodexException expected');
        } catch (HolodexException $e) {
            $this->assertSame(403, $e->status);
            $this->assertSame('/channels', $e->path);
            $this->assertStringNotContainsString('test-secret-key', $e->getMessage());
        }

        $this->assertSame(50, Talent::count());
    }

    public function test_non_json_response_throws(): void
    {
        Http::fake(['*' => Http::response('<html>oops</html>', 200)]);

        $this->expectException(HolodexException::class);
        $this->sync();
    }

    public function test_entry_without_id_is_skipped_and_logged(): void
    {
        Log::spy();
        $broken = $this->channel('x', 'Broken Ch. 壊れ', 'Broken');
        unset($broken['id']);
        $this->fakePages([[$broken, $this->channel(self::NENE, 'Nene Ch.桃鈴ねね', 'Momosuzu Nene')]]);

        $this->sync();

        $this->assertSame([self::NENE], Talent::query()->pluck('holodex_channel_id')->all());
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_reference_first_or_create_fills_missing_alt_text_only(): void
    {
        $first = Reference::firstOrCreateFor('song', 'フレンズ', null);
        $again = Reference::firstOrCreateFor('song', 'フレンズ', 'Friends');
        $third = Reference::firstOrCreateFor('song', 'フレンズ', 'Other');

        $this->assertSame($first->id, $again->id);
        $this->assertSame($first->id, $third->id);
        $this->assertSame('Friends', $third->fresh()?->alt_text);
        $this->assertNotSame($first->id, Reference::firstOrCreateFor('name', 'フレンズ', null)->id);
    }

    public function test_command_reports_failure_with_non_zero_exit(): void
    {
        Http::fake(['*' => Http::response([], 500)]);

        $this->artisan('holodex:sync', ['--talents' => true])->assertFailed();
    }

    public function test_command_syncs_talents_and_skips_songs_for_now(): void
    {
        $this->fakePages([[$this->channel(self::NENE, 'Nene Ch.桃鈴ねね', 'Momosuzu Nene')]]);

        $this->artisan('holodex:sync')
            ->expectsOutputToContain('Songs: noch nicht implementiert')
            ->assertSuccessful();

        $this->assertSame(1, Talent::count());
    }
}
