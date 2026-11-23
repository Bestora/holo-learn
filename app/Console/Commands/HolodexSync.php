<?php

namespace App\Console\Commands;

use App\Holodex\HolodexException;
use App\Holodex\TalentSync;
use App\Models\Talent;
use Illuminate\Console\Command;

class HolodexSync extends Command
{
    protected $signature = 'holodex:sync
        {--talents : Nur Talente abgleichen}
        {--songs : Nur Songs abgleichen}
        {--channel= : Songs nur für diesen Holodex-Kanal}';

    protected $description = 'Gleicht Talente und Songs mit Holodex ab (ohne Option: beides)';

    public function handle(TalentSync $talents): int
    {
        $all = ! $this->option('talents') && ! $this->option('songs');
        $failed = false;

        if ($all || $this->option('talents')) {
            try {
                $talents->run();
                $this->info('Talente: '.Talent::count()
                    .', davon Zweitkanäle: '.Talent::query()->where('is_secondary', true)->count()
                    .', mit Namens-Referenz: '.Talent::query()->whereHas('occurrences')->count());
            } catch (HolodexException $e) {
                $this->error('Talente fehlgeschlagen: '.$e->getMessage());
                $failed = true;
            }
        }

        if ($all || $this->option('songs')) {
            // ponytail: --songs and --channel arrive with the song sync (Task 5).
            $this->warn('Songs: noch nicht implementiert');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
