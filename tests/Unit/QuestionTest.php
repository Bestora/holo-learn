<?php

namespace Tests\Unit;

use App\Learning\Question;
use App\Models\Item;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class QuestionTest extends TestCase
{
    /** @return array<string, array{string, string, list<string>, string}> */
    public static function displayMeanings(): array
    {
        return [
            // real meaning lists from the dev DB
            'loanword: first meaning of at most three words' => ['vocab', 'アイドル', [
                'performer (usu. in a boy band or girl group) with an image cultivated to foster a dedicated fan following',
                'Japanese idol', '(cultural) icon', 'idol', 'star',
            ], 'Japanese idol'],
            'kanji 星: first, not shortest' => ['kanji', '星', ['star', 'spot', 'dot', 'mark'], 'star'],
            'vocab 星: parentheses removed' => ['vocab', '星', ['star (usu. excluding the Sun)', 'planet (usu. excluding Earth)'], 'star'],
            'radical 方' => ['radical', '方', ['direction', 'person', 'alternative'], 'direction'],
            'kanji 残' => ['kanji', '残', ['remainder', 'leftover', 'balance'], 'remainder'],
            '京: needs a letter' => ['kanji', '京', ['10**16', 'capital'], 'capital'],
            'long: cut at the first ; or ,' => ['vocab', '例', ['self-service drink area, drinks bar where you serve yourself', 'x'], 'self-service drink area'],
            'long, cut too short: kept' => ['vocab', '例', ['a, very long meaning that goes on and on without any stop', 'x'], 'a, very long meaning that goes on and on without any stop'],
            'loanword without a short meaning' => ['vocab', 'ドリンクバー', ['self-service drink area (in a restaurant)'], 'self-service drink area'],
        ];
    }

    /** @param list<string> $meanings */
    #[DataProvider('displayMeanings')]
    public function test_display_meaning(string $kind, string $characters, array $meanings, string $expected): void
    {
        $item = new Item(['kind' => $kind, 'characters' => $characters, 'readings' => [], 'meanings' => $meanings]);

        $this->assertSame($expected, Question::displayMeaning($item));
    }

    public function test_meanings_for_display_put_the_display_meaning_first(): void
    {
        $item = new Item(['kind' => 'kanji', 'characters' => '京', 'readings' => [], 'meanings' => ['10**16', 'capital', 'metropolis']]);

        $this->assertSame(['capital', '10**16', 'metropolis'], Question::meaningsForDisplay($item));
    }
}
