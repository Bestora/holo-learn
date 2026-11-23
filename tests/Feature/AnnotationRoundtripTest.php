<?php

namespace Tests\Feature;

use App\Annotations\Decomposer;
use App\Annotations\Importer;
use App\Models\Annotation;
use App\Models\Occurrence;
use App\Models\Reference;
use App\Models\Talent;
use Database\Seeders\KanaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AnnotationRoundtripTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/holo-annotations-'.uniqid();
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private int $sources = 0;

    private function talent(?int $priority): Talent
    {
        return Talent::query()->create([
            'holodex_channel_id' => 'UC'.uniqid(), 'name_en' => 'T', 'priority' => $priority,
        ]);
    }

    private function reference(string $kind, string $text, ?Talent $talent = null, string $status = 'new', ?string $alt = null): Reference
    {
        $reference = Reference::query()->create([
            'kind' => $kind, 'text_ja' => $text, 'text_key' => Reference::keyFor($text),
            'alt_text' => $alt, 'status' => $status,
        ]);
        if ($talent !== null) {
            Occurrence::query()->create([
                'reference_id' => $reference->id, 'talent_id' => $talent->id, 'source_id' => 's'.$this->sources++,
            ]);
        }

        return $reference;
    }

    /** @param array<string, mixed> $overrides */
    private function line(Reference $reference, array $overrides = []): string
    {
        return json_encode(array_merge([
            'key' => "{$reference->kind}:{$reference->text_key}",
            'reading' => 'はなにぼうれい',
            'meaning' => 'Ghost in a flower',
            'alt_kind' => 'translation',
            'model' => 'haiku',
            'words' => [
                ['surface' => '花', 'reading' => 'はな'],
                ['surface' => 'に', 'reading' => 'に'],
                ['surface' => '亡霊', 'reading' => 'ぼうれい'],
            ],
        ], $overrides), JSON_UNESCAPED_UNICODE);
    }

    /** @return list<array<string, mixed>> */
    private function export(string ...$args): array
    {
        $this->assertSame(0, Artisan::call('annotations:export '.implode(' ', $args)));
        $out = trim(Artisan::output());

        return $out === '' ? [] : array_map(fn ($l) => json_decode($l, true, flags: JSON_THROW_ON_ERROR), explode("\n", $out));
    }

    private function import(string $path, string $flags = ''): string
    {
        $this->assertSame(0, Artisan::call("annotations:import {$path} {$flags}"));

        return Artisan::output();
    }

    public function test_export_lists_only_new_references_with_occurrences_in_priority_order(): void
    {
        $top = $this->talent(1);
        $mid = $this->talent(5);
        $none = $this->talent(null);

        $this->reference('song', '歌C', $none);
        $songTop = $this->reference('song', '歌A', $top, alt: 'Song A');
        $nameMid = $this->reference('name', '名前', $mid);
        $nameTop = $this->reference('name', '兎田ぺこら', $top);
        $this->reference('song', '歌B', $mid)->occurrences()->create(['talent_id' => $top->id, 'source_id' => 'x1']);
        $this->reference('song', '孤独', null);
        $this->reference('song', '済み', $top, 'annotated');
        $this->reference('song', '却下', $top, 'rejected');

        $lines = $this->export();

        $this->assertSame(['兎田ぺこら', '歌A', '歌B', '名前', '歌C'], array_column($lines, 'text'));
        $this->assertSame([
            'key' => "name:{$nameTop->text_key}", 'kind' => 'name', 'text' => '兎田ぺこら', 'alt' => null,
        ], $lines[0]);
        $this->assertSame('Song A', $lines[1]['alt']);
        $this->assertSame("song:{$songTop->text_key}", $lines[1]['key']);
        $this->assertSame("name:{$nameMid->text_key}", $lines[3]['key']);

        $this->assertSame(['兎田ぺこら', '歌A'], array_column($this->export('--limit=2'), 'text'));
        $this->assertSame(['却下'], array_column($this->export('--retry-rejected'), 'text'));
    }

    public function test_export_output_is_raw_unicode_without_escaped_slashes(): void
    {
        $this->reference('song', 'あ/い', $this->talent(1), alt: 'A/B');

        Artisan::call('annotations:export');

        $this->assertStringContainsString('"text":"あ/い","alt":"A/B"}', Artisan::output());
    }

    public function test_export_rejects_bad_limit(): void
    {
        $this->assertSame(1, Artisan::call('annotations:export --limit=0'));
        $this->assertSame(1, Artisan::call('annotations:export --limit=abc'));
    }

    public function test_valid_line_creates_annotation_and_marks_annotated(): void
    {
        $reference = $this->reference('song', '花に亡霊', $this->talent(1));

        $this->assertTrue(app(Importer::class)->importLine($this->line($reference, ['reading' => 'ハナニボウレイ'])));

        $reference->refresh();
        $this->assertSame('annotated', $reference->status);
        $annotation = $reference->annotation;
        $this->assertNotNull($annotation);
        $this->assertSame('はなにぼうれい', $annotation->reading_kana);
        $this->assertSame('Ghost in a flower', $annotation->meaning_en);
        $this->assertSame('translation', $annotation->alt_kind);
        $this->assertSame('haiku', $annotation->model);
        $this->assertNull($annotation->check_errors);
        $this->assertSame([
            ['surface' => '花', 'reading' => 'はな'],
            ['surface' => 'に', 'reading' => 'に'],
            ['surface' => '亡霊', 'reading' => 'ぼうれい'],
        ], $annotation->words);
    }

    /** @return array<string, array{array<string, mixed>|string}> */
    public static function invalidLines(): array
    {
        return [
            'broken json' => ['{"key": '],
            'not an object' => ['[1,2]'],
            'bad key' => [['key' => 'song:abc']],
            'bad kind' => [['key' => 'item:'.str_repeat('a', 40)]],
            'reading with non-kana' => [['reading' => 'はな亡霊']],
            'empty reading' => [['reading' => '']],
            'empty meaning' => [['meaning' => '']],
            'long meaning' => [['meaning' => str_repeat('a', 301)]],
            'bad alt_kind' => [['alt_kind' => 'english']],
            'missing model' => [['model' => null]],
            'long model' => [['model' => str_repeat('m', 51)]],
            'missing words' => [['words' => null]],
            'empty words' => [['words' => []]],
            'words not a list' => [['words' => ['a' => ['surface' => '花', 'reading' => 'はな']]]],
            'word without surface' => [['words' => [['reading' => 'はな']]]],
            'word reading with kanji' => [['words' => [['surface' => '花', 'reading' => '花']]]],
            'key with trailing newline' => [['key' => 'song:'.str_repeat('0', 40)."\n"]],
            'reading as int' => [['reading' => 5]],
            'meaning as array' => [['meaning' => ['a']]],
            'words with null' => [['words' => [null]]],
            'word as list' => [['words' => [['花', 'はな']]]],
            'surface as int' => [['words' => [['surface' => 1, 'reading' => 'はな']]]],
            'alt_kind true' => [['alt_kind' => true]],
            'lemma without lemma_reading' => [['words' => [['surface' => '歌った', 'reading' => 'うたった', 'lemma' => '歌う']]]],
            'lemma_reading without lemma' => [['words' => [['surface' => '歌った', 'reading' => 'うたった', 'lemma_reading' => 'うたう']]]],
            'lemma_reading with kanji' => [['words' => [['surface' => '歌った', 'reading' => 'うたった', 'lemma' => '歌う', 'lemma_reading' => '歌う']]]],
            'lemma as int' => [['words' => [['surface' => '歌った', 'reading' => 'うたった', 'lemma' => 1, 'lemma_reading' => 'うたう']]]],
            'invalid utf-8' => ['{"key":"song:'.str_repeat('0', 40).'","reading":"は'."\xff".'な","meaning":"m","alt_kind":"none","model":"m","words":[{"surface":"花","reading":"はな"}]}'],
        ];
    }

    /** @param array<string, mixed>|string $line */
    #[DataProvider('invalidLines')]
    public function test_parse_line_rejects_invalid_lines(array|string $line): void
    {
        if (is_array($line)) {
            $line = $this->line(new Reference(['kind' => 'song', 'text_key' => str_repeat('0', 40)]), $line);
            $line = json_encode(array_filter(json_decode($line, true), fn ($v) => $v !== null), JSON_UNESCAPED_UNICODE);
        }

        $importer = app(Importer::class);
        $this->assertNull($importer->parseLine($line));
        $this->assertNotNull($importer->lineError($line));
    }

    public function test_parse_line_normalizes_and_ignores_unknown_fields(): void
    {
        $key = 'song:'.str_repeat('ab', 20);
        $line = json_encode([
            'key' => $key, 'reading' => 'ハナ ニ', 'meaning' => 'Flower', 'alt_kind' => 'none', 'model' => 'haiku',
            'extra' => 1, 'words' => [['surface' => '花', 'reading' => 'ハナ', 'pos' => 'noun'], ['surface' => 'に', 'reading' => 'に']],
        ], JSON_UNESCAPED_UNICODE);

        $importer = app(Importer::class);
        $this->assertNull($importer->lineError($line));
        $this->assertSame([
            'key' => $key, 'kind' => 'song', 'text_key' => str_repeat('ab', 20),
            'reading' => 'はなに', 'meaning' => 'Flower', 'alt_kind' => 'none', 'model' => 'haiku',
            'words' => [['surface' => '花', 'reading' => 'はな'], ['surface' => 'に', 'reading' => 'に']],
        ], $importer->parseLine($line));
    }

    public function test_reading_separators_are_removed(): void
    {
        $line = json_encode([
            'key' => 'name:'.str_repeat('ab', 20), 'reading' => 'ふわわ・あびすがーど', 'meaning' => 'Fuwawa', 'alt_kind' => 'romaji', 'model' => 'haiku',
            'words' => [['surface' => 'フワワ', 'reading' => 'ふわわ '], ['surface' => '・アビスガード', 'reading' => '・あびす　がーど']],
        ], JSON_UNESCAPED_UNICODE);

        $parsed = app(Importer::class)->parseLine($line);

        $this->assertSame('ふわわあびすがーど', $parsed['reading'] ?? null);
        $this->assertSame(['ふわわ', 'あびすがーど'], array_column($parsed['words'] ?? [], 'reading'));
    }

    public function test_parse_line_keeps_lemma(): void
    {
        $line = $this->line(new Reference(['kind' => 'song', 'text_key' => str_repeat('0', 40)]), ['reading' => 'うたった', 'words' => [
            ['surface' => '歌った', 'reading' => 'ウタッタ', 'lemma' => ' 歌う ', 'lemma_reading' => 'ウタ・ウ'],
        ]]);

        $this->assertSame(
            [['surface' => '歌った', 'reading' => 'うたった', 'lemma' => '歌う', 'lemma_reading' => 'うたう']],
            app(Importer::class)->parseLine($line)['words'] ?? null,
        );
    }

    public function test_import_verifies_and_reports_rejected(): void
    {
        $talent = $this->talent(1);
        $this->seed(KanaSeeder::class);
        $good = $this->reference('song', '花に亡霊', $talent);
        $bad = $this->reference('song', '花を', $talent);
        File::put("{$this->dir}/a.jsonl", $this->line($good)."\n".$this->line($bad, [
            'reading' => 'はなと', 'words' => [['surface' => '花', 'reading' => 'はな'], ['surface' => 'を', 'reading' => 'と']],
        ])."\n");

        $out = $this->import($this->dir, '-v');

        $this->assertStringContainsString('imported 2, skipped 0, verified 1, rejected 1', $out);
        $this->assertStringContainsString("song:{$bad->text_key} 花を: kana_mismatch", $out);
        $this->assertStringNotContainsString($good->text_key, $out);
        $this->assertSame('verified', $good->refresh()->status);
        $this->assertSame('rejected', $bad->refresh()->status);
        $this->assertSame(1, $good->items()->count()); // only に: no dictionary rows in this test
    }

    public function test_reverify_reapplies_rules_to_annotated_references(): void
    {
        $talent = $this->talent(1);
        $this->seed(KanaSeeder::class);
        $good = $this->reference('song', '花に亡霊', $talent);
        $this->reference('song', '歌B', $talent);
        File::put("{$this->dir}/a.jsonl", $this->line($good)."\n");
        $this->import($this->dir);
        $good->update(['status' => 'rejected']);
        $good->items()->detach();

        $this->assertSame(0, Artisan::call('annotations:reverify'));

        $this->assertStringContainsString('verified 1, rejected 0', Artisan::output());
        $this->assertSame('verified', $good->refresh()->status);
        $this->assertSame(1, $good->items()->count());
    }

    public function test_failing_decompose_rolls_back_verification(): void
    {
        $reference = $this->reference('song', '花に亡霊', $this->talent(1));
        app(Importer::class)->import($this->line($reference));
        $this->app->instance(Decomposer::class, new class extends Decomposer
        {
            public function decompose(Reference $ref): void
            {
                throw new RuntimeException('boom');
            }
        });

        try {
            Artisan::call('annotations:reverify');
            $this->fail('exception expected');
        } catch (RuntimeException) {
        }

        $this->assertSame('annotated', $reference->refresh()->status);
    }

    public function test_import_accepts_bom_and_crlf(): void
    {
        $talent = $this->talent(1);
        $a = $this->reference('song', '花に亡霊', $talent);
        $b = $this->reference('song', '歌B', $talent);
        File::put("{$this->dir}/bom.jsonl", "\xEF\xBB\xBF".$this->line($a)."\r\n".$this->line($b)."\r\n");

        $this->assertStringContainsString('imported 2, skipped 0', $this->import($this->dir));
    }

    public function test_reference_lookups_are_indexed(): void
    {
        $indexed = fn (string $table) => array_column(Schema::getIndexes($table), 'columns');

        $this->assertContains(['reference_id'], $indexed('occurrences'));
        $this->assertContains(['talent_id'], $indexed('occurrences'));
        $this->assertContains(['reference_id'], $indexed('item_reference'));
    }

    public function test_import_skips_bad_lines_without_aborting(): void
    {
        $talent = $this->talent(1);
        $a = $this->reference('song', '花に亡霊', $talent);
        $b = $this->reference('song', '歌B', $talent);
        $unknown = new Reference(['kind' => 'song', 'text_key' => Reference::keyFor('無い')]);

        File::put("{$this->dir}/a.jsonl", implode("\n", [
            '{"key": broken',
            $this->line($unknown),
            '',
            $this->line($a, ['reading' => 'hana']),
            $this->line($a, ['words' => null]),
            $this->line($a, ['alt_kind' => 'english']),
            $this->line($a),
        ])."\n");
        File::put("{$this->dir}/b.jsonl", $this->line($b)."\n");

        $out = $this->import($this->dir, '-v');

        $this->assertStringContainsString('imported 2, skipped 5', $out);
        $this->assertStringContainsString('a.jsonl:1: kein gültiges JSON-Objekt', $out);
        $this->assertStringContainsString('a.jsonl:2: unbekannter key', $out);
        $this->assertStringNotContainsString('a.jsonl:3', $out);
        $this->assertStringContainsString('a.jsonl:4: reading fehlt oder enthält Nicht-Kana', $out);
        $this->assertStringContainsString('a.jsonl:5: words fehlt oder ist keine nicht-leere Liste', $out);
        $this->assertStringContainsString('a.jsonl:6: alt_kind muss romaji, translation oder none sein', $out);
        $this->assertStringNotContainsString('a.jsonl:7', $out);
        $this->assertStringNotContainsString('b.jsonl', $out);
        $this->assertSame('verified', $a->refresh()->status);
        $this->assertSame('rejected', $b->refresh()->status);  // its words spell 花に亡霊
        $this->assertSame(2, Annotation::query()->count());
    }

    public function test_later_file_wins_and_rejected_is_reset(): void
    {
        $reference = $this->reference('song', '花に亡霊', $this->talent(1), 'rejected');
        File::put("{$this->dir}/1.jsonl", $this->line($reference, ['meaning' => 'first'])."\n");
        File::put("{$this->dir}/2.jsonl", $this->line($reference, ['meaning' => 'second'])."\n");
        Annotation::query()->create([
            'reference_id' => $reference->id, 'reading_kana' => 'x', 'meaning_en' => 'old', 'words' => [],
            'alt_kind' => 'none', 'model' => 'm', 'check_errors' => ['bad'],
        ]);

        $this->assertStringContainsString('imported 2, skipped 0', $this->import($this->dir));

        $this->assertSame('verified', $reference->refresh()->status);
        $this->assertSame('second', $reference->annotation?->meaning_en);
        $this->assertNull($reference->annotation?->check_errors);
        $this->assertSame(1, Annotation::query()->count());
    }

    public function test_verified_reference_is_never_overwritten(): void
    {
        $reference = $this->reference('song', '花に亡霊', $this->talent(1), 'verified');
        Annotation::query()->create([
            'reference_id' => $reference->id, 'reading_kana' => 'はなにぼうれい', 'meaning_en' => 'kept', 'words' => [],
            'alt_kind' => 'none', 'model' => 'm',
        ]);
        File::put("{$this->dir}/a.jsonl", $this->line($reference, ['meaning' => 'new'])."\n");

        $this->assertStringContainsString('imported 0, skipped 1', $this->import("{$this->dir}/a.jsonl"));

        $this->assertSame('verified', $reference->refresh()->status);
        $this->assertSame('kept', $reference->annotation?->meaning_en);
    }

    public function test_import_fails_on_missing_path(): void
    {
        $this->assertSame(1, Artisan::call('annotations:import does/not/exist'));
    }

    public function test_relative_path_resolves_from_base_path(): void
    {
        $reference = $this->reference('song', '花に亡霊', $this->talent(1));
        $relative = 'storage/framework/testing-annotations-'.uniqid().'.jsonl';
        File::put(base_path($relative), $this->line($reference)."\n");

        try {
            $this->assertStringContainsString('imported 1, skipped 0', $this->import($relative));
        } finally {
            File::delete(base_path($relative));
        }
    }

    public function test_roundtrip_export_annotate_import(): void
    {
        $top = $this->talent(1);
        $this->reference('name', '兎田ぺこら', $top);
        $this->reference('song', '花に亡霊', $top, alt: 'Ghost In A Flower');
        $this->reference('song', 'いのち', $this->talent(null));

        $exported = $this->export();
        $this->assertCount(3, $exported);

        File::put("{$this->dir}/batch.jsonl", implode("\n", array_map(fn (array $e) => json_encode([
            'key' => $e['key'], 'reading' => 'てすと', 'meaning' => "meaning of {$e['text']}",
            'alt_kind' => $e['alt'] === null ? 'none' : 'translation', 'model' => 'haiku',
            'words' => [['surface' => $e['text'], 'reading' => 'てすと']],
        ], JSON_UNESCAPED_UNICODE), $exported))."\n");

        $this->assertStringContainsString('imported 3, skipped 0', $this->import($this->dir));

        foreach ($exported as $e) {
            [$kind, $textKey] = explode(':', $e['key']);
            $reference = Reference::query()->where(['kind' => $kind, 'text_key' => $textKey])->sole();
            // the dummy reading fails the kana and romaji checks; the kanji title word only loses ok
            $this->assertSame($e['text'] === '花に亡霊' ? 'verified' : 'rejected', $reference->status);
            $this->assertSame("meaning of {$e['text']}", $reference->annotation?->meaning_en);
            $this->assertSame([['surface' => $e['text'], 'reading' => 'てすと', 'unit' => $kind === 'name' ? 0 : null, 'ok' => false]], $reference->annotation->words);
        }
        $this->assertSame([], $this->export());

        $before = Annotation::query()->orderBy('id')->get()->toArray();
        $this->import($this->dir);
        $this->assertSame($before, Annotation::query()->orderBy('id')->get()->toArray());
        $this->assertSame(['いのち' => 'rejected', '兎田ぺこら' => 'rejected', '花に亡霊' => 'verified'], Reference::query()->orderBy('text_ja')->pluck('status', 'text_ja')->all());
    }
}
