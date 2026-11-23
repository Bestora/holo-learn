<?php

namespace App\Learning;

use App\Models\Item;
use App\Models\Occurrence;
use App\Models\Reference;
use App\Models\Talent;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Random\Randomizer;

/** Chooses the verified reference and occurrence a card shows. */
class CardPicker
{
    public function __construct(private Randomizer $random) {}

    /**
     * Weighted random (3 with a live occurrence of a preferred talent, else 1) among the item's verified
     * references, avoiding the last shown one and those used in this lesson unless nothing else is left.
     *
     * @param  list<int>  $usedInLesson
     * @return ?array{reference: Reference, occurrence: ?Occurrence}
     */
    public function pick(User $user, Item $item, ?int $lastReferenceId, array $usedInLesson): ?array
    {
        $candidates = $item->references()->where('status', 'verified')
            ->with(['occurrences' => fn (HasMany $q) => $q->whereNull('dead_at')->with('talent')])
            ->get()->unique('id')->values();
        if ($candidates->isEmpty()) {
            return null;
        }

        $fresh = $candidates->reject(fn (Reference $r) => $r->id === $lastReferenceId || in_array($r->id, $usedInLesson, true));
        $fresh = $fresh->isNotEmpty() ? $fresh : $candidates->reject(fn (Reference $r) => $r->id === $lastReferenceId);
        $fresh = $fresh->isNotEmpty() ? $fresh : $candidates;

        $preferred = $this->preferredTalentIds($user);
        $preferredOccurrences = fn (Reference $r) => $r->occurrences->whereIn('talent_id', $preferred)->values();

        $reference = $this->weighted($fresh->values(), fn (Reference $r) => $preferredOccurrences($r)->isNotEmpty() ? 3 : 1);
        $pool = $preferredOccurrences($reference);
        $pool = $pool->isNotEmpty() ? $pool : $reference->occurrences->values();

        return ['reference' => $reference, 'occurrence' => $pool->isEmpty() ? null : $pool[$this->random->getInt(0, $pool->count() - 1)]];
    }

    /**
     * Positions of the item in the reference (kana can repeat) and its context readings there.
     *
     * @return array{positions: list<int>, context_readings: list<string>}
     */
    public function links(Item $item, int $referenceId): array
    {
        $rows = $item->references()->whereKey($referenceId)->orderByPivot('position')->get();

        return [
            'positions' => array_values(array_map(intval(...), $rows->pluck('pivot.position')->all())),
            'context_readings' => array_values(array_unique(array_filter(array_map(strval(...), $rows->pluck('pivot.context_reading')->all())))),
        ];
    }

    public function isShowable(Item $item, int $referenceId): bool
    {
        return $item->references()->whereKey($referenceId)->where('status', 'verified')->exists();
    }

    /** Verified references of the item other than the shown one. */
    public function remaining(Item $item, int $referenceId): int
    {
        return $item->references()->where('status', 'verified')->whereKeyNot($referenceId)->distinct()->count('refs.id');
    }

    /**
     * Favorites; without any, talents with a priority.
     *
     * @return list<int>
     */
    private function preferredTalentIds(User $user): array
    {
        $favorites = $user->favorites()->pluck('talents.id')->all();
        $ids = $favorites ?: Talent::query()->whereNotNull('priority')->pluck('id')->all();

        return array_values(array_map(intval(...), $ids));
    }

    /**
     * @param  Collection<int, Reference>  $references
     * @param  callable(Reference): int  $weight
     */
    private function weighted(Collection $references, callable $weight): Reference
    {
        $weights = $references->map($weight);
        $roll = $this->random->getInt(1, (int) $weights->sum());
        foreach ($references as $i => $reference) {
            $roll -= $weights[$i];
            if ($roll <= 0) {
                return $reference;
            }
        }

        return $references->last();
    }
}
