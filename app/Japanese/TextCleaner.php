<?php

namespace App\Japanese;

/**
 * Turns Holodex channel and song names into Japanese reference text.
 */
class TextCleaner
{
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
     * Splits on "/" and "／" (spaced or not), drops 【…】 groups and a trailing "feat./ft./featuring …";
     * "晩餐歌 - Bansanka" → '晩餐歌' with alt 'Bansanka' when no slash part gives one.
     *
     * @return array{text_ja: ?string, alt: ?string}
     */
    public static function songName(string $name): array
    {
        $segments = array_map(self::cleanSegment(...), preg_split('~\s*[/／]\s*~u', mb_trim($name)) ?: [mb_trim($name)]);

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
        // greedy (.+) → last " - "
        if (preg_match('/^(.+)\s-\s(.+)$/u', $text, $m) && ! Kana::hasJapanese($m[2]) && Kana::hasJapanese($m[1])) {
            $text = mb_trim($m[1]);
            $alt ??= mb_trim($m[2]);
        }

        return ['text_ja' => $text, 'alt' => $alt];
    }

    private static function cleanSegment(string $text): string
    {
        $text = preg_replace('/【[^【】]*】/u', '', $text) ?? $text;
        $text = self::stripTrailingGroups($text);
        $text = preg_replace('/\s+(feat\.|ft\.|featuring\s).*$/iu', '', $text) ?? $text;

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
