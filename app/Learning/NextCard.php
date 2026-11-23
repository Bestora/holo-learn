<?php

namespace App\Learning;

use App\Models\Review;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/** Picks the card to issue next in a lesson (rules 2–4; rule 1, the pending card, is LessonFlow's). */
class NextCard
{
    public function __construct(private NewItems $newItems, private Cards $cards) {}

    /**
     * Next card: a due review (oldest first) not yet answered correctly in this lesson, a wrong one
     * after two other cards; else an intro; else a wrong one early; else null.
     *
     * @param  array<string, mixed>  $state
     * @return ?array<string, mixed>
     */
    public function issue(User $user, array $state, int $position): ?array
    {
        $done = $cooling = [];
        $byItem = [];
        foreach ($state['answers'] as $answer) {
            $byItem[$answer['item_id']][] = $answer;
        }
        foreach ($byItem as $itemId => $answers) {
            if (in_array(true, array_column($answers, 'correct'), true) || count($answers) >= 2) {
                $done[] = $itemId;
            } elseif ($position - end($answers)['at'] < 2) {
                $cooling[] = $itemId;
            }
        }

        foreach ($this->due($user, [...$done, ...$cooling]) as $review) {
            if ($card = $this->cards->quiz($user, $review, $state['used_references'])) {
                return $card;
            }
        }
        $item = $this->newItems->next($user);
        if ($item !== null && ($card = $this->cards->intro($user, $item, $state['used_references']))) {
            return $card;
        }
        foreach ($this->due($user, $done, $cooling) as $review) {
            if ($card = $this->cards->quiz($user, $review, $state['used_references'])) {
                return $card;
            }
        }

        return null;
    }

    /**
     * @param  list<int|string>  $except
     * @param  ?list<int|string>  $only
     * @return iterable<Review>
     */
    private function due(User $user, array $except, ?array $only = null): iterable
    {
        if ($only === []) {
            return [];
        }

        return $user->reviews()->showable()->where('due_at', '<=', now())
            ->whereNotIn('item_id', $except)
            ->when($only !== null, fn (Builder $q) => $q->whereIn('item_id', $only))
            ->with('item')->orderBy('due_at')->orderBy('id')->lazy(50);
    }

    /** Earliest future due date among reviews that can still be shown (ISO 8601), or null. Never in the past. */
    public function nextDueAt(User $user): ?string
    {
        $next = $user->reviews()->showable()->where('due_at', '>', now())->min('due_at');

        return $next === null ? null : CarbonImmutable::parse($next)->toIso8601String();
    }
}
