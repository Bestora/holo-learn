<?php

namespace Tests\Feature;

use App\Models\DictKanji;
use App\Models\DictWord;
use App\Models\Item;
use Database\Seeders\KanaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DictImportTest extends TestCase
{
    use RefreshDatabase;

    private function import(): void
    {
        $this->artisan('dict:import', ['dir' => base_path('tests/fixtures/dict')])->assertSuccessful();
    }

    public function test_import_creates_words_names_and_kanji(): void
    {
        $this->import();

        $kyou = DictWord::query()->where('spelling', '今日')->where('reading', 'きょう')->sole();
        $this->assertFalse($kyou->is_name);
        $this->assertSame([['today', 'this day']], $kyou->glosses);

        $konnichi = DictWord::query()->where('spelling', '今日')->where('reading', 'こんにち')->sole();
        $this->assertSame([['today', 'this day'], ['these days', 'recently', 'nowadays']], $konnichi->glosses);

        $name = DictWord::query()->where('spelling', '桃鈴')->where('reading', 'ももすず')->sole();
        $this->assertTrue($name->is_name);
        $this->assertSame([['Momosuzu']], $name->glosses);

        $hana = DictKanji::find('花');
        $this->assertContains('艹', $hana->radicals);
        $this->assertSame(['flower'], $hana->meanings);
        $this->assertSame(['on' => ['カ', 'ケ'], 'kun' => ['はな'], 'nanori' => ['わ'], 'plain' => ['か', 'け', 'はな']], $hana->readings);
        $this->assertContains('もも', DictKanji::find('桃')->readings['kun']);
    }

    public function test_reading_restrictions_and_kana_only_words(): void
    {
        $this->import();

        $this->assertTrue(DictWord::query()->where('spelling', '机')->where('reading', 'つき')->exists());
        $this->assertFalse(DictWord::query()->where('spelling', '案')->where('reading', 'つき')->exists());
        $this->assertTrue(DictWord::query()->where('spelling', '案')->where('reading', 'つくえ')->exists());
        $this->assertTrue(DictWord::query()->where('spelling', 'にゃー')->where('reading', 'にゃー')->exists());
        // "usually kana" words are also stored with the kana as spelling
        $this->assertTrue(DictWord::query()->where('spelling', '否')->where('reading', 'いや')->exists());
        $this->assertTrue(DictWord::query()->where('spelling', 'いや')->where('reading', 'いや')->exists());
        $this->assertFalse(DictWord::query()->where('spelling', 'つくえ')->exists());
    }

    public function test_second_run_creates_no_duplicates(): void
    {
        $this->import();
        $words = DictWord::count();
        $kanji = DictKanji::count();

        $this->import();

        $this->assertSame(15, $words); // 今日×4, 机×2 + 案×1, 否 + いや, にゃー×4, 桃鈴, 兎田
        $this->assertSame($words, DictWord::count());
        $this->assertSame($kanji, DictKanji::count());
    }

    public function test_kana_seeder_creates_71_hiragana_and_71_katakana(): void
    {
        $this->seed(KanaSeeder::class);
        $this->seed(KanaSeeder::class);

        $this->assertSame(['ne'], Item::query()->where('kind', 'hiragana')->where('characters', 'ね')->sole()->readings);
        $this->assertSame(['ne'], Item::query()->where('kind', 'katakana')->where('characters', 'ネ')->sole()->readings);
        $this->assertSame([], Item::query()->where('characters', 'ネ')->sole()->meanings);
        $this->assertSame(71, Item::query()->where('kind', 'hiragana')->count());
        $this->assertSame(71, Item::query()->where('kind', 'katakana')->count());
        $this->assertSame(['shi'], Item::query()->where('characters', 'シ')->sole()->readings);
        $this->assertSame(['ji'], Item::query()->where('characters', 'ぢ')->sole()->readings);
        $this->assertSame(['n'], Item::query()->where('characters', 'ン')->sole()->readings);
    }
}
