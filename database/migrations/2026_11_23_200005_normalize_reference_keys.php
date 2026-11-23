<?php

use App\Models\Reference;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Recomputes refs.text_key with Reference::keyFor() (NFKC etc.) and merges references that now share
// a key into the lowest id: occurrences and item links move over, the duplicates are deleted.
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $groups = Reference::query()->orderBy('id')->get()
                ->groupBy(fn (Reference $r) => $r->kind.'|'.Reference::keyFor($r->text_ja));

            foreach ($groups as $group) {
                /** @var Reference $keep */
                $keep = $group->first();

                foreach ($group->slice(1) as $duplicate) {
                    /** @var Reference $duplicate */
                    $duplicate->occurrences()->update(['reference_id' => $keep->id]);
                    // item links: move each (item, position) unless the survivor already has it
                    foreach ($duplicate->items()->newPivotQuery()->get() as $link) {
                        $taken = $keep->items()->newPivotQuery()
                            ->where('item_id', $link->item_id)->where('position', $link->position)->exists();
                        if (! $taken) {
                            $keep->items()->attach($link->item_id, ['position' => $link->position, 'context_reading' => $link->context_reading]);
                        }
                    }
                    if ($keep->alt_text === null && $duplicate->alt_text !== null) {
                        $keep->alt_text = $duplicate->alt_text;
                    }
                    $duplicate->delete(); // cascades its remaining item links
                }

                // after the duplicates are gone, so the unique (kind, text_key) index cannot collide
                $keep->text_key = Reference::keyFor($keep->text_ja);
                $keep->save();
            }
        });
    }

    public function down(): void
    {
        // merged references cannot be split again; keys stay normalized
    }
};
