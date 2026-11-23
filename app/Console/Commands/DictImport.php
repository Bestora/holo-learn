<?php

namespace App\Console\Commands;

use App\Models\DictKanji;
use App\Models\DictWord;
use Generator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class DictImport extends Command
{
    protected $signature = 'dict:import {dir : Verzeichnis mit den JSON-Dateien von jmdict-simplified}';

    protected $description = 'Importiert JMdict, JMnedict, Kanjidic2 und Kradfile (jmdict-simplified) in dict_words und dict_kanji';

    private const FILES = [
        'jmdict' => 'jmdict-eng-*.json',
        'jmnedict' => 'jmnedict-*.json',
        'kanjidic' => 'kanjidic2-en-*.json',
        'kradfile' => 'kradfile-*.json',
    ];

    /** Kradfile writes radicals missing from JIS X 0208 as a kanji containing them. */
    private const KRAD_SUBSTITUTES = [
        '化' => '亻', '个' => '𠆢', '并' => '丷', '刈' => '刂', '込' => '辶', '尚' => '⺌',
        '忙' => '忄', '扎' => '扌', '汁' => '氵', '犯' => '犭', '艾' => '艹', '邦' => '阝',
        '阡' => '阝', '老' => '耂', '杰' => '灬', '礼' => '礻', '疔' => '疒', '禹' => '禸',
        '初' => '衤', '買' => '罒', '滴' => '啇', '乞' => '𠂉',
    ];

    private const CHUNK = 1000;

    public function handle(): int
    {
        $files = [];
        foreach (self::FILES as $key => $pattern) {
            $matches = glob(rtrim($this->argument('dir'), '/').'/'.$pattern) ?: [];
            if (count($matches) !== 1) {
                $this->error("Erwarte genau eine Datei {$pattern}, gefunden: ".count($matches));

                return self::FAILURE;
            }
            $files[$key] = $matches[0];
        }

        DB::transaction(function () use ($files) {
            // dict_words has no natural unique key (homographs share spelling+reading),
            // so a re-import replaces all rows instead of upserting.
            DictWord::query()->delete();
            $this->info('Wörter: '.$this->importWords($files['jmdict'], false));
            $this->info('Namen: '.$this->importWords($files['jmnedict'], true));
            $this->info('Kanji: '.$this->importKanji($files['kanjidic'], $files['kradfile']));
        });

        return self::SUCCESS;
    }

    private function importWords(string $path, bool $isName): int
    {
        $rows = [];
        $total = 0;
        foreach ($this->entries($path) as $n => $entry) {
            foreach ($this->wordRows($entry) as [$spelling, $reading, $glosses]) {
                $rows[] = [
                    'spelling' => $spelling,
                    'reading' => $reading,
                    'glosses' => json_encode($glosses, JSON_UNESCAPED_UNICODE),
                    'is_name' => $isName,
                ];
            }
            if (count($rows) >= self::CHUNK) {
                DictWord::insert($rows);
                $total += count($rows);
                $rows = [];
            }
            if (($n + 1) % 50000 === 0) {
                $this->line(basename($path).' '.($n + 1));
            }
        }
        DictWord::insert($rows);

        return $total + count($rows);
    }

    /**
     * One row per valid (spelling, reading) pair, with the English glosses of the senses
     * that apply to it. Kana-only words and readings, and readings of words marked
     * "usually written in kana" (uk), also get the kana as spelling.
     *
     * @param  array<string, mixed>  $entry
     * @return list<array{string, string, list<list<string>>}>
     */
    private function wordRows(array $entry): array
    {
        $kanji = array_column($entry['kanji'] ?? [], 'text');
        $senses = [];
        $usuallyKana = false;
        foreach ($entry['sense'] ?? $entry['translation'] ?? [] as $sense) {
            $usuallyKana = $usuallyKana || in_array('uk', $sense['misc'] ?? [], true);
            $glosses = [];
            foreach ($sense['gloss'] ?? $sense['translation'] ?? [] as $gloss) {
                if (($gloss['lang'] ?? null) === 'eng' && is_string($gloss['text'] ?? null)) {
                    $glosses[] = $gloss['text'];
                }
            }
            if ($glosses !== []) {
                $senses[] = [$sense['appliesToKanji'] ?? ['*'], $sense['appliesToKana'] ?? ['*'], $glosses];
            }
        }

        $rows = [];
        foreach ($entry['kana'] ?? [] as $kana) {
            $reading = $kana['text'] ?? null;
            if (! is_string($reading)) {
                continue;
            }
            $appliesTo = $kana['appliesToKanji'] ?? ['*'];
            $spellings = match (true) {
                $kanji === [] || $appliesTo === [] => [$reading],
                in_array('*', $appliesTo, true) => $kanji,
                default => array_intersect($kanji, $appliesTo),
            };
            if ($usuallyKana && ! in_array($reading, $spellings, true)) {
                $spellings[] = $reading;
            }
            foreach ($spellings as $spelling) {
                $glosses = [];
                foreach ($senses as [$senseKanji, $senseKana, $senseGlosses]) {
                    $kanjiOk = $spelling === $reading || in_array('*', $senseKanji, true) || in_array($spelling, $senseKanji, true);
                    $kanaOk = in_array('*', $senseKana, true) || in_array($reading, $senseKana, true);
                    if ($kanjiOk && $kanaOk) {
                        $glosses[] = $senseGlosses;
                    }
                }
                $rows[] = [$spelling, $reading, $glosses];
            }
        }

        return $rows;
    }

    private function importKanji(string $kanjidicPath, string $kradfilePath): int
    {
        // Kradfile is small (< 1 MB), so it is decoded in one go.
        $kradfile = json_decode(File::get($kradfilePath), true, flags: JSON_THROW_ON_ERROR)['kanji'] ?? [];

        $rows = [];
        $total = 0;
        foreach ($this->entries($kanjidicPath) as $character) {
            $literal = $character['literal'] ?? null;
            if (! is_string($literal)) {
                continue;
            }
            $on = $kun = $meanings = [];
            foreach ($character['readingMeaning']['groups'] ?? [] as $group) {
                foreach ($group['readings'] ?? [] as $reading) {
                    match ($reading['type'] ?? null) {
                        'ja_on' => $on[] = $reading['value'],
                        'ja_kun' => $kun[] = $reading['value'],
                        default => null,
                    };
                }
                foreach ($group['meanings'] ?? [] as $meaning) {
                    if (($meaning['lang'] ?? null) === 'en') {
                        $meanings[] = $meaning['value'];
                    }
                }
            }
            // plain: hiragana readings of the kanji alone (on readings converted, kun readings
            // without okurigana after "." and without the "-" affix markers).
            $plain = array_values(array_unique([
                ...array_map(fn (string $r) => mb_convert_kana(str_replace('-', '', $r), 'c'), $on),
                ...array_map(fn (string $r) => str_replace('-', '', explode('.', $r)[0]), $kun),
            ]));
            $radicals = array_values(array_unique(array_map(
                fn (string $r) => self::KRAD_SUBSTITUTES[$r] ?? $r,
                $kradfile[$literal] ?? [],
            )));

            $rows[] = [
                'character' => $literal,
                'readings' => json_encode(['on' => $on, 'kun' => $kun, 'nanori' => $character['readingMeaning']['nanori'] ?? [], 'plain' => $plain], JSON_UNESCAPED_UNICODE),
                'meanings' => json_encode($meanings, JSON_UNESCAPED_UNICODE),
                'radicals' => json_encode($radicals, JSON_UNESCAPED_UNICODE),
            ];
            if (count($rows) === self::CHUNK) {
                DictKanji::upsert($rows, ['character'], ['readings', 'meanings', 'radicals']);
                $total += count($rows);
                $rows = [];
            }
        }
        DictKanji::upsert($rows, ['character'], ['readings', 'meanings', 'radicals']);

        return $total + count($rows);
    }

    /**
     * Streams the entries of a jmdict-simplified file. The release files put one entry
     * per line inside the top-level array; the last entry line carries the closing "]".
     *
     * @return Generator<int, array<string, mixed>>
     */
    private function entries(string $path): Generator
    {
        $n = 0;
        foreach (File::lines($path) as $line) {
            $line = rtrim($line, " \r\n,");
            if (! str_starts_with($line, '{"')) {
                continue;
            }
            if (str_ends_with($line, ']')) {
                $line = substr($line, 0, -1);
            }
            $entry = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            if (is_array($entry)) {
                yield $n++ => $entry;
            }
        }
    }
}
