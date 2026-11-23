<?php

namespace Tests\Unit;

use App\Learning\ItemLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ItemLevelTest extends TestCase
{
    public function test_kana(): void
    {
        $this->assertSame([0, false], ItemLevel::kana('hiragana'));
        $this->assertSame([1, false], ItemLevel::kana('katakana'));
    }

    /** @return array<string, array{?int, ?int, array{int, bool}}> */
    public static function kanjiCases(): array
    {
        return [
            'N5 (old 4) 日' => [4, 1, [10, false]],
            'old 3' => [3, 2, [11, false]],
            'old 2' => [2, 6, [12, false]],
            'old 1 beats grade 9 綺' => [1, 9, [13, false]],
            'grade 1 without jlpt' => [null, 1, [21, false]],
            'grade 6 without jlpt' => [null, 6, [26, false]],
            'grade 8 without jlpt' => [null, 8, [28, false]],
            'name kanji grade 9 湊' => [null, 9, [40, true]],
            'name kanji grade 10' => [null, 10, [40, true]],
            'no data 戌' => [null, null, [40, true]],
        ];
    }

    /** @param array{int, bool} $expected */
    #[DataProvider('kanjiCases')]
    public function test_kanji(?int $jlpt, ?int $grade, array $expected): void
    {
        $this->assertSame($expected, ItemLevel::kanji($jlpt, $grade));
    }

    public function test_loanword_vocab(): void
    {
        $this->assertSame([2, false], ItemLevel::vocab(true, []));
        $this->assertSame([3, false], ItemLevel::vocab(false, [])); // early vocabulary, never special
    }

    public function test_kanji_vocab_takes_highest_kanji_level(): void
    {
        $this->assertSame([13, false], ItemLevel::vocab(true, [[12, false], [13, false]]));
        $this->assertSame([13, true], ItemLevel::vocab(false, [[12, false], [13, false]])); // not common
        $this->assertSame([40, true], ItemLevel::vocab(true, [[10, false], [40, true]])); // special kanji
    }

    public function test_radical_takes_lowest_kanji_level(): void
    {
        $this->assertSame([10, false], ItemLevel::radical([[13, false], [10, false]]));
        $this->assertSame([21, false], ItemLevel::radical([[40, true], [21, false]])); // one basic kanji suffices
        $this->assertSame([40, true], ItemLevel::radical([[40, true], [40, true]]));
        $this->assertSame([40, true], ItemLevel::radical([]));
    }
}
