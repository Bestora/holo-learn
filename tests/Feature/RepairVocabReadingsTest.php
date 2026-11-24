<?php

namespace Tests\Feature;

use App\Models\DictWord;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairVocabReadingsTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAnnotations;

    public function test_drops_readings_of_other_entries_and_their_links(): void
    {
        $this->seedDictionary();
        DictWord::query()->forceCreate(['spelling' => '一番', 'reading' => 'いちばん', 'glosses' => [['number one', 'first']], 'is_name' => false, 'common' => true]);
        DictWord::query()->forceCreate(['spelling' => '一番', 'reading' => 'ひとつがい', 'glosses' => [['pair (of animals)']], 'is_name' => false, 'common' => false]);
        // made under the old rule: readings of every 一番 row
        $ichiban = Item::query()->create(['kind' => 'vocab', 'characters' => '一番', 'readings' => ['いちばん', 'ひとつがい'], 'meanings' => ['number one', 'first'], 'level' => 10]);
        $ichiban->reviews()->create(['user_id' => User::factory()->create()->id, 'stage' => 3, 'due_at' => now()]);
        $hana = Item::query()->create(['kind' => 'vocab', 'characters' => '花', 'readings' => ['はな'], 'meanings' => ['flower', 'blossom', 'beauty'], 'level' => 10]);
        $right = $this->annotated('song', '一番', 'いちばん', [['一番', 'いちばん']]);
        $wrong = $this->annotated('song', '一番だ', 'ひとつがいだ', [['一番', 'ひとつがい'], ['だ', 'だ']]);
        $this->verify($right);
        $this->verify($wrong);
        $this->verify($this->annotated('song', '花', 'はな', [['花', 'はな']]));
        $this->assertSame(['vocab:一番@0=ひとつがい'], $this->links($wrong, 'vocab'));

        $this->artisan('items:repair-vocab-readings')
            ->expectsOutputToContain('vocab items changed: 1, links removed: 1')->assertSuccessful();

        $this->assertSame(['いちばん'], $ichiban->refresh()->readings);
        $this->assertSame(['vocab:一番@0=いちばん'], $this->links($right, 'vocab'));
        $this->assertSame([], $this->links($wrong, 'vocab'));
        $this->assertSame(1, $ichiban->reviews()->count());
        $this->assertSame(['はな'], $hana->refresh()->readings);

        $this->artisan('items:repair-vocab-readings')
            ->expectsOutputToContain('vocab items changed: 0, links removed: 0')->assertSuccessful();
    }
}
