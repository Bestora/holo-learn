<?php

namespace Tests\Feature;

use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItemsRelevelTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAnnotations;

    /** @return array<string, array{int, bool}> "kind|characters" => [level, special] */
    private function levels(): array
    {
        return Item::query()->orderBy('id')->get()
            ->mapWithKeys(fn (Item $item) => ["{$item->kind}|{$item->characters}" => [$item->level, $item->is_special]])->all();
    }

    public function test_relevel_fixes_wrong_levels_and_is_idempotent(): void
    {
        $this->seedDictionary();
        $this->verify($this->annotated('name', '湊あくあ', 'みなとあくあ', [['湊', 'みなと'], ['あくあ', 'あくあ']], 'Minato Aqua', 'romaji'));
        $this->verify($this->annotated('song', '亡霊とアイドル', 'ぼうれいとあいどる', [['亡霊', 'ぼうれい'], ['と', 'と'], ['アイドル', 'あいどる']]));
        $this->verify($this->annotated('song', '今日の海賊団', 'きょうのかいぞくだん', [['今日', 'きょう'], ['の', 'の'], ['海賊団', 'かいぞくだん']]));
        $this->verify($this->annotated('song', '綺麗', 'きれい', [['綺麗', 'きれい']]));
        Item::query()->create(['kind' => 'radical', 'characters' => '丸', 'readings' => [], 'meanings' => []]); // used by no kanji item
        $expected = $this->levels();
        $expected['radical|丸'] = [40, true];

        Item::query()->update(['level' => 99, 'is_special' => true]);
        $this->artisan('items:relevel')->expectsOutputToContain('geändert: '.count($expected))->assertSuccessful();

        $this->assertSame($expected, $this->levels());
        $this->assertSame([2, false], $expected['vocab|アイドル']);
        $this->assertSame([13, true], $expected['vocab|海賊団']);
        $this->assertSame([40, true], $expected['vocab|綺麗']);
        $this->assertSame([10, false], $expected['radical|一']); // 今 (10) beats 霊 (13) and 湊 (40)
        $this->assertSame([11, false], $expected['radical|氵']);
        $this->assertSame([40, true], $expected['radical|二']);
        $this->assertSame([1, false], $expected['katakana|ア']);

        $this->artisan('items:relevel')->expectsOutputToContain('geändert: 0')->assertSuccessful();
        $this->assertSame($expected, $this->levels());
    }
}
