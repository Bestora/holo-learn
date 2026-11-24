<?php

namespace App\Learning;

use App\Japanese\Kana;

/** Grades typed answers. Pure. */
class AnswerChecker
{
    /**
     * Exact, so a wrong long vowel (しゅじん for しゅうじん, kou for こ) is wrong. Only ー equals the
     * same vowel repeated (こーひー = こおひい); romaji, which cannot tell づ/ぢ from ず/じ, gets those
     * two pairs merged, and a macron may stand for any spelling of its long vowel (ō: ou, oo, o-).
     *
     * @param  list<string>  $expected  kana readings, or Hepburn romaji for kana items
     */
    public static function reading(string $input, array $expected): bool
    {
        $input = mb_trim($input);
        if ($input === '') {
            return false;
        }

        $compact = mb_strtolower(preg_replace('/\s+/u', '', $input) ?? '');
        $romaji = self::asKana($compact) !== $compact;
        $inputs = array_map(fn (string $spelling) => self::exactKey(self::asKana($spelling), $romaji), self::macronSpellings($compact));
        // kana items expect Hepburn: any romaji spelling of that kana (shi/si, fu/hu, n/nn), nothing longer
        $kanaItemInputs = array_map(fn (string $spelling) => self::exactKey(self::asKana($spelling), true), self::macronSpellings($compact));

        foreach ($expected as $reading) {
            $matches = preg_match('/[a-z]/i', $reading) === 1
                ? in_array(self::exactKey(self::asKana(mb_strtolower($reading)), true), $kanaItemInputs, true)
                : in_array(self::exactKey($reading, $romaji), $inputs, true);
            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every spelling a macron answer may mean (kōhī: kouhii, koohii, ko-hii, …); without macrons only itself.
     *
     * @return list<string>
     */
    private static function macronSpellings(string $romaji): array
    {
        $options = ['ā' => ['aa', 'a-'], 'ī' => ['ii', 'i-'], 'ū' => ['uu', 'u-'], 'ē' => ['ei', 'ee', 'e-'], 'ō' => ['ou', 'oo', 'o-']];
        if (preg_match_all('/[āīūēō]/u', $romaji) > 6) {
            return [$romaji]; // 3^n spellings: no answer has that many long vowels
        }
        $spellings = [''];
        foreach (mb_str_split($romaji) as $char) {
            $spellings = array_merge(...array_map(fn (string $s) => array_map(fn (string $o) => $s.$o, $options[$char] ?? [$char]), $spellings));
        }

        return $spellings;
    }

    /** Hiragana with every ー spelled as the vowel before it; づ/ぢ as ず/じ when compared with romaji. */
    private static function exactKey(string $kana, bool $romaji): string
    {
        $vowels = ['a' => 'あ', 'i' => 'い', 'u' => 'う', 'e' => 'え', 'o' => 'お'];
        $out = '';
        foreach (mb_str_split(Kana::toHiragana($kana)) as $char) {
            $out .= $char === 'ー' && $out !== '' ? $vowels[substr(Kana::toRomaji(mb_substr($out, -1)), -1)] ?? $char : $char;
        }

        return $romaji ? strtr($out, ['づ' => 'ず', 'ぢ' => 'じ']) : $out;
    }

    /**
     * Exact (normalised) match, or a typo: no tolerance up to 4 characters, 1 edit up to 7, else 2.
     * A typo match that spells another item's meaning (`son` for 'sun') is not one.
     *
     * @param  list<string>  $meanings
     * @param  array<string, true>  $otherMeanings  normalised meanings of all other items
     */
    public static function meaning(string $input, array $meanings, array $otherMeanings = []): bool
    {
        $input = self::normalizeMeaning($input);
        if ($input === '') {
            return false;
        }

        $normalized = array_values(array_filter(array_map(self::normalizeMeaning(...), $meanings), fn (string $m) => $m !== ''));
        if (in_array($input, $normalized, true)) {
            return true;
        }
        if (isset($otherMeanings[$input])) {
            return false;
        }
        foreach ($normalized as $meaning) {
            $length = mb_strlen($meaning);
            if ($length > 4 && levenshtein($input, $meaning) <= ($length >= 8 ? 2 : 1)) {
                return true;
            }
        }

        return false;
    }

    /** Lowercase, parenthesised parts and a leading to/a/an/the removed, spaces collapsed. */
    public static function normalizeMeaning(string $meaning): string
    {
        $s = mb_strtolower(preg_replace('/\([^)]*\)/u', ' ', $meaning) ?? '');
        $s = mb_trim(preg_replace('/\s+/u', ' ', $s) ?? '');

        return preg_replace('/^(to|a|an|the)(\s+|$)/u', '', $s) ?? '';
    }

    /** Comparison key for a reading option: kana (romaji converted), long vowels collapsed. */
    public static function readingKey(string $reading): string
    {
        return Kana::loose(self::asKana($reading));
    }

    /** Romaji → kana, a typed - as ー (ko-hi- → こーひー). */
    private static function asKana(string $text): string
    {
        return preg_match('/[a-z]/i', $text) === 1 ? Kana::fromRomaji(str_replace('-', 'ー', $text)) : $text;
    }
}
