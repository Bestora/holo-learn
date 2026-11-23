<?php

namespace App\Holodex;

use App\Japanese\TextCleaner;
use App\Models\Occurrence;
use App\Models\Reference;
use App\Models\Talent;
use Illuminate\Support\Facades\Log;

/** Imports all Hololive channels (without HOLOSTARS) as talents plus their name references. */
class TalentSync
{
    private const PAGE_SIZE = 50;

    public function __construct(private Client $client) {}

    public function run(): void
    {
        $offset = 0;
        do {
            $page = $this->client->get('/channels', [
                'org' => 'Hololive', 'type' => 'vtuber', 'limit' => self::PAGE_SIZE, 'offset' => $offset,
            ]);
            foreach ($page as $channel) {
                $this->import($channel);
            }
            $offset += self::PAGE_SIZE;
        } while (count($page) >= self::PAGE_SIZE);
    }

    private function import(mixed $channel): void
    {
        if (! is_array($channel) || ! is_string($channel['id'] ?? null) || ! is_string($channel['name'] ?? null)) {
            Log::warning('Holodex-Kanal ohne id/name übersprungen', ['channel' => $channel]);

            return;
        }

        $id = $channel['id'];
        $name = $channel['name'];
        $english = mb_trim((string) ($channel['english_name'] ?? ''));
        $group = $channel['group'] ?? null;

        // ponytail: existing HOLOSTARS rows from earlier imports are left alone, no deletes.
        if (preg_match('/holostars/i', "{$name} {$english} {$group}")) {
            return;
        }

        $secondary = $english === ''
            || preg_match('/(?<![A-Za-z])Sub(?![a-z])/', "{$name} {$english}")
            || in_array($group, ['Official', 'Misc'], true);

        $nameJa = $secondary ? null
            : (config('hololearn.name_overrides')[$id] ?? TextCleaner::talentName($name, $english));

        $position = array_search($id, config('hololearn.priority_channels'), true);

        $talent = Talent::query()->updateOrCreate(['holodex_channel_id' => $id], [
            'name_ja' => $nameJa,
            'name_en' => $english !== '' ? $english : $name,
            'group_name' => $group,
            'photo_url' => $channel['photo'] ?? null,
            'inactive' => (bool) ($channel['inactive'] ?? false),
            'is_secondary' => $secondary,
            'priority' => is_int($position) ? $position + 1 : null,
        ]);

        $occurrence = Occurrence::query()->with('reference')->firstWhere('source_id', "name:{$id}");
        $previous = $occurrence?->reference;

        if ($nameJa === null) {
            $occurrence?->delete();
        } else {
            $reference = Reference::firstOrCreateFor('name', $nameJa, $english);
            Occurrence::query()->updateOrCreate(
                ['source_id' => "name:{$id}"],
                ['reference_id' => $reference->id, 'talent_id' => $talent->id],
            );
        }

        // Renamed talent: drop the old name reference if it is unannotated and nothing points at it.
        if ($previous !== null && $previous->text_ja !== $nameJa
            && $previous->status === 'new' && ! $previous->occurrences()->exists()) {
            $previous->delete();
        }
    }
}
