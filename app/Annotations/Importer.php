<?php

namespace App\Annotations;

use App\Japanese\Kana;
use App\Models\Annotation;
use App\Models\Reference;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Reads annotation lines (JSONL, written by AI agents) into the annotations table.
 *
 * @phpstan-type ParsedLine array{key: string, kind: string, text_key: string, reading: string, meaning: string, alt_kind: string, model: string, words: list<array{surface: string, reading: string, lemma?: string, lemma_reading?: string}>}
 */
class Importer
{
    private const ALT_KINDS = ['romaji', 'translation', 'none'];

    /**
     * Format check only, no database access.
     *
     * @return ParsedLine|null
     */
    public function parseLine(string $json): ?array
    {
        $result = $this->check($json);

        return is_array($result) ? $result : null;
    }

    /** Why parseLine() rejects the line, or null if it is valid. */
    public function lineError(string $json): ?string
    {
        $result = $this->check($json);

        return is_string($result) ? $result : null;
    }

    /**
     * Non-blank lines of a JSONL file, keyed by 1-based line number, BOM stripped.
     *
     * @return \Generator<int, string>
     */
    public static function fileLines(string $file): \Generator
    {
        foreach (File::lines($file) as $index => $line) {
            if ($index === 0) {
                $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line; // UTF-8 BOM
            }
            if (trim($line) !== '') {
                yield $index + 1 => $line;
            }
        }
    }

    public function importLine(string $json): bool
    {
        return $this->import($json) instanceof Reference;
    }

    /**
     * Creates or replaces the annotation and marks the reference annotated.
     *
     * @return Reference|string the annotated reference, or why the line was skipped
     */
    public function import(string $json): Reference|string
    {
        $line = $this->check($json);
        if (is_string($line)) {
            return $line;
        }

        $reference = Reference::query()->where(['kind' => $line['kind'], 'text_key' => $line['text_key']])->first();
        if ($reference === null) {
            return 'unbekannter key';
        }
        if ($reference->status === 'verified') {
            return 'Referenz bereits verifiziert';
        }

        DB::transaction(function () use ($reference, $line) {
            Annotation::query()->updateOrCreate(['reference_id' => $reference->id], [
                'reading_kana' => $line['reading'],
                'meaning_en' => $line['meaning'],
                'words' => $line['words'],
                'alt_kind' => $line['alt_kind'],
                'model' => $line['model'],
                'check_errors' => null,
            ]);
            $reference->update(['status' => 'annotated']);
        });

        return $reference;
    }

    /** @return ParsedLine|string */
    private function check(string $json): array|string
    {
        $data = json_decode($json, true);
        if (! is_array($data) || array_is_list($data)) {
            return 'kein gültiges JSON-Objekt';
        }

        $key = $data['key'] ?? null;
        if (! is_string($key) || preg_match('/^(name|song):([0-9a-f]{40})$/D', $key, $m) !== 1) {
            return 'key fehlt oder hat nicht die Form <name|song>:<40 Hex-Zeichen>';
        }

        $reading = $this->kana($data['reading'] ?? null);
        if ($reading === null) {
            return 'reading fehlt oder enthält Nicht-Kana';
        }

        $meaning = $this->text($data['meaning'] ?? null, 300);
        if ($meaning === null) {
            return 'meaning fehlt oder ist länger als 300 Zeichen';
        }

        $altKind = $data['alt_kind'] ?? null;
        if (! in_array($altKind, self::ALT_KINDS, true)) {
            return 'alt_kind muss romaji, translation oder none sein';
        }

        $model = $this->text($data['model'] ?? null, 50);
        if ($model === null) {
            return 'model fehlt oder ist länger als 50 Zeichen';
        }

        $words = $data['words'] ?? null;
        if (! is_array($words) || $words === [] || ! array_is_list($words)) {
            return 'words fehlt oder ist keine nicht-leere Liste';
        }
        $parsedWords = [];
        foreach ($words as $i => $word) {
            $surface = is_array($word) ? $this->text($word['surface'] ?? null, PHP_INT_MAX) : null;
            $wordReading = is_array($word) ? $this->kana($word['reading'] ?? null) : null;
            if ($surface === null || $wordReading === null) {
                return 'words['.$i.'] braucht surface und ein reading aus Kana';
            }
            $parsed = ['surface' => $surface, 'reading' => $wordReading];
            // optional dictionary form of a conjugated word: both or neither
            if (isset($word['lemma']) || isset($word['lemma_reading'])) {
                $lemma = $this->text($word['lemma'] ?? null, PHP_INT_MAX);
                $lemmaReading = $this->kana($word['lemma_reading'] ?? null);
                if ($lemma === null || $lemmaReading === null) {
                    return 'words['.$i.']: lemma und lemma_reading (Kana) nur zusammen';
                }
                $parsed += ['lemma' => $lemma, 'lemma_reading' => $lemmaReading];
            }
            $parsedWords[] = $parsed;
        }

        return [
            'key' => $key,
            'kind' => $m[1],
            'text_key' => $m[2],
            'reading' => $reading,
            'meaning' => $meaning,
            'alt_kind' => $altKind,
            'model' => $model,
            'words' => $parsedWords,
        ];
    }

    /** Non-empty trimmed string of at most $max characters, else null. */
    private function text(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = mb_trim($value);

        return $value !== '' && mb_strlen($value) <= $max ? $value : null;
    }

    /** Kana without separators (spaces, ・), converted to hiragana, else null. */
    private function kana(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = preg_replace('/[\s\x{3000}\x{30FB}]+/u', '', $value) ?? '';

        return Kana::isKanaOnly($value) ? Kana::toHiragana($value) : null;
    }
}
