<?php

namespace App\Console\Commands;

use App\Japanese\Kana;
use App\Learning\ItemLevel;
use App\Models\DictKanji;
use App\Models\DictWord;
use App\Models\Item;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class ItemsRelevel extends Command
{
    protected $signature = 'items:relevel';

    protected $description = 'Berechnet Lernstufe und Spezial-Kennzeichen aller Elemente neu aus den Wörterbuchtabellen';

    private const CHUNK = 500;

    /** @var array<string, array{int, bool}> kanji => [level, special], dict_kanji lookups so far */
    private array $kanji = [];

    public function handle(): int
    {
        $this->kanji = []; // the command instance can be reused
        // a radical takes the lowest level of the kanji items that contain it
        $radicalKanji = [];
        Item::query()->where('kind', 'kanji')->chunkById(self::CHUNK, function (Collection $items) use (&$radicalKanji) {
            foreach ($this->loadKanji($items->map(fn (Item $item) => $item->characters)->all()) as $kanji) {
                foreach ($kanji->radicals as $radical) {
                    $radicalKanji[$radical][] = $this->kanji[$kanji->character];
                }
            }
        });

        $counts = [];
        $changed = 0;
        Item::query()->chunkById(self::CHUNK, function (Collection $items) use ($radicalKanji, &$counts, &$changed) {
            $vocab = $items->where('kind', 'vocab')->pluck('characters');
            $this->loadKanji(Kana::kanjiIn($vocab->implode('')));
            $common = DictWord::query()->whereIn('spelling', $vocab)->where('is_name', false)->where('common', true)
                ->distinct()->pluck('spelling')->flip();

            $updates = []; // "level|special" => item ids
            foreach ($items as $item) {
                [$level, $special] = match ($item->kind) {
                    'hiragana', 'katakana' => ItemLevel::kana($item->kind),
                    'kanji' => $this->kanji[$item->characters],
                    'radical' => ItemLevel::radical($radicalKanji[$item->characters] ?? []),
                    default => ItemLevel::vocab($common->has($item->characters), array_map(fn (string $c) => $this->kanji[$c], Kana::kanjiIn($item->characters))),
                };
                $counts[$item->kind] = ($counts[$item->kind] ?? 0) + 1;
                if ($level !== $item->level || $special !== $item->is_special) {
                    $updates[$level.'|'.(int) $special][] = $item->id;
                }
            }
            foreach ($updates as $key => $ids) {
                [$level, $special] = explode('|', $key);
                Item::query()->whereKey($ids)->update(['level' => (int) $level, 'is_special' => $special === '1']);
                $changed += count($ids);
            }
        });

        foreach ($counts as $kind => $count) {
            $this->line("{$kind}: {$count}");
        }
        $this->info("geändert: {$changed}");

        return self::SUCCESS;
    }

    /**
     * Levels of the kanji not looked up yet; kanji missing from dict_kanji count as rare.
     *
     * @param  array<int, string>  $characters
     * @return Collection<string, DictKanji> the newly found entries
     */
    private function loadKanji(array $characters): Collection
    {
        $missing = array_values(array_diff(array_unique($characters), array_keys($this->kanji)));
        $found = DictKanji::query()->whereIn('character', $missing)->get()->keyBy('character');
        foreach ($missing as $character) {
            $this->kanji[$character] = ItemLevel::kanji($found[$character]->jlpt ?? null, $found[$character]->grade ?? null);
        }

        return $found;
    }
}
