<?php

namespace Tests\Unit;

use App\Japanese\TextCleaner;
use PHPUnit\Framework\Attributes\DataProvider;
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
        // the Japanese segment is the artist, not the title
        $this->assertSame(['text_ja' => null, 'alt' => null], TextCleaner::songName('Stellar / 星街すいせい', '星街すいせい'));
        $this->assertSame(['text_ja' => 'おねがい、ダーリン', 'alt' => null], TextCleaner::songName('おねがい、ダーリン / おねがいダーリン'));
        $this->assertSame(['text_ja' => null, 'alt' => null], TextCleaner::songName('Song (カバー)'));
        $this->assertSame(['text_ja' => null, 'alt' => null], TextCleaner::songName('A/B'));
        $this->assertSame(['text_ja' => null, 'alt' => null], TextCleaner::songName(''));
    }

    /** @return array<string, array{string, ?string, ?string}> */
    public static function realSongTitles(): array
    {
        // Titles from the Nene run (2026-11-23) that the first cleaner left uncut.
        return [
            'full-width slash' => ['ファンサ／Fansa', 'ファンサ', 'Fansa'],
            'full-width slash 2' => ['ジェンガ／Jenga', 'ジェンガ', 'Jenga'],
            'full-width slash 3' => ['アイドル／Idol', 'アイドル', 'Idol'],
            'full-width slash 4' => ['ピエロ／Pierrot', 'ピエロ', 'Pierrot'],
            'full-width slash 5' => ['東京サマーセッション／Tokyo Summer Session', '東京サマーセッション', 'Tokyo Summer Session'],
            'full-width slash 6' => ['シュガーソングとビターステップ／Sugar Song and Bitter Step', 'シュガーソングとビターステップ', 'Sugar Song and Bitter Step'],
            'full-width slash 7' => ['トウキョウ・シャンディ・ランデヴ／Tokyo Shandy Rendez-vous', 'トウキョウ・シャンディ・ランデヴ', 'Tokyo Shandy Rendez-vous'],
            'slash, space after' => ['チャンス！/ Chance', 'チャンス！', 'Chance'],
            'slash, space after 2' => ['言って。/ Say It.', '言って。', 'Say It.'],
            'slash, space after 3' => ['脱げばいいってモンじゃない！/ Nugeba ii tte Mon ja nai!', '脱げばいいってモンじゃない！', 'Nugeba ii tte Mon ja nai!'],
            'slash, space after 4' => ['こちら、幸福安心委員会です。/ This Is the Happiness and Peace of Mind Commitee', 'こちら、幸福安心委員会です。', 'This Is the Happiness and Peace of Mind Commitee'],
            'slash, space after 5' => ['雪、無音、窓辺にて。/ Yuki, Muon, Madobe Nite.', '雪、無音、窓辺にて。', 'Yuki, Muon, Madobe Nite.'],
            'slash, space after 6' => ["今はいいんだよ。/ It's Okay Now", '今はいいんだよ。', "It's Okay Now"],
            'feat. without space' => ['サリシノハラ feat.初音ミク', 'サリシノハラ', null],
            'feat. before slash' => ['天ノ弱 feat.GUMI / Amanojaku', '天ノ弱', 'Amanojaku'],
            'dash suffix' => ['晩餐歌 - Bansanka', '晩餐歌', 'Bansanka'],
            'lenticular brackets' => ['みくみくにしてあげる♪【してやんよ】 / Mikumiku ni Shiteageru', 'みくみくにしてあげる♪', 'Mikumiku ni Shiteageru'],
            // only Japanese was the featured singer: the title itself is Latin
            'feat. leaves Latin title' => ['from Y to Y feat.初音ミク', null, null],
            // general rules, synthetic
            'ft. with space' => ['フレンズ ft. Mio', 'フレンズ', null],
            'featuring, any case' => ['フレンズ FEATURING Mio / Friends', 'フレンズ', 'Friends'],
            'lenticular in the middle' => ['【MV】フレンズ / Friends', 'フレンズ', 'Friends'],
            'dash suffix with Japanese stays' => ['恋 - 愛の歌', '恋 - 愛の歌', null],
            'slash alt wins over dash' => ['恋 - Koi / Love', '恋', 'Love'],
        ];
    }

    /** @return array<string, array{string, ?string, ?string, ?string}> */
    public static function reviewSongTitles(): array
    {
        // name, original_artist, text_ja, alt
        return [
            'artist only' => ['Overdose / なとり', 'なとり', null, null],
            'artist after dash in alt' => ['夜に駆ける / Yoru ni Kakeru - YOASOBI', 'YOASOBI', '夜に駆ける', 'Yoru ni Kakeru'],
            'artist after dash, feat' => ['恋愛裁判 - 40mP feat. 初音ミク', '40mP', '恋愛裁判', null],
            'artist compare ignores inner spaces' => ['Overdose / な と り ', ' なとり', null, null],
            'artist compare is not kana-insensitive' => ['Overdose / なとり', 'ナトリ', 'なとり', null],
            'artist compare ignores case and spaces 2' => ['Bling-Bang-Bang-Born / creepy  nuts', 'Creepy Nuts', null, null],
            'unspaced slash split' => ['アイドル/Idol', null, 'アイドル', 'Idol'],
            'unspaced slash, Japanese right' => ['ハロ/ハワユ', null, 'ハロ/ハワユ', null],
            'unspaced slash, Latin left' => ['Fate/Zero 主題歌', null, 'Fate/Zero 主題歌', null],
            'unspaced slash, digit left' => ['1/3の純情な感情', null, '1/3の純情な感情', null],
            'feat glued' => ['サリシノハラfeat.初音ミク', null, 'サリシノハラ', null],
            'feat without dot' => ['フレンズ feat Mio', null, 'フレンズ', null],
            'feat inside a word' => ['Defeat the 敵', null, 'Defeat the 敵', null],
            'ft inside a word' => ['soft 雨', null, 'soft 雨', null],
            'en dash' => ['メルト – Melt', null, 'メルト', 'Melt'],
            'em dash' => ['メルト — Melt', null, 'メルト', 'Melt'],
            // known miss, not special-cased: the emoticon's ／ splits the title
            'emoticon known miss' => ['トルコ行進曲 - オワタ＼(^o^)／', null, 'トルコ行進曲 - オワタ＼', null],
        ];
    }

    #[DataProvider('reviewSongTitles')]
    public function test_song_name_with_artist_and_edge_cases(string $name, ?string $artist, ?string $textJa, ?string $alt): void
    {
        $this->assertSame(['text_ja' => $textJa, 'alt' => $alt], TextCleaner::songName($name, $artist));
    }

    #[DataProvider('realSongTitles')]
    public function test_song_name_splits_and_strips(string $name, ?string $textJa, ?string $alt): void
    {
        $this->assertSame(['text_ja' => $textJa, 'alt' => $alt], TextCleaner::songName($name));
    }
}
