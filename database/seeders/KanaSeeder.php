<?php

namespace Database\Seeders;

use App\Learning\ItemLevel;
use App\Models\Item;
use Illuminate\Database\Seeder;

class KanaSeeder extends Seeder
{
    /** 46 basic kana, 20 dakuten, 5 handakuten with Hepburn romaji. */
    private const HIRAGANA = [
        'あ' => 'a', 'い' => 'i', 'う' => 'u', 'え' => 'e', 'お' => 'o',
        'か' => 'ka', 'き' => 'ki', 'く' => 'ku', 'け' => 'ke', 'こ' => 'ko',
        'さ' => 'sa', 'し' => 'shi', 'す' => 'su', 'せ' => 'se', 'そ' => 'so',
        'た' => 'ta', 'ち' => 'chi', 'つ' => 'tsu', 'て' => 'te', 'と' => 'to',
        'な' => 'na', 'に' => 'ni', 'ぬ' => 'nu', 'ね' => 'ne', 'の' => 'no',
        'は' => 'ha', 'ひ' => 'hi', 'ふ' => 'fu', 'へ' => 'he', 'ほ' => 'ho',
        'ま' => 'ma', 'み' => 'mi', 'む' => 'mu', 'め' => 'me', 'も' => 'mo',
        'や' => 'ya', 'ゆ' => 'yu', 'よ' => 'yo',
        'ら' => 'ra', 'り' => 'ri', 'る' => 'ru', 'れ' => 're', 'ろ' => 'ro',
        'わ' => 'wa', 'を' => 'wo', 'ん' => 'n',
        'が' => 'ga', 'ぎ' => 'gi', 'ぐ' => 'gu', 'げ' => 'ge', 'ご' => 'go',
        'ざ' => 'za', 'じ' => 'ji', 'ず' => 'zu', 'ぜ' => 'ze', 'ぞ' => 'zo',
        'だ' => 'da', 'ぢ' => 'ji', 'づ' => 'zu', 'で' => 'de', 'ど' => 'do',
        'ば' => 'ba', 'び' => 'bi', 'ぶ' => 'bu', 'べ' => 'be', 'ぼ' => 'bo',
        'ぱ' => 'pa', 'ぴ' => 'pi', 'ぷ' => 'pu', 'ぺ' => 'pe', 'ぽ' => 'po',
    ];

    public function run(): void
    {
        $rows = [];
        foreach (self::HIRAGANA as $kana => $romaji) {
            $readings = json_encode([$romaji]);
            $rows[] = ['kind' => 'hiragana', 'characters' => $kana, 'readings' => $readings, 'meanings' => '[]', 'level' => ItemLevel::kana('hiragana')[0]];
            $rows[] = ['kind' => 'katakana', 'characters' => mb_convert_kana($kana, 'C'), 'readings' => $readings, 'meanings' => '[]', 'level' => ItemLevel::kana('katakana')[0]];
        }

        Item::upsert($rows, ['kind', 'characters'], ['readings', 'meanings', 'level']);
    }
}
