<?php

namespace App\Japanese;

/**
 * Kana helpers: script conversion, romaji in both directions and a loose
 * comparison for grading. Pure static, multibyte-safe, never throws.
 */
class Kana
{
    /**
     * Romaji → hiragana. Order matters: the first spelling of a kana is its
     * Hepburn form and is what toRomaji() emits.
     */
    private const ROMAJI = [
        'a' => 'あ', 'i' => 'い', 'u' => 'う', 'e' => 'え', 'o' => 'お',
        'ka' => 'か', 'ki' => 'き', 'ku' => 'く', 'ke' => 'け', 'ko' => 'こ',
        'sa' => 'さ', 'shi' => 'し', 'si' => 'し', 'su' => 'す', 'se' => 'せ', 'so' => 'そ',
        'ta' => 'た', 'chi' => 'ち', 'ti' => 'ち', 'tsu' => 'つ', 'tu' => 'つ', 'te' => 'て', 'to' => 'と',
        'na' => 'な', 'ni' => 'に', 'nu' => 'ぬ', 'ne' => 'ね', 'no' => 'の',
        'ha' => 'は', 'hi' => 'ひ', 'fu' => 'ふ', 'hu' => 'ふ', 'he' => 'へ', 'ho' => 'ほ',
        'ma' => 'ま', 'mi' => 'み', 'mu' => 'む', 'me' => 'め', 'mo' => 'も',
        'ya' => 'や', 'yu' => 'ゆ', 'yo' => 'よ',
        'ra' => 'ら', 'ri' => 'り', 'ru' => 'る', 're' => 'れ', 'ro' => 'ろ',
        'wa' => 'わ', 'wo' => 'を',
        'ga' => 'が', 'gi' => 'ぎ', 'gu' => 'ぐ', 'ge' => 'げ', 'go' => 'ご',
        'za' => 'ざ', 'ji' => 'じ', 'zi' => 'じ', 'zu' => 'ず', 'ze' => 'ぜ', 'zo' => 'ぞ',
        'da' => 'だ', 'dzu' => 'づ', 'de' => 'で', 'do' => 'ど',
        'ba' => 'ば', 'bi' => 'び', 'bu' => 'ぶ', 'be' => 'べ', 'bo' => 'ぼ',
        'pa' => 'ぱ', 'pi' => 'ぴ', 'pu' => 'ぷ', 'pe' => 'ぺ', 'po' => 'ぽ',
        'kya' => 'きゃ', 'kyu' => 'きゅ', 'kyo' => 'きょ',
        'gya' => 'ぎゃ', 'gyu' => 'ぎゅ', 'gyo' => 'ぎょ',
        'sha' => 'しゃ', 'shu' => 'しゅ', 'sho' => 'しょ', 'she' => 'しぇ',
        'sya' => 'しゃ', 'syu' => 'しゅ', 'syo' => 'しょ',
        'ja' => 'じゃ', 'ju' => 'じゅ', 'jo' => 'じょ', 'je' => 'じぇ',
        'jya' => 'じゃ', 'jyu' => 'じゅ', 'jyo' => 'じょ',
        'zya' => 'じゃ', 'zyu' => 'じゅ', 'zyo' => 'じょ',
        'cha' => 'ちゃ', 'chu' => 'ちゅ', 'cho' => 'ちょ', 'che' => 'ちぇ',
        'tya' => 'ちゃ', 'tyu' => 'ちゅ', 'tyo' => 'ちょ',
        'nya' => 'にゃ', 'nyu' => 'にゅ', 'nyo' => 'にょ',
        'hya' => 'ひゃ', 'hyu' => 'ひゅ', 'hyo' => 'ひょ',
        'mya' => 'みゃ', 'myu' => 'みゅ', 'myo' => 'みょ',
        'rya' => 'りゃ', 'ryu' => 'りゅ', 'ryo' => 'りょ',
        'bya' => 'びゃ', 'byu' => 'びゅ', 'byo' => 'びょ',
        'pya' => 'ぴゃ', 'pyu' => 'ぴゅ', 'pyo' => 'ぴょ',
        'fa' => 'ふぁ', 'fi' => 'ふぃ', 'fe' => 'ふぇ', 'fo' => 'ふぉ',
        'di' => 'でぃ', 'wi' => 'うぃ', 'we' => 'うぇ',
        'va' => 'ゔぁ', 'vi' => 'ゔぃ', 'vu' => 'ゔ', 've' => 'ゔぇ', 'vo' => 'ゔぉ',
        'la' => 'ら', 'li' => 'り', 'lu' => 'る', 'le' => 'れ', 'lo' => 'ろ', // Balalaika
        'lya' => 'りゃ', 'lyu' => 'りゅ', 'lyo' => 'りょ',
    ];

