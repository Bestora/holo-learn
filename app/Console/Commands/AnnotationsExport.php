<?php

namespace App\Console\Commands;

use App\Annotations\Exporter;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

class AnnotationsExport extends Command
{
    protected $signature = 'annotations:export
        {--limit=200 : Höchstens so viele Referenzen}
        {--retry-rejected : Abgelehnte statt neuer Referenzen ausgeben}';

    protected $description = 'Gibt nicht annotierte Referenzen als JSONL auf stdout aus (Eingabe für die Annotations-Agenten)';

    public function handle(Exporter $exporter): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit <= 0) {
            // stderr: stdout carries only JSONL
            $this->output->getErrorStyle()->writeln('<error>--limit muss eine positive ganze Zahl sein</error>');

            return self::FAILURE;
        }

        foreach ($exporter->lines($limit, (bool) $this->option('retry-rejected')) as $line) {
            $this->output->writeln($line, OutputInterface::OUTPUT_RAW);
        }

        return self::SUCCESS;
    }
}
