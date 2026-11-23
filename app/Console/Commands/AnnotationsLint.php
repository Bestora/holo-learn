<?php

namespace App\Console\Commands;

use App\Annotations\Importer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class AnnotationsLint extends Command
{
    protected $signature = 'annotations:lint {file : JSONL-Datei mit Annotationszeilen}
        {--fix : fehlerhafte und doppelte Zeilen entfernen (bei doppelten bleibt die letzte)}';

    protected $description = 'Prüft das Format einer Annotationsdatei ohne Datenbank';

    public function handle(Importer $importer): int
    {
        $file = $this->argument('file');
        if (! str_starts_with($file, '/')) {
            $file = base_path($file);
        }
        if (! is_file($file)) {
            $this->error("Datei nicht gefunden: {$file}");

            return self::FAILURE;
        }

        $name = basename($file);
        $total = $bad = 0;
        $valid = []; // key => [line number, line]; a later duplicate replaces the earlier one
        foreach (Importer::fileLines($file) as $number => $line) {
            $total++;
            $parsed = $importer->parseLine($line);
            if ($parsed === null) {
                $bad++;
                $this->line("{$name}:{$number}: ".$importer->lineError($line));

                continue;
            }
            if (isset($valid[$parsed['key']])) {
                $this->warn("{$name}:{$number}: doppelter key {$parsed['key']} (auch Zeile {$valid[$parsed['key']][0]})");
                unset($valid[$parsed['key']]); // re-insert at the end: keep the position of the last occurrence
            }
            $valid[$parsed['key']] = [$number, $line];
        }

        if ($this->option('fix')) {
            File::put($file, implode('', array_map(fn ($entry) => $entry[1]."\n", $valid)));
            $this->info(($total - count($valid)).' Zeilen entfernt');

            return self::SUCCESS;
        }

        $this->info("{$total} Zeilen, {$bad} fehlerhaft");

        return $bad === 0 ? self::SUCCESS : self::FAILURE;
    }
}