    /**
     * toRomaji()-only spellings; they override the inverted ROMAJI table
     * (づ→zu, not dzu; てぃ→ti although fromRomaji('ti') is ち).
     */
    private const EXTRA_KANA = [
        'づ' => 'zu', 'ぢ' => 'ji', 'ゐ' => 'i', 'ゑ' => 'e',
        'てぃ' => 'ti', 'とぅ' => 'tu', 'でゅ' => 'dyu', 'どぅ' => 'du', 'てゅ' => 'tyu', 'ふゅ' => 'fyu',
        'うぉ' => 'wo', 'いぇ' => 'ye', 'つぁ' => 'tsa', 'つぃ' => 'tsi', 'つぇ' => 'tse', 'つぉ' => 'tso',
        'ぁ' => 'a', 'ぃ' => 'i', 'ぅ' => 'u', 'ぇ' => 'e', 'ぉ' => 'o',
        'ゃ' => 'ya', 'ゅ' => 'yu', 'ょ' => 'yo', 'ゎ' => 'wa', 'ゕ' => 'ka', 'ゖ' => 'ke',
    ];

    /**
     * Regex character-class bodies. Explicit ranges because PCRE's \p{Han} /
     * \p{Katakana} use script extensions and match ・、「 as well.
     */
    public const KANA = '\x{3041}-\x{3096}\x{3099}\x{309A}\x{309D}\x{309E}\x{30A1}-\x{30FA}\x{30FC}-\x{30FE}\x{31F0}-\x{31FF}\x{FF66}-\x{FF9F}';

    public const KANJI = '\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{F900}-\x{FAFF}\x{20000}-\x{2FA1F}';

    private const MACRONS = ['ā' => 'aa', 'ī' => 'ii', 'ū' => 'uu', 'ē' => 'ee', 'ō' => 'ou'];

    public static function toHiragana(string $text): string
    {
        // Half-width → full-width katakana first, then shift the katakana block.
        $text = mb_convert_kana($text, 'KV', 'UTF-8');

        return preg_replace_callback('/[\x{30A1}-\x{30F6}]/u', fn ($m) => mb_chr(mb_ord($m[0]) - 0x60), $text);
    }

    public static function fromRomaji(string $romaji): string
    {
        $s = strtr(mb_strtolower($romaji), self::MACRONS);
        // IME-style nn (not before a vowel/y), n' and n before a word boundary
        // are always ん; then drop non-letters.
        $s = preg_replace("/nn(?![aiueoy])|n['’]|n(?=[^\\p{L}]|$)/u", 'ん', $s);
        $s = preg_replace('/[^\p{L}]+/u', '', $s);

        $chars = mb_str_split($s);
        $count = count($chars);
        $out = '';

        for ($i = 0; $i < $count;) {
            $c = $chars[$i];
            $next = $chars[$i + 1] ?? '';

            // Doubled consonant (and Hepburn "tch") → っ.
            if (($c === $next && preg_match('/^[bcdfghjklpqrstvwxyz]$/', $c)) || ($c === 't' && $next === 'c')) {
                $out .= 'っ';
                $i++;

                continue;
            }

            foreach ([3, 2, 1] as $len) {
                $chunk = implode('', array_slice($chars, $i, $len));
                if (isset(self::ROMAJI[$chunk])) {
                    $out .= self::ROMAJI[$chunk];
                    $i += $len;

                    continue 2;
                }
            }

            if (($c === 'n' && preg_match('/^[bcdfghjklmnpqrstvwxz]$/', $next)) || ($c === 'm' && in_array($next, ['b', 'p', 'm'], true))) {
                $out .= 'ん';
            } else {
                $out .= $c; // unconvertible: keep so a later comparison fails
            }
            $i++;
        }

        return $out;
    }

    public static function toRomaji(string $kana): string
    {
        static $map = null;
        $map ??= self::EXTRA_KANA + array_flip(array_reverse(self::ROMAJI));

        $chars = mb_str_split(self::toHiragana($kana));
        $count = count($chars);
        $out = '';
        $sokuon = false;
        $afterN = false;

        for ($i = 0; $i < $count;) {
            $c = $chars[$i];

            if ($c === 'っ') {
                $sokuon = true;
                $i++;

                continue;
            }
            if ($c === 'ー') {
                if (preg_match('/[aeiou]$/', $out, $m)) {
                    $out .= $m[0];
                }
                $sokuon = $afterN = false;
                $i++;

                continue;
            }
            if ($c === 'ん') {
                $out .= 'n';
                $sokuon = false;
                $afterN = true;
                $i++;

                continue;
            }
            if ($c === '・' || preg_match('/^\s$/u', $c)) {
                $out = rtrim($out, ' ').' ';
                $sokuon = $afterN = false;
                $i++;

                continue;
            }

            $pair = $c.($chars[$i + 1] ?? '');
            [$syllable, $len] = isset($map[$pair]) ? [$map[$pair], 2] : [$map[$c] ?? null, 1];

            if ($syllable === null) {
                $out .= $c; // not kana: pass through
            } else {
                if ($afterN && preg_match('/^[aeiouy]/', $syllable)) {
                    $out .= "'";
                }
                if ($sokuon) {
                    $out .= str_starts_with($syllable, 'ch') ? 't' : $syllable[0];
                }
                $out .= $syllable;
            }
            $sokuon = $afterN = false;
            $i += $len;
        }

        return trim($out);
    }

