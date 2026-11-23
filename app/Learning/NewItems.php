<?php

namespace App\Learning;

use App\Models\Item;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Which item a learner meets next ("Lernweg"). */
class NewItems
{
    public const KINDS = ['hiragana', 'katakana', 'radical', 'kanji', 'vocab'];

    /**
     * Items with at least `min_references` verified references (radicals: with meanings), best first:
     * level asc, verified references desc, id. With a user: not reviewed yet, specials setting applied.
     *
     * @return Collection<int, Item>
     */
    public function eligible(?User $user = null): Collection
    {
        $min = (int) config('hololearn.min_references');
        $verified = fn (Builder|BelongsToMany $q) => $q->where('status', 'verified');

        $items = Item::query()
            ->whereHas('references', $verified, '>=', $min) // counts link rows, a superset: narrowed below
            ->where(fn (Builder $q) => $q->where('kind', '!=', 'radical')->orWhereJsonLength('meanings', '>', 0))
            ->when($user, fn (Builder $q, User $user) => $q->whereDoesntHave('reviews', fn (Builder $r) => $r->where('user_id', $user->id)))
            ->when($user && ! $user->specials_enabled, fn (Builder $q) => $q->where('is_special', false))
            ->with(['references' => fn (BelongsToMany $q) => $verified($q)->select('refs.id')])
            ->get();

        // ponytail: an item repeated in one reference (ねね) has several link rows, so distinct references are
        // counted in PHP instead of a raw count(distinct). Loads the link rows of all candidates; move to a
        // cached per-item count if the reference table grows into the hundreds of thousands.
        $counts = $items->mapWithKeys(fn (Item $item) => [$item->id => $item->references->unique('id')->count()]);
        $items->each->unsetRelation('references');

        return $items->filter(fn (Item $item) => $counts[$item->id] >= $min)
            ->sort(fn (Item $a, Item $b) => [$a->level, $counts[$b->id], $a->id] <=> [$b->level, $counts[$a->id], $b->id])
            ->values();
    }

    /** The next new item, or null (daily limit reached or nothing eligible). Specials at most every n-th. */
    public function next(User $user): ?Item
    {
        if ($this->createdToday($user) >= (int) config('hololearn.new_items_per_day')) {
            return null;
        }

        $candidates = $this->eligible($user);
        $window = (int) config('hololearn.special_every') - 1;
        $recent = $user->reviews()->latest('id')->take($window)->with('item')->get();
        $specialAllowed = $recent->count() === $window && $recent->every(fn ($review) => ! $review->item->is_special);

        return $specialAllowed ? $candidates->first() : ($candidates->firstWhere('is_special', false) ?? $candidates->first());
    }

    /** Reviews the user created today (local day, `hololearn.timezone`). */
    public function createdToday(User $user): int
    {
        $start = now()->setTimezone(config('hololearn.timezone'))->startOfDay()->setTimezone(config('app.timezone'));

        return $user->reviews()->where('created_at', '>=', $start)->count();
    }

    /** @return array<string, int> eligible items per kind, all users */
    public function availableCounts(): array
    {
        return array_merge(array_fill_keys(self::KINDS, 0), $this->eligible()->countBy('kind')->all());
    }
}
