<?php

namespace App\Holodex;

use App\Japanese\TextCleaner;
use App\Models\Occurrence;
use App\Models\Reference;
use App\Models\Talent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Imports all Hololive channels (without HOLOSTARS) as talents plus their name references. */
class TalentSync
{
    private const PAGE_SIZE = 50;

    private const MAX_PAGES = 40;

    public function __construct(private Client $client) {}

    public function run(): void
    {
        $offset = 0;
        $pages = 0;
        do {
            $page = $this->client->get('/channels', [
                'org' => 'Hololive', 'type' => 'vtuber', 'limit' => self::PAGE_SIZE, 'offset' => $offset,
            ]);
            foreach ($page as $channel) {
                $this->import($channel);
            }
            $offset += self::PAGE_SIZE;
        } while (count($page) >= self::PAGE_SIZE && ++$pages < self::MAX_PAGES);
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

        $names = $secondary ? [] : $this->names($id, $name, $english);
        $position = array_search($id, config('hololearn.priority_channels'), true);

        DB::transaction(function () use ($id, $name, $english, $group, $channel, $secondary, $names, $position) {
            $talent = Talent::query()->updateOrCreate(['holodex_channel_id' => $id], [
                'name_ja' => $names[0]['ja'] ?? null,
                'name_en' => $english !== '' ? $english : $name,
                'group_name' => $group,
                'photo_url' => $channel['photo'] ?? null,
                'inactive' => (bool) ($channel['inactive'] ?? false),
                'is_secondary' => $secondary,
                'priority' => is_int($position) ? $position + 1 : null,
            ]);

            // Name occurrences: "name:<id>" for the first name, "name:<id>:2", ":3"… for further ones.
            $previous = $talent->occurrences()->with('reference')->where('source_id', 'like', 'name:%')->get();
            $current = [];
            foreach ($names as $i => ['ja' => $ja, 'en' => $en]) {
                $sourceId = $i === 0 ? "name:{$id}" : "name:{$id}:".($i + 1);
                $current[] = $sourceId;
                Occurrence::query()->updateOrCreate(
                    ['source_id' => $sourceId],
                    ['reference_id' => Reference::firstOrCreateFor('name', $ja, $en)->id, 'talent_id' => $talent->id],
                );
            }
            $previous->whereNotIn('source_id', $current)->each->delete();

            // Renamed or dropped names: delete old references nothing uses any more, whatever their status
            // (annotations are re-importable from versioned files).
            foreach ($previous->pluck('reference')->filter()->unique('id') as $reference) {
                /** @var Reference $reference */
                if (! $reference->occurrences()->exists()) {
                    $reference->delete();
                }
            }
        });
    }

    /**
     * Japanese names of a main channel: a configured override (one name, or a list of ja/en pairs), else from the channel name.
     *
     * @return list<array{ja: string, en: string}>
     */
    private function names(string $id, string $name, string $english): array
    {
        $override = config('hololearn.name_overrides')[$id] ?? null;
        if (is_array($override)) {
            return array_values($override);
        }

        $ja = is_string($override) ? $override : TextCleaner::talentName($name, $english);

        return $ja === null ? [] : [['ja' => $ja, 'en' => $english]];
    }
}
