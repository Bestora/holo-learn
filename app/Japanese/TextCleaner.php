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
     * $englishName is reserved for disambiguation and is currently unused.
     */
    public static function talentName(string $channelName, string $englishName): ?string
    {
        $head = preg_split('/\s[-‐‑‒–—―]\s/u', $channelName, 2)[0];
        preg_match_all('/['.Kana::KANA.Kana::KANJI.'々・]+/u', $head, $m);

        $best = null;
        foreach ($m[0] as $run) {
            $run = mb_trim($run, '・');
            if (Kana::hasJapanese($run) && mb_strlen($run) > mb_strlen($best ?? '')) {
                $best = $run;
            }
        }

        return $best;
    }

    /**
     * "フレンズ / Friends (ft. Mio) [3D Live]" → ['text_ja' => 'フレンズ', 'alt' => 'Friends'].
     *
     * @return array{text_ja: ?string, alt: ?string}
     */
    public static function songName(string $name): array
    {
        $segments = array_map(self::stripTrailingGroups(...), preg_split('/\s+\/\s+/u', mb_trim($name)));

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

        return ['text_ja' => $segments[$ja], 'alt' => $alt];
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
