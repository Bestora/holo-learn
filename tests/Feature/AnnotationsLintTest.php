<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AnnotationsLintTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = sys_get_temp_dir().'/holo-lint-'.uniqid().'.jsonl';
    }

    protected function tearDown(): void
    {
        File::delete($this->file);
        parent::tearDown();
    }

    private function line(string $hex = 'a', string $meaning = 'Ghost in a flower'): string
    {
        return json_encode([
            'key' => 'song:'.str_repeat($hex, 40),
            'reading' => 'はなにぼうれい',
            'meaning' => $meaning,
            'alt_kind' => 'translation',
            'model' => 'haiku',
            'words' => [
                ['surface' => '花', 'reading' => 'はな'],
                ['surface' => 'に', 'reading' => 'に'],
                ['surface' => '亡霊', 'reading' => 'ぼうれい'],
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    public function test_valid_file_passes(): void
    {
        File::put($this->file, $this->line('a')."\n\n".$this->line('b')."\n");

        $this->artisan('annotations:lint', ['file' => $this->file])
            ->expectsOutputToContain('2 Zeilen, 0 fehlerhaft')
            ->assertExitCode(0);
    }

    public function test_broken_line_fails_with_line_number(): void
    {
        File::put($this->file, $this->line('a')."\n".'{"key":"song:zz"}'."\n".$this->line('b')."\n");

        $this->artisan('annotations:lint', ['file' => $this->file])
            ->expectsOutputToContain(basename($this->file).':2: key fehlt')
            ->expectsOutputToContain('3 Zeilen, 1 fehlerhaft')
            ->assertExitCode(1);
    }

    public function test_fix_removes_exactly_the_bad_lines(): void
    {
        File::put($this->file, "nope\n".$this->line('a')."\n\n".'{"key":1}'."\n".$this->line('b')."\n");

        $this->artisan('annotations:lint', ['file' => $this->file, '--fix' => true])
            ->expectsOutputToContain('2 Zeilen entfernt')
            ->assertExitCode(0);

        $this->assertSame($this->line('a')."\n".$this->line('b')."\n", File::get($this->file));
        $this->artisan('annotations:lint', ['file' => $this->file])->assertExitCode(0);
    }

    public function test_duplicate_keys_warn_and_fix_keeps_last(): void
    {
        File::put($this->file, $this->line('a', 'first')."\n".$this->line('b')."\n".$this->line('a', 'second')."\n");

        $this->artisan('annotations:lint', ['file' => $this->file])
            ->expectsOutputToContain(basename($this->file).':3: doppelter key')
            ->assertExitCode(0);

        $this->artisan('annotations:lint', ['file' => $this->file, '--fix' => true])->assertExitCode(0);
        $this->assertSame($this->line('b')."\n".$this->line('a', 'second')."\n", File::get($this->file));
    }

    public function test_bom_on_first_line_is_ignored(): void
    {
        File::put($this->file, "\xEF\xBB\xBF".$this->line('a')."\n");

        $this->artisan('annotations:lint', ['file' => $this->file])
            ->expectsOutputToContain('1 Zeilen, 0 fehlerhaft')
            ->assertExitCode(0);
    }

    public function test_missing_file_fails(): void
    {
        $this->artisan('annotations:lint', ['file' => $this->file])
            ->expectsOutputToContain('Datei nicht gefunden')
            ->assertExitCode(1);
    }
}
