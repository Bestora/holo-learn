<?php

namespace App\Console\Commands;

use App\Annotations\Decomposer;
use App\Annotations\Verifier;
use App\Models\Item;
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

        // vocab made under an older rule (オラ "hola") that no reference links any more; kept while a learner reviews it
        $removed = Item::query()->where('kind', 'vocab')->doesntHave('references')->doesntHave('reviews')->delete();
        $this->info("Vokabeln ohne Referenz entfernt: {$removed}");

        return self::SUCCESS;
    }
}
