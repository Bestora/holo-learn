<?php

namespace Tests\Unit;

use App\Japanese\Kana;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class KanaTest extends TestCase
{
    public function test_brief_cases(): void
    {
        $this->assertTrue(Kana::looselyEqual(Kana::fromRomaji('Momosuzu Nene'), 'ももすずねね'));
        $this->assertTrue(Kana::looselyEqual(Kana::fromRomaji('Kyu-Kurarin'), 'きゅうくらりん'));
        $this->assertTrue(Kana::looselyEqual(Kana::fromRomaji('AMATTARU'), 'あまったる'));
        $this->assertTrue(Kana::looselyEqual(Kana::fromRomaji('Hai Yorokonde'), 'はいよろこんで'));
        $this->assertTrue(Kana::looselyEqual(Kana::fromRomaji('Uchiagehanabi'), 'うちあげはなび'));
        $this->assertFalse(Kana::looselyEqual(Kana::fromRomaji('Fake Meme'), 'うそみーむ'));
        $this->assertTrue(Kana::looselyEqual('ネネ', 'ねね'));
        $this->assertSame(['桃', '鈴'], Kana::kanjiIn('桃鈴ねね'));
    }

    #[DataProvider('toRomajiCases')]
    public function test_to_romaji(string $kana, string $romaji): void
    {
        $this->assertSame($romaji, Kana::toRomaji($kana));
    }

    public static function toRomajiCases(): array
    {
        return [
            // brief
            ['はなにぼうれい', 'hananibourei'],
            ['きゅうくらりん', 'kyuukurarin'],
            ['あまったる', 'amattaru'],
            ['ネネ', 'nene'],
            ['ラプラス・ダークネス', 'rapurasu daakunesu'],
            // rulings
            ['まっちゃ', 'matcha'],
            ['こんや', "kon'ya"],
            ['きんえん', "kin'en"],
            ['しんぶん', 'shinbun'],
            ['ちつふじを', 'chitsufujiwo'],
            ['シャチョー', 'shachoo'],
            ['ヴァイオレット', 'vaioretto'],
            ['ねね  ・ 　ぺこら', 'nene pekora'],
            ['桃鈴ねね!', '桃鈴nene!'],
            ['', ''],
        ];
    }

    #[DataProvider('fromRomajiCases')]
    public function test_from_romaji(string $romaji, string $kana): void
    {
        $this->assertSame($kana, Kana::fromRomaji($romaji));
    }

    public static function fromRomajiCases(): array
    {
        return [
            ['shi si chi ti tsu tu fu hu ji zi', 'ししちちつつふふじじ'],
            ['kitte', 'きって'],
            ['matcha', 'まっちゃ'],
            ['onna', 'おんな'],
            ['shinbun', 'しんぶん'],
            ['shimbun', 'しんぶん'],
            ['kon\'ya', 'こんや'],
            ['konya', 'こにゃ'],
            ['kin\'en', 'きんえん'],
            ['kin en', 'きんえん'],
            ['Shin’ya', 'しんや'],
            ['Hōshō', 'ほうしょう'],
            ['tsūkā', 'つうかあ'],
            ['sha shu sho cha chu cho ja ju jo', 'しゃしゅしょちゃちゅちょじゃじゅじょ'],
            ['sya tya zya jya kyo', 'しゃちゃじゃじゃきょ'],
            ['dzu wo o', 'づをお'],
            ['fa fi fe fo di we wi', 'ふぁふぃふぇふぉでぃうぇうぃ'],
            ['va vi vu ve vo', 'ゔぁゔぃゔゔぇゔぉ'],
            ['Ridin\' on Dreams', 'りでぃんおんdれあms'],
            ['qxl', 'qxl'],
            ['Kyu-123-Ku!', 'きゅく'],
            ['', ''],
        ];
    }

    public function test_to_hiragana(): void
    {
        $this->assertSame('らぷらす・だーくねす', Kana::toHiragana('ラプラス・ダークネス'));
        $this->assertSame('ゔぁ', Kana::toHiragana('ヴァ'));
        $this->assertSame('ねね', Kana::toHiragana('ﾈﾈ'));
        $this->assertSame('桃鈴ねね', Kana::toHiragana('桃鈴ネネ'));
        $this->assertSame('', Kana::toHiragana(''));
    }

    public function test_loose(): void
    {
        $this->assertSame('きゅくらりん', Kana::loose('キュー クラリン'));
        $this->assertSame('ほしょ', Kana::loose('ほうしょう'));
        $this->assertSame('お', Kana::loose('おお'));
        $this->assertSame('せんせ', Kana::loose('せんせい'));
        $this->assertSame('らぷらすだくねす', Kana::loose('ラプラス・ダークネス'));
        $this->assertSame('', Kana::loose(''));
        $this->assertTrue(Kana::looselyEqual(Kana::fromRomaji('Tokyo'), 'とうきょう'));
        $this->assertFalse(Kana::looselyEqual(Kana::fromRomaji('qxl'), 'くぇ'));
        $this->assertTrue(Kana::looselyEqual('', ''));
    }

    public function test_character_classes(): void
    {
        $this->assertTrue(Kana::isKanaOnly('ねねネネー'));
        $this->assertTrue(Kana::isKanaOnly('きゅう'));
        $this->assertFalse(Kana::isKanaOnly('桃鈴ねね'));
        $this->assertFalse(Kana::isKanaOnly('ラプラス・ダークネス'));
        $this->assertFalse(Kana::isKanaOnly('ねね '));
        $this->assertFalse(Kana::isKanaOnly(''));

        $this->assertTrue(Kana::hasJapanese('Nene Ch.桃鈴ねね'));
        $this->assertTrue(Kana::hasJapanese('トワ'));
        $this->assertFalse(Kana::hasJapanese('Suisei Channel'));
        $this->assertFalse(Kana::hasJapanese(''));
        $this->assertFalse(Kana::hasJapanese('Hi、「・」'));

        $this->assertSame(['佐', '佐', '木'], Kana::kanjiIn('佐々佐木ー'));
        $this->assertSame([], Kana::kanjiIn('ねね'));
        $this->assertSame([], Kana::kanjiIn(''));
    }
}
