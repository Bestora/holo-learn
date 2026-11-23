<?php

namespace App\Japanese;

/**
 * Turns Holodex channel and song names into Japanese reference text.
 */
class TextCleaner
{
    /** Greedy (.+) → splits at the last spaced dash. */
    private const DASH = '/^(.+)\s[-–—]\s(.+)$/u';

    /**
     * Longest Japanese run before the first spaced dash, e.g.
     * "Laplus ch. ラプラス・ダークネス - holoX -" → "ラプラス・ダークネス".
     * A trailing チャンネル/ちゃんねる is dropped; runs containing ホロライブ
     * ("ホロライブ所属") are agency labels and ignored.
     * $englishName is reserved for disambiguation and is currently unused.
     */
    public static function talentName(string $channelName, string $englishName): ?string
    {
        $head = (preg_split('/\s[-‐‑‒–—―]\s/u', $channelName, 2) ?: [$channelName])[0];
        preg_match_all('/['.Kana::KANA.Kana::KANJI.'々・]+/u', $head, $m);

        $best = null;
        foreach ($m[0] as $run) {
            $run = mb_trim(preg_replace('/(チャンネル|ちゃんねる)$/u', '', $run) ?? $run, '・');
            if (! str_contains($run, 'ホロライブ') && Kana::hasJapanese($run) && mb_strlen($run) > mb_strlen($best ?? '')) {
                $best = $run;
            }
        }

        return $best;
    }

    /**
     * "フレンズ / Friends (ft. Mio) [3D Live]" → ['text_ja' => 'フレンズ', 'alt' => 'Friends'].
     *
     * - splits on "／" and on "/" with whitespace on a side; an unspaced "/" only in "アイドル/Idol" shape
     *   (Japanese without Latin left, no Japanese right), so "ハロ/ハワユ" and "1/3の純情な感情" stay whole
     * - drops 【…】 groups, trailing bracket groups and a trailing feat./ft./featuring clause
     * - "晩餐歌 - Bansanka" (also – and —) → '晩餐歌' with alt 'Bansanka' when no slash part gives one
     * - segments equal to $originalArtist (ignoring case and whitespace) are neither title nor alt
     *
     * @return array{text_ja: ?string, alt: ?string}
     */
    public static function songName(string $name, ?string $originalArtist = null): array
    {
        $isArtist = fn (string $text) => $originalArtist !== null && self::artistKey($text) === self::artistKey($originalArtist);

        $segments = [];
        foreach (preg_split('~\s+/\s*|\s*/\s+|\s*／\s*~u', mb_trim($name)) ?: [mb_trim($name)] as $part) {
            array_push($segments, ...self::splitUnspacedSlash($part));
        }

        $segments = array_map(function (string $segment) use ($isArtist) {
            $segment = self::cleanSegment($segment);
            // "Yoru ni Kakeru - YOASOBI": a trailing artist after a dash goes
            if (preg_match(self::DASH, $segment, $m) && $isArtist($m[2])) {
                $segment = mb_trim($m[1]);
            }

            return $segment;
        }, $segments);
        $segments = array_values(array_filter($segments, fn (string $segment) => ! $isArtist($segment)));

        $ja = null;
        foreach ($segments as $index => $segment) {
            if (Kana::hasJapanese($segment)) {
                $ja = $index;
                break;
            }
        }
        if ($ja === null) {
            return ['text_ja' => null, 'alt' => null];
        }

        $last = array_key_last($segments);
        $alt = $last !== $ja && $segments[$last] !== '' && ! Kana::hasJapanese($segments[$last]) ? $segments[$last] : null;

        $text = $segments[$ja];
        if (preg_match(self::DASH, $text, $m) && ! Kana::hasJapanese($m[2]) && Kana::hasJapanese($m[1])) {
            $text = mb_trim($m[1]);
            $alt ??= mb_trim($m[2]);
        }

        return ['text_ja' => $text, 'alt' => $alt];
    }

    /** @return list<string> */
    private static function splitUnspacedSlash(string $part): array
    {
        $pieces = explode('/', $part, 2);
        if (count($pieces) === 2 && Kana::hasJapanese($pieces[0]) && ! preg_match('/[A-Za-z]/', $pieces[0]) && ! Kana::hasJapanese($pieces[1])) {
            return $pieces;
        }

        return [$part];
    }

    private static function artistKey(string $text): string
    {
        return mb_strtolower(preg_replace('/\s+/u', '', $text) ?? $text);
    }

    private static function cleanSegment(string $text): string
    {
        $text = preg_replace('/【[^【】]*】/u', '', $text) ?? $text;
        $text = self::stripTrailingGroups($text);
        $text = preg_replace('/(?<![A-Za-z])(featuring|feat|ft)\.?(?![A-Za-z]).*$/iu', '', $text) ?? $text;

        return self::stripTrailingGroups($text);
    }

    /** Removes trailing "(ft. Mio)", "[3D Live]", "（歌ってみた）" groups, repeatedly. */
    private static function stripTrailingGroups(string $text): string
    {
        do {
            $text = preg_replace('/\s*(\([^()]*\)|\[[^\[\]]*\]|（[^（）]*）)\s*$/u', '', $before = mb_trim($text));
        } while ($text !== $before);

        return $text;
    }
}
