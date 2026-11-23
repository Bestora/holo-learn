<?php

namespace App\Console\Commands;

use App\Annotations\Decomposer;
use App\Annotations\Verifier;
use App\Models\Reference;
use Illuminate\Console\Command;

class AnnotationsReverify extends Command
{
    protected $signature = 'annotations:reverify';

    protected $description = 'Prüft und zerlegt alle annotierten Referenzen erneut (nach Regeländerungen)';

    public function handle(Verifier $verifier, Decomposer $decomposer): int
    {
        $references = Reference::query()->whereIn('status', ['annotated', 'verified', 'rejected'])
            ->whereHas('annotation')->with('annotation')->lazyById(200);

        [$verified, $rejected] = AnnotationsImport::verifyAll($this, $references, $verifier, $decomposer);
        $this->info("verified {$verified}, rejected {$rejected}");

        return self::SUCCESS;
    }
}
