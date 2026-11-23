<?php

namespace App\Console\Commands;

use App\Annotations\Decomposer;
use App\Annotations\Importer;
use App\Annotations\Verifier;
use App\Models\Reference;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class AnnotationsImport extends Command
{
    protected $signature = 'annotations:import {path=data/annotations : Verzeichnis mit *.jsonl oder eine einzelne Datei}';

    protected $description = 'Liest Annotationszeilen (JSONL) ein und setzt die Referenzen auf annotated';

    public function handle(Importer $importer, Verifier $verifier, Decomposer $decomposer): int
    {
        $path = $this->argument('path');
        if (! str_starts_with($path, '/')) {
            $path = base_path($path);
        }

        $files = match (true) {
            is_dir($path) => glob($path.'/*.jsonl') ?: [], // glob sorts by name
            is_file($path) => [$path],
            default => null,
        };
        if ($files === null) {
            $this->error("Pfad nicht gefunden: {$path}");

            return self::FAILURE;
        }

        $imported = $skipped = 0;
        $references = [];
        foreach ($files as $file) {
            foreach (File::lines($file) as $index => $line) {
                if ($index === 0) {
                    $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line; // UTF-8 BOM
                }
                if (trim($line) === '') {
                    continue;
                }
                $result = $importer->import($line);
                if (is_string($result)) {
                    $skipped++;
                    $this->line(basename($file).':'.($index + 1).': '.$result, verbosity: 'v');

                    continue;
                }
                $imported++;
                $references[$result->id] = $result;
            }
        }

        // after all files, so a later line for the same key still replaces an earlier one
        [$verified, $rejected] = self::verifyAll($this, $references, $verifier, $decomposer);
        $this->info("imported {$imported}, skipped {$skipped}, verified {$verified}, rejected {$rejected}");

        return self::SUCCESS;
    }

    /**
     * Verifies and decomposes each reference; with -v prints one line per rejected one.
     *
     * @param  iterable<Reference>  $references
     * @return array{int, int} verified, rejected
     */
    public static function verifyAll(Command $command, iterable $references, Verifier $verifier, Decomposer $decomposer): array
    {
        $verified = $rejected = 0;
        foreach ($references as $reference) {
            $errors = $verifier->verify($reference);
            $decomposer->decompose($reference);
            if ($errors === []) {
                $verified++;

                continue;
            }
            $rejected++;
            $command->line("{$reference->kind}:{$reference->text_key} {$reference->text_ja}: ".implode(', ', $errors), verbosity: 'v');
        }

        return [$verified, $rejected];
    }
}
