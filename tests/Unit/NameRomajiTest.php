<?php

namespace Tests\Unit;

use App\Annotations\NameRomaji;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NameRomajiTest extends TestCase
{
    /**
     * @param  list<array{0: string, 1: string}>  $pairs
     * @return list<array{surface: string, reading: string}>
     */
    private static function words(array $pairs): array
    {
        return array_map(fn (array $p) => ['surface' => $p[0], 'reading' => $p[1]], $pairs);
    }

    public function test_units_group_consecutive_kanji_words(): void
    {
        $this->assertSame([0, 0, null, 1, null], NameRomaji::units(self::words([
            ['小', 'たか'], ['鳥遊', 'なし'], ['の', 'の'], ['花', 'はな'], ['Ahoy', 'あほい'],
        ])));
    }

    /** @return array<string, array{?string, list<array{0: string, 1: string}>, ?bool}> */
    public static function cases(): array
    {
        return [
            'family first' => ['Momosuzu Nene', [['桃鈴', 'ももすず'], ['ねね', 'ねね']], true],
            'split inside a unit' => ['Momosuzu Nene', [['桃', 'もも'], ['鈴', 'すず'], ['ねね', 'ねね']], true],
            'swapped readings' => ['Momosuzu Nene', [['桃', 'すず'], ['鈴', 'もも'], ['ねね', 'ねね']], false],
            'wrong reading' => ['Momosuzu Nene', [['桃鈴', 'とうりん'], ['ねね', 'ねね']], false],
            'given name first' => ['Ao Hiodoshi', [['火威', 'ひおどし'], ['青', 'あお']], false],
            'leftover part needs a kana word' => ['Mizumiya Su', [['水宮枢', 'みずみや']], false],
            'kana gap needs a part' => ['Momosuzu', [['桃鈴', 'ももすず'], ['ねね', 'ねね']], false],
            'partial part' => ['Houshou Marine', [['宝鐘', 'しょう'], ['マリン', 'まりん']], false],
            'apostrophe and final s, two words' => ["Ninomae Ina'nis", [['一', 'にのまえ'], ['伊那尓栖', 'いなにす']], true],
            'apostrophe and final s, one word' => ["Ninomae Ina'nis", [['一伊那尓栖', 'にのまえいなにす']], true],
            'units overlap' => ['Hana', [['花', 'はな'], ['の', 'の'], ['花', 'はな']], false],
            'no kanji' => ['Houshou Marine', [['マリン', 'まりん']], null],
            'kanji without romaji' => [null, [['小鳥遊', 'たかなし']], false],
        ];
    }

    /** @param list<array{0: string, 1: string}> $pairs */
    #[DataProvider('cases')]
    public function test_matches(?string $alt, array $pairs, ?bool $expected): void
    {
        $this->assertSame($expected, NameRomaji::matches($alt, self::words($pairs)));
    }
}
