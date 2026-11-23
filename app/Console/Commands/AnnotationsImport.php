<?php

namespace App\Console\Commands;

use App\Annotations\Importer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class AnnotationsImport extends Command
{
    protected $signature = 'annotations:import {path=data/annotations : Verzeichnis mit *.jsonl oder eine einzelne Datei}';

    protected $description = 'Liest Annotationszeilen (JSONL) ein und setzt die Referenzen auf annotated';

    public function handle(Importer $importer): int
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
        foreach ($files as $file) {
            foreach (File::lines($file) as $index => $line) {
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
            }
        }

        $this->info("imported {$imported}, skipped {$skipped}");

        return self::SUCCESS;
    }
}
