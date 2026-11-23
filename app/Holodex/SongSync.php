<?php

namespace App\Holodex;

use App\Japanese\TextCleaner;
use App\Models\Occurrence;
use App\Models\Reference;
use App\Models\Talent;

/** Imports a talent's sung songs from Holodex' (undocumented) POST /songs/latest as song references. */
class SongSync
{
    private const PATH = '/songs/latest';

    private const PAGE_SIZE = 50;

    private const MAX_PAGES = 400;

    /** Counters of the last run(). */
    public int $skippedNoJapanese = 0;

    public int $skippedMalformed = 0;

    public int $requests = 0;

    /** Song references deleted because re-cleaning left them unannotated and unused. */
    public int $deletedReferences = 0;

    public function __construct(private Client $client) {}

    /**
     * $full walks all pages; otherwise the run stops at the first page that adds nothing new.
     *
     * @return int number of new occurrences
     */
    public function run(Talent $talent, bool $full = false): int
    {
        $this->skippedNoJapanese = $this->skippedMalformed = $this->requests = $this->deletedReferences = 0;
        $created = 0;
        $offset = 0;
        $progressFile = config('hololearn.progress_file');

        try {
            do {
                $data = $this->client->post(self::PATH, [
                    'channel_id' => $talent->holodex_channel_id, 'paginated' => true, 'limit' => self::PAGE_SIZE, 'offset' => $offset,
                ]);
                $this->requests++;
                if (! is_array($data['items'] ?? null)) {
                    throw new HolodexException(200, self::PATH, 'Antwort ohne items');
                }
                $items = $data['items'];
                $total = is_numeric($data['total'] ?? null) ? (int) $data['total'] : PHP_INT_MAX;

                $known = $new = 0;
                foreach ($items as $item) {
                    match ($this->import($talent, $item)) {
                        'new' => $new++,
                        'known' => $known++,
                        default => null,
                    };
                }
                $created += $new;
                $offset += self::PAGE_SIZE;

                if (is_string($progressFile)) {
                    file_put_contents($progressFile, min($offset, $total).' '.$total." Songs\n");
                }

                // Incremental run: a page that adds nothing but holds already stored songs means the rest is known too.
                // A page of only non-Japanese titles has no stored songs, so it does not stop a first run.
                $pageKnown = ! $full && $new === 0 && $known > 0;
            } while (count($items) >= self::PAGE_SIZE && $offset < $total && ! $pageKnown && $this->requests < self::MAX_PAGES);
        } finally {
            if (is_string($progressFile)) {
                @unlink($progressFile);
            }
        }

        return $created;
    }

    /** @return 'new'|'known'|'skipped' */
    private function import(Talent $talent, mixed $item): string
    {
        $name = is_array($item) ? $item['name'] ?? null : null;
        $videoId = is_array($item) ? $item['video_id'] ?? null : null;
        if (! is_scalar($name) || ! is_scalar($videoId) || mb_trim((string) $name) === '' || (string) $videoId === '') {
            $this->skippedMalformed++;

            return 'skipped';
        }

        /** @var array<string, mixed> $item */
        $start = is_numeric($item['start'] ?? null) ? (int) $item['start'] : null;
        $end = is_numeric($item['end'] ?? null) ? (int) $item['end'] : null;
        $sourceId = "song:{$videoId}:".($start ?? 0);

        $occurrence = Occurrence::query()->with('reference')->firstWhere('source_id', $sourceId);
        $previous = $occurrence?->reference;

        ['text_ja' => $textJa, 'alt' => $alt] = TextCleaner::songName((string) $name);
        if ($textJa === null) {
            // A stored title may lose its Japanese under newer cleaning rules.
            $occurrence?->delete();
            $this->dropIfOrphaned($previous);
            $this->skippedNoJapanese++;

            return 'skipped';
        }

        $reference = Reference::firstOrCreateFor('song', $textJa, $alt);

        if ($occurrence !== null) {
            if ($occurrence->reference_id !== $reference->id) {
                // Re-cleaned title: move the occurrence, drop the old reference if nothing else uses it.
                $occurrence->update(['reference_id' => $reference->id]);
                $this->dropIfOrphaned($previous);
            }

            return 'known';
        }

        Occurrence::query()->create([
            'reference_id' => $reference->id,
            'talent_id' => $talent->id,
            'video_id' => (string) $videoId,
            'start_s' => $start,
            'end_s' => $end,
            'source_id' => $sourceId,
        ]);

        return 'new';
    }

    private function dropIfOrphaned(?Reference $reference): void
    {
        if ($reference !== null && $reference->status === 'new' && ! $reference->occurrences()->exists()) {
            $reference->delete();
            $this->deletedReferences++;
        }
    }
}
