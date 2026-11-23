<?php

namespace App\Console\Commands;

use App\Holodex\HolodexException;
use App\Holodex\SongSync;
use App\Holodex\TalentSync;
use App\Models\Reference;
use App\Models\Talent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class HolodexSync extends Command
{
    protected $signature = 'holodex:sync
        {--talents : Nur Talente abgleichen}
        {--songs : Nur Songs abgleichen}
        {--channel= : Songs nur für diesen Holodex-Kanal}
        {--full : Songs: alle Seiten abrufen statt bei der ersten bekannten Seite aufzuhören}';

    protected $description = 'Gleicht Talente und Songs mit Holodex ab (ohne Option: beides)';

    public function handle(TalentSync $talents, SongSync $songs): int
    {
        $all = ! $this->option('talents') && ! $this->option('songs');
        $failed = false;

        if ($all || $this->option('talents')) {
            try {
                $talents->run();
                $this->info('Talente: '.Talent::count()
                    .', davon Zweitkanäle: '.Talent::query()->where('is_secondary', true)->count()
                    .', Namens-Referenzen: '.Reference::query()->where('kind', 'name')->count());
            } catch (HolodexException $e) {
                $this->logFailure($e, 'Talente fehlgeschlagen');
                $failed = true;
            }
        }

        if ($all || $this->option('songs')) {
            $failed = ! $this->syncSongs($songs) || $failed;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return bool false if any talent failed */
    private function syncSongs(SongSync $songs): bool
    {
        $channel = $this->option('channel');
        // ponytail: sorted in PHP for "priority asc, nulls last" without raw SQL; ~100 rows.
        $talents = Talent::query()
            ->when($channel, fn ($q) => $q->where('holodex_channel_id', $channel))
            ->orderBy('id')->get()
            ->sortBy(fn (Talent $t) => $t->priority ?? PHP_INT_MAX);

        if ($talents->isEmpty()) {
            $this->error($channel ? "Kein Talent mit Kanal {$channel}" : 'Keine Talente – erst --talents abgleichen');

            return false;
        }

        $ok = true;
        foreach ($talents as $talent) {
            try {
                $created = $songs->run($talent, (bool) $this->option('full'));
                $this->info("Songs {$talent->name_en}: {$created} neue Fundstellen, "
                    ."{$songs->skippedNoJapanese} ohne Japanisch, {$songs->skippedArtistOnly} nur Interpret, "
                    ."{$songs->skippedDuplicate} doppelt, {$songs->skippedMalformed} fehlerhaft, "
                    ."{$songs->deletedReferences} verwaiste Referenzen gelöscht, {$songs->requests} Abrufe");
            } catch (HolodexException $e) {
                $this->logFailure($e, "Songs {$talent->name_en} ({$talent->holodex_channel_id}) fehlgeschlagen", $talent->holodex_channel_id);
                $ok = false;
            }
        }

        return $ok;
    }

    private function logFailure(HolodexException $e, string $message, ?string $channelId = null): void
    {
        Log::error("Holodex: {$message}", array_filter(
            ['status' => $e->status, 'path' => $e->path, 'channel_id' => $channelId],
            fn ($v) => $v !== null,
        ));
        $this->error("{$message}: {$e->getMessage()}");
    }
}
