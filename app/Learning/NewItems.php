<?php

namespace App\Learning;

use App\Models\DictKanji;
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
     * Items with at least `min_references[kind]` verified references (kana: always; radicals: with
     * meanings), best first: level asc, verified references desc, id (kana without references thus in
     * gojūon order). With a user: not reviewed yet, specials setting applied, and radicals only once a
     * kanji containing them is reviewed.
     *
     * @return Collection<int, Item>
     */
    public function eligible(?User $user = null): Collection
    {
        /** @var array<string, int> $min */
        $min = config('hololearn.min_references');
        $verified = fn (Builder|BelongsToMany $q) => $q->where('status', 'verified');

        $items = Item::query()
            ->where(function (Builder $q) use ($min, $verified) {
                foreach (self::KINDS as $kind) {
                    $q->orWhere(fn (Builder $k) => $k->where('kind', $kind)
                        // counts link rows, a superset: narrowed below
                        ->when(($min[$kind] ?? 0) > 0, fn (Builder $k) => $k->whereHas('references', $verified, '>=', $min[$kind])));
                }
            })
            ->where(fn (Builder $q) => $q->where('kind', '!=', 'radical')->orWhereJsonLength('meanings', '>', 0))
            ->when($user, fn (Builder $q, User $user) => $q
                ->whereDoesntHave('reviews', fn (Builder $r) => $r->where('user_id', $user->id))
                ->where(fn (Builder $r) => $r->where('kind', '!=', 'radical')->orWhereIn('characters', $this->unlockedRadicals($user))))
            ->when($user && ! $user->specials_enabled, fn (Builder $q) => $q->where('is_special', false))
            ->with(['references' => fn (BelongsToMany $q) => $verified($q)->select('refs.id')])
            ->get();

        // ponytail: an item repeated in one reference (ねね) has several link rows, so distinct references are
        // counted in PHP instead of a raw count(distinct). Loads the link rows of all candidates; move to a
        // cached per-item count if the reference table grows into the hundreds of thousands.
        $counts = $items->mapWithKeys(fn (Item $item) => [$item->id => $item->references->unique('id')->count()]);
        $items->each->unsetRelation('references');

        return $items->filter(fn (Item $item) => $counts[$item->id] >= ($min[$item->kind] ?? 0))
            ->sort(fn (Item $a, Item $b) => [$a->level, $counts[$b->id], $a->id] <=> [$b->level, $counts[$a->id], $b->id])
            ->values();
    }

    /**
     * The next new item, or null (daily limit reached or nothing eligible). A special only when the last
     * `special_every − 1` new items were not, a radical only when the last two were not — unless nothing
     * else is eligible.
     */
    public function next(User $user): ?Item
    {
        if ($this->limitReached($user)) {
            return null;
        }

        $candidates = $this->eligible($user);
        $specialWindow = (int) config('hololearn.special_every') - 1;
        $recent = $user->reviews()->latest('id')->take(max($specialWindow, 2))->with('item')->get();
        $specialOk = $recent->count() >= $specialWindow && $recent->take($specialWindow)->every(fn ($review) => ! $review->item->is_special);
        $radicalOk = $recent->count() >= 2 && $recent->take(2)->every(fn ($review) => $review->item->kind !== 'radical');
        $allowed = fn (bool $special, bool $radical) => fn (Item $item) => ($special || ! $item->is_special) && ($radical || $item->kind !== 'radical');

        return $candidates->first($allowed($specialOk, $radicalOk))
            ?? $candidates->first($allowed($specialOk, true))
            ?? $candidates->first($allowed(true, $radicalOk))
            ?? $candidates->first();
    }

    /** Next local midnight (ISO 8601) when the daily limit is what holds back eligible new items, else null. */
    public function newItemsAt(User $user): ?string
    {
        if (! $this->limitReached($user) || $this->eligible($user)->isEmpty()) {
            return null;
        }

        return now()->setTimezone(config('hololearn.timezone'))->startOfDay()->addDay()->setTimezone(config('app.timezone'))->toIso8601String();
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

    private function limitReached(User $user): bool
    {
        return $this->createdToday($user) >= (int) config('hololearn.new_items_per_day');
    }

    /**
     * Radicals of the kanji the user has reviews for.
     *
     * @return list<string>
     */
    private function unlockedRadicals(User $user): array
    {
        $kanji = Item::query()->where('kind', 'kanji')->whereHas('reviews', fn (Builder $q) => $q->where('user_id', $user->id))->pluck('characters');

        $radicals = [];
        foreach (DictKanji::query()->whereIn('character', $kanji)->get() as $entry) {
            array_push($radicals, ...$entry->radicals);
        }

        return array_values(array_unique($radicals));
    }
}
