<?php

namespace Tests\Unit;

use App\Japanese\TextCleaner;
use PHPUnit\Framework\TestCase;

class TextCleanerTest extends TestCase
{
    public function test_talent_name_brief_cases(): void
    {
        $this->assertSame('桃鈴ねね', TextCleaner::talentName('Nene Ch.桃鈴ねね', 'Momosuzu Nene'));
        $this->assertSame('常闇トワ', TextCleaner::talentName('Towa Ch. 常闇トワ', 'Tokoyami Towa'));
        $this->assertSame('ラプラス・ダークネス', TextCleaner::talentName('Laplus ch. ラプラス・ダークネス - holoX -', 'La+ Darknesss'));
        $this->assertSame('宙科そぴあ', TextCleaner::talentName('Sopia Ch. 宙科そぴあ ‐ アソビ★まわり隊!', 'Sorashina Sopia'));
        $this->assertNull(TextCleaner::talentName('Suisei Channel', 'Hoshimachi Suisei'));
    }

    public function test_talent_name_rulings(): void
    {
        // longest run wins, brackets and spaces are not part of it
        $this->assertSame('角巻わため', TextCleaner::talentName('Watame Ch. 角巻わため【4期生】', 'Tsunomaki Watame'));
        $this->assertSame('兎田ぺこら', TextCleaner::talentName('【兎田ぺこら】Pekora Ch.', 'Usada Pekora'));
        // only the part before the first spaced dash counts
        $this->assertSame('ねね', TextCleaner::talentName('ねね – ホロライブ所属タレント', 'Nene'));
        // a bare dot run is no name
        $this->assertSame('ぺこら', TextCleaner::talentName('・ ぺこら・', 'Pekora'));
        $this->assertNull(TextCleaner::talentName('', ''));
    }

    public function test_talent_name_live_channel_names(): void
    {
        // a trailing チャンネル/ちゃんねる is no part of the name
        $this->assertSame('ときのそら', TextCleaner::talentName('SoraCh. ときのそらチャンネル', 'Tokino Sora'));
        $this->assertSame('夜空メル', TextCleaner::talentName('Mel Channel 夜空メルチャンネル', 'Yozora Mel'));
        $this->assertSame('みこ', TextCleaner::talentName('みこちゃんねる', 'Miko'));
        // runs containing ホロライブ are agency labels, not names
        $this->assertSame('アキロゼ', TextCleaner::talentName('アキロゼCh。Vtuber/ホロライブ所属', 'Aki Rosenthal'));
        $this->assertNull(TextCleaner::talentName('ホロライブ公式', 'hololive'));
    }

    public function test_song_name_brief_cases(): void
    {
        $this->assertSame(['text_ja' => '花に亡霊', 'alt' => 'Ghost In A Flower'], TextCleaner::songName('花に亡霊 / Ghost In A Flower'));
        $this->assertSame(['text_ja' => 'フレンズ', 'alt' => 'Friends'], TextCleaner::songName('フレンズ / Friends (ft. Mio) [3D Live]'));
        $this->assertSame(['text_ja' => 'ももいろの砂時計', 'alt' => null], TextCleaner::songName('ももいろの砂時計 (Instrumental)'));
        $this->assertSame('Ahoy!! 我ら宝鐘海賊団☆', TextCleaner::songName('Ahoy!! 我ら宝鐘海賊団☆ / Ahoy!! Warera Houshou Kaizokudan☆')['text_ja']);
        $this->assertSame(['text_ja' => null, 'alt' => null], TextCleaner::songName('Ridin\' on Dreams'));
    }

    public function test_song_name_rulings(): void
    {
        $this->assertSame(['text_ja' => '夜もすがら君想ふ', 'alt' => 'Yomosugara Kimi Omou'], TextCleaner::songName('Cover / 夜もすがら君想ふ / Yomosugara Kimi Omou (Short ver.)'));
        $this->assertSame(['text_ja' => 'きゅうくらりん', 'alt' => null], TextCleaner::songName('きゅうくらりん（歌ってみた）'));
        $this->assertSame(['text_ja' => '星街すいせい', 'alt' => null], TextCleaner::songName('Stellar / 星街すいせい'));
        $this->assertSame(['text_ja' => 'おねがい、ダーリン', 'alt' => null], TextCleaner::songName('おねがい、ダーリン / おねがいダーリン'));
        $this->assertSame(['text_ja' => null, 'alt' => null], TextCleaner::songName('Song (カバー)'));
        $this->assertSame(['text_ja' => null, 'alt' => null], TextCleaner::songName('A/B'));
        $this->assertSame(['text_ja' => null, 'alt' => null], TextCleaner::songName(''));
    }
}