    /** Comparison form only, never for display: long vowels collapsed. */
    public static function loose(string $text): string
    {
        $s = strtr(preg_replace('/[\s・ー]+/u', '', self::toHiragana($text)), ['づ' => 'ず', 'ぢ' => 'じ']);

        // ponytail: collapses by vowel row (こう→こ, せい→せ, きゅう→きゅ), a superset of the
        // brief's literal おう/おお/えい/うう/ゅう/ょう. Narrow to literals if too lenient.
        return preg_replace([
            '/([おこそとのほもよろをごぞどぼぽょぉ])[うお]+/u',
            '/([うくすつぬふむゆるぐずづぶぷゅぅゔ])う+/u',
            '/([えけせてねへめれげぜでべぺぇ])い+/u',
        ], '$1', $s);
    }

    public static function looselyEqual(string $a, string $b): bool
    {
        return self::loose($a) === self::loose($b);
    }

    /**
     * Whether a reading agrees with the kana of its romanization: every romaji kana present
     * in order, and the only extra kana are long vowels romaji commonly omits — one directly
     * after a reading kana that matched and was not itself a long vowel: a ー vowel, う after
     * an o/u-row kana or い after an e-row kana (きりゅう for Kiryu, ばー for Ba ok;
     * ほしょう for Houshou, とおこやみ for Tokoyami, おおうかみ for Ookami not).
     * A ー vowel also matches a long vowel the romaji spells (ちょー for Chou).
     */
    public static function matchesRomaji(string $reading, string $romajiKana): bool
    {
        $romaji = array_column(self::spelledOut($romajiKana), 0);
        $k = 0;
        $previous = null;  // last reading kana
        $canAdd = false;   // previous kana matched and was not a long vowel
        foreach (self::spelledOut($reading) as [$char, $fromBar]) {
            $long = $previous !== null && self::lengthens($previous, $char);
            $expected = $romaji[$k] ?? null;
            if ($char === $expected || ($fromBar && $expected !== null && $previous !== null && self::lengthens($previous, $expected))) {
                $k++;
                $canAdd = ! $long;
            } elseif ($canAdd && ($fromBar || ($long && ($char === 'う' || ($char === 'い' && str_ends_with(self::toRomaji($previous), 'e')))))) {
                $canAdd = false; // romaji omitted this long vowel; at most one, never chained
            } else {
                return false;
            }
            $previous = $char;
        }

        return $k === count($romaji);
    }

    /** Whether $vowel lengthens $kana: あ after a-row, い after i/e-row, う after u/o-row, え after e-row, お after o-row. */
    private static function lengthens(string $kana, string $vowel): bool
    {
        $allowed = ['a' => ['あ'], 'i' => ['い'], 'u' => ['う'], 'e' => ['い', 'え'], 'o' => ['う', 'お']];

        return in_array($vowel, $allowed[substr(self::toRomaji($kana), -1)] ?? [], true);
    }

    /**
     * Hiragana as [kana, came from ー] pairs: separators removed, づ/ぢ as ず/じ, small
     * vowels full-size, ー as the vowel of the kana before it.
     *
     * @return list<array{string, bool}>
     */
    private static function spelledOut(string $kana): array
    {
        $kana = strtr(preg_replace('/[\s・]+/u', '', self::toHiragana($kana)) ?? '', [
            'づ' => 'ず', 'ぢ' => 'じ', 'ぁ' => 'あ', 'ぃ' => 'い', 'ぅ' => 'う', 'ぇ' => 'え', 'ぉ' => 'お',
        ]);
        $vowels = ['a' => 'あ', 'i' => 'い', 'u' => 'う', 'e' => 'え', 'o' => 'お'];
        $out = [];
        foreach (mb_str_split($kana) as $char) {
            $previous = end($out);
            if ($char === 'ー' && $previous !== false && isset($vowels[substr(self::toRomaji($previous[0]), -1)])) {
                $out[] = [$vowels[substr(self::toRomaji($previous[0]), -1)], true];
            } elseif ($char !== 'ー') {
                $out[] = [$char, false];
            }
        }

        return $out;
    }

    public static function isKanaOnly(string $text): bool
    {
        return preg_match('/^['.self::KANA.']+$/u', $text) === 1;
    }

    public static function hasJapanese(string $text): bool
    {
        return preg_match('/['.self::KANA.self::KANJI.'々]/u', $text) === 1;
    }

    /**
     * Kanji in order of appearance, duplicates kept, 々 excluded.
     *
     * @return list<string>
     */
    public static function kanjiIn(string $text): array
    {
        preg_match_all('/['.self::KANJI.']/u', $text, $m);

        return $m[0];
    }
}
