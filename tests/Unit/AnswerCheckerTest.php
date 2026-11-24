<?php

namespace Tests\Unit;

use App\Learning\AnswerChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AnswerCheckerTest extends TestCase
{
    #[DataProvider('kanaInputs')]
    public function test_kana_reading_accepts_romaji_and_kana_in_any_case(string $input): void
    {
        $this->assertTrue(AnswerChecker::reading($input, ['ne']));
    }

    /** @return array<string, array{string}> */
    public static function kanaInputs(): array
    {
        return ['upper' => ['NE'], 'trailing space' => ['ne '], 'hiragana' => ['ね'], 'katakana' => ['ネ'], 'inner space' => ['n e']];
    }

    public function test_shi_and_si_are_both_shi(): void
    {
        $this->assertTrue(AnswerChecker::reading('shi', ['shi']));
        $this->assertTrue(AnswerChecker::reading('si', ['shi']));
        $this->assertFalse(AnswerChecker::reading('su', ['shi']));
    }

    public function test_romaji_typed_for_a_kanji_reading(): void
    {
        $this->assertTrue(AnswerChecker::reading('hana', ['はな']));
        $this->assertTrue(AnswerChecker::reading('Takanashi', ['たかなし']));
        $this->assertFalse(AnswerChecker::reading('bana', ['はな']));
    }

    public function test_a_wrong_long_vowel_is_wrong(): void
    {
        $this->assertFalse(AnswerChecker::reading('しゅじん', ['しゅうじん']));
        $this->assertFalse(AnswerChecker::reading('shujin', ['しゅうじん']));
        $this->assertFalse(AnswerChecker::reading('kyo', ['きょう']));
        $this->assertFalse(AnswerChecker::reading('boorei', ['ぼうれい'])); // おお is not おう
        $this->assertFalse(AnswerChecker::reading('ぼーれー', ['ぼうれい']));
        $this->assertFalse(AnswerChecker::reading('こうひい', ['こーひー'])); // ー is the same vowel again, not う
    }

    public function test_correct_readings_in_romaji_or_kana(): void
    {
        $this->assertTrue(AnswerChecker::reading('shuujin', ['しゅうじん']));
        $this->assertTrue(AnswerChecker::reading('syuujin', ['しゅうじん']));
        $this->assertTrue(AnswerChecker::reading('shūjin', ['しゅうじん']));
        $this->assertTrue(AnswerChecker::reading('シュウジン', ['しゅうじん']));
        $this->assertTrue(AnswerChecker::reading('kyou', ['きょう']));
        $this->assertTrue(AnswerChecker::reading(' Bourei ', ['ぼうれい']));
    }

    public function test_long_vowel_mark_equals_the_same_vowel_repeated(): void
    {
        $this->assertTrue(AnswerChecker::reading('ko-hi-', ['こーひー']));
        $this->assertTrue(AnswerChecker::reading('koohii', ['こーひー']));
        $this->assertTrue(AnswerChecker::reading('コーヒー', ['こーひー']));
        $this->assertTrue(AnswerChecker::reading('こおひい', ['こーひー']));
        $this->assertTrue(AnswerChecker::reading('こーひー', ['こおひい']));
        $this->assertFalse(AnswerChecker::reading('kouhii', ['こーひー']));
        $this->assertFalse(AnswerChecker::reading('kohi', ['こーひー']));
    }

    public function test_zu_and_ji_spellings_are_strict_in_kana_only(): void
    {
        $this->assertFalse(AnswerChecker::reading('つずく', ['つづく']));
        $this->assertFalse(AnswerChecker::reading('ちじむ', ['ちぢむ']));
        $this->assertFalse(AnswerChecker::reading('ちぢむ', ['ちじむ']));
        $this->assertTrue(AnswerChecker::reading('つづく', ['つづく']));
        $this->assertTrue(AnswerChecker::reading('tsuzuku', ['つづく'])); // Hepburn writes づ as zu
        $this->assertTrue(AnswerChecker::reading('chijimu', ['ちぢむ']));
        $this->assertFalse(AnswerChecker::reading('tsutsuku', ['つづく']));
    }

    public function test_kana_items_stay_loose_in_romaji(): void
    {
        $this->assertTrue(AnswerChecker::reading('zu', ['zu'])); // づ
        $this->assertTrue(AnswerChecker::reading('dzu', ['zu']));
        $this->assertTrue(AnswerChecker::reading('ji', ['ji'])); // ぢ
    }

    public function test_empty_reading_is_wrong(): void
    {
        $this->assertFalse(AnswerChecker::reading('  ', ['ne']));
    }

    public function test_meaning_tolerates_small_typos_and_plurals(): void
    {
        $this->assertTrue(AnswerChecker::meaning('ghost', ['ghosts']));
        $this->assertTrue(AnswerChecker::meaning('Ghost', ['ghost', 'departed spirit']));
        $this->assertTrue(AnswerChecker::meaning('departed sprit', ['ghost', 'departed spirit'])); // ≥ 8 chars: 2 edits allowed
        $this->assertTrue(AnswerChecker::meaning('univrese', ['universe'])); // 2 edits, 8 chars
        $this->assertFalse(AnswerChecker::meaning('flwr', ['flower'])); // 2 edits, short
    }

    /** @return array<string, array{string, list<string>}> */
    public static function differentWords(): array
    {
        return [
            'now ≠ no' => ['no', ['now']],
            'no ≠ now' => ['now', ['no']],
            'I ≠ x' => ['x', ['ego', 'I', 'selfish', 'our', 'oneself']],
            'I ≠ it' => ['it', ['ego', 'I']],
            'I ≠ in' => ['in', ['I']],
            'I ≠ is' => ['is', ['I']],
            'I ≠ if' => ['if', ['I']],
            'sun ≠ son' => ['son', ['day', 'sun']],
            'bug ≠ big' => ['big', ['bug']],
            'bug ≠ bag' => ['bag', ['bug']],
            'eye ≠ dye' => ['dye', ['eye']],
            'eye ≠ bye' => ['bye', ['eye']],
        ];
    }

    /** @param list<string> $meanings */
    #[DataProvider('differentWords')]
    public function test_short_meanings_need_an_exact_answer(string $input, array $meanings): void
    {
        $this->assertFalse(AnswerChecker::meaning($input, $meanings));
    }

    public function test_exact_answers_are_always_right(): void
    {
        $this->assertTrue(AnswerChecker::meaning('I', ['ego', 'I']));
        $this->assertTrue(AnswerChecker::meaning('now', ['now'], ['no' => true]));
        $this->assertTrue(AnswerChecker::meaning('Sun', ['day', 'sun']));
    }

    public function test_tolerance_by_length(): void
    {
        $this->assertTrue(AnswerChecker::meaning('horse', ['horses']));   // 6 chars: 1 edit
        $this->assertFalse(AnswerChecker::meaning('hose', ['horses']));   // 2 edits
        $this->assertFalse(AnswerChecker::meaning('stars', ['star']));    // 4 chars: exact only
    }

    public function test_a_fuzzy_match_that_is_another_items_meaning_is_wrong(): void
    {
        $this->assertTrue(AnswerChecker::meaning('house', ['horse']));
        $this->assertFalse(AnswerChecker::meaning('house', ['horse'], ['house' => true]));
        $this->assertFalse(AnswerChecker::meaning('a House', ['horse'], ['house' => true])); // compared normalised
    }

    public function test_meaning_ignores_articles_to_and_parentheses(): void
    {
        $this->assertTrue(AnswerChecker::meaning('to love', ['love']));
        $this->assertTrue(AnswerChecker::meaning('love', ['to love']));
        $this->assertTrue(AnswerChecker::meaning('an idol', ['idol']));
        $this->assertTrue(AnswerChecker::meaning('icon', ['(cultural) icon']));
        $this->assertTrue(AnswerChecker::meaning('lei', ['lei (garland of flowers)']));
        $this->assertTrue(AnswerChecker::meaning('  pirate   crew ', ['pirate crew']));
    }

    public function test_empty_and_far_off_meanings_are_wrong(): void
    {
        $this->assertFalse(AnswerChecker::meaning('', ['ghost']));
        $this->assertFalse(AnswerChecker::meaning('the', ['ghost'])); // nothing left after normalising
        $this->assertFalse(AnswerChecker::meaning('banana', ['ghost']));
    }

    public function test_normalize_meaning(): void
    {
        $this->assertSame('icon', AnswerChecker::normalizeMeaning(' (Cultural)  Icon '));
        $this->assertSame('sing', AnswerChecker::normalizeMeaning('To sing'));
    }
}
