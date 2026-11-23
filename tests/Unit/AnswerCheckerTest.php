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

    public function test_long_vowels_are_loose(): void
    {
        $this->assertTrue(AnswerChecker::reading('kyo', ['きょう']));
        $this->assertTrue(AnswerChecker::reading('boorei', ['ぼうれい']));
        $this->assertTrue(AnswerChecker::reading('ぼーれー', ['ぼうれい']));
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
