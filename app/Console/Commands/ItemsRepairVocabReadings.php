<?php

namespace App\Console\Commands;

use App\Annotations\Decomposer;
use App\Annotations\VocabMatcher;
use App\Japanese\Kana;
use App\Models\DictWord;
use App\Models\Item;
use App\Models\Reference;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** One-off: vocab made before readings were limited to the matched entry (一番 also listed ひとつがい). */
class ItemsRepairVocabReadings extends Command
{
    protected $signature = 'items:repair-vocab-readings';

    protected $description = 'Limits vocab readings to their dictionary entry and unlinks references that read the word differently';

    public function handle(Decomposer $decomposer): int
    {
        $changed = [];
        foreach (Item::query()->where('kind', 'vocab')->get() as $item) {
            if (Kana::kanjiIn($item->characters) === []) {
                continue; // loanwords already carry only their own reading
            }
            $rows = DictWord::query()->where('spelling', $item->characters)->where('is_name', false)->orderBy('id')->get();
            // the row the item was made from: its meanings and one of its readings
            $match = $rows->first(fn (DictWord $w) => in_array(Kana::toHiragana($w->reading), $item->readings, true)
                && array_slice(array_values(array_unique(array_merge(...$w->glosses))), 0, 5) === $item->meanings);
            if ($match === null) {
                continue; // dictionary changed since; leave as is
            }
            $readings = VocabMatcher::entryReadings($rows, $match);
            if ($readings !== $item->readings) {
                $item->readings = $readings;
                $changed[$item->id] = $item;
            }
        }

        $links = fn () => Item::query()->whereKey(array_keys($changed))->withCount('references')->get()->sum('references_count');
        $before = $links();
        DB::transaction(function () use ($changed, $decomposer) {
            foreach ($changed as $item) {
                $item->save();
            }
            // rebuilt links skip a vocab item whose readings lack the word's reading; items are never deleted
            Reference::query()->whereHas('items', fn ($q) => $q->whereKey(array_keys($changed)))->with('annotation')
                ->get()->each(fn (Reference $ref) => $decomposer->decompose($ref));
        });

        $this->info('vocab items changed: '.count($changed).', links removed: '.($before - $links()));

        return self::SUCCESS;
    }
}
