<?php

namespace App\Learning;

use App\Models\Lesson;
use App\Models\Review;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The lesson state machine. Every step runs in a transaction holding a row lock on the user (and the
 * lesson), so a double-submitted answer sees the cleared pending card and gets a 422.
 *
 * lessons.state:
 *   pending          ?{type, item_id, review_id, reference_id, occurrence_id, field, mode, options,
 *                      correct_option, expected, issued_at}  the issued, unanswered card (server side)
 *   used_references  list<int>  references shown in this lesson
 *   answers          list<{item_id, correct, at}>  graded cards, `at` = lesson position after it
 *   last_answer      ?{item_id, review_id, stage_before, answered_at, overridable}  until the next card
 *   dismissed        bool  summary of this finished lesson was closed
 */
class LessonFlow
{
    private const EMPTY_STATE = ['pending' => null, 'used_references' => [], 'answers' => [], 'last_answer' => null, 'dismissed' => false];

    public function __construct(private NextCard $next, private Cards $cards, private NewItems $newItems) {}

    /**
     * Props for GET /learn: {lesson, card, summary, nothing_due}.
     *
     * @return array<string, mixed>
     */
    public function show(User $user): array
    {
        return DB::transaction(function () use ($user) {
            $user = $this->lockUser($user);
            $latest = $user->lessons()->latest('id')->lockForUpdate()->first();
            if ($latest?->finished_at !== null && ! $latest->state['dismissed']) {
                return $this->props($user, $latest);
            }

            $lesson = $latest?->finished_at === null ? $latest : null;
            $state = $lesson->state ?? self::EMPTY_STATE;
            if ($state['pending'] !== null && ! $this->cards->isValid($state['pending'])) {
                $state['pending'] = null;
            }
            if ($state['pending'] === null && ($pending = $this->next->issue($user, $state, $lesson->position ?? 0))) {
                $state['pending'] = $pending;
                if ($pending['reference_id'] !== null) {
                    $state['used_references'][] = $pending['reference_id'];
                }
                $state['last_answer'] = null;
            }

            if ($state['pending'] === null) {
                if ($lesson !== null && $lesson->position > 0) {
                    $lesson->state = $state;
                    $this->finish($user, $lesson);

                    return $this->props($user, $lesson);
                }
                $lesson?->delete();

                return ['lesson' => null, 'card' => null, 'summary' => null, 'nothing_due' => [
                    'next_due_at' => $this->next->nextDueAt($user),
                    'new_items_at' => $this->newItems->newItemsAt($user), // set when only the daily limit holds new items back
                ]];
            }

            $lesson ??= new Lesson(['user_id' => $user->id, 'size' => (int) config('hololearn.lesson_size')]);
            $lesson->state = $state;
            $lesson->save();

            return $this->props($user, $lesson);
        });
    }

    /** POST /learn/intro: creates the review (stage 0, due now) and advances. */
    public function confirmIntro(User $user, int $itemId): void
    {
        DB::transaction(function () use ($user, $itemId) {
            $user = $this->lockUser($user);
            [$lesson, $pending] = $this->pending($user, 'intro', $itemId);

            Review::query()->firstOrCreate(
                ['user_id' => $user->id, 'item_id' => $itemId],
                ['stage' => 0, 'due_at' => now(), 'last_reference_id' => $pending['reference_id']],
            );
            $lesson->new_items++;
            $this->advance($user, $lesson, ['pending' => null] + $lesson->state);
        });
    }

    /**
     * POST /learn/answer.
     *
     * @return array{correct: bool, expected: list<string>, reveal: array<string, mixed>, next_due_at: string, can_override: bool}
     */
    public function answer(User $user, int $itemId, string $answer): array
    {
        return DB::transaction(function () use ($user, $itemId, $answer) {
            $user = $this->lockUser($user);
            [$lesson, $pending] = $this->pending($user, 'quiz', $itemId);
            if (! $this->cards->isValid($pending)) {
                throw $this->stale(); // reference no longer verified: the next GET issues another one
            }
            $review = Review::query()->whereKey($pending['review_id'])->lockForUpdate()->first() ?? throw $this->stale();

            $correct = match (true) {
                $pending['mode'] === 'choice' => $answer === $pending['correct_option'],
                $pending['field'] === 'meaning' => AnswerChecker::meaning($answer, $pending['expected'], $this->cards->otherMeanings($itemId)),
                default => AnswerChecker::reading($answer, $pending['expected']),
            };
            $now = now()->toImmutable();
            $stageBefore = $review->stage;
            $next = Srs::next($stageBefore, $correct, $now);
            $review->update(['stage' => $next['stage'], 'due_at' => $next['due_at'], 'last_reference_id' => $pending['reference_id']]);

            $overridable = ! $correct && $pending['mode'] === 'typed' && $pending['field'] === 'meaning';
            $correct ? $lesson->correct++ : $lesson->wrong++;
            $state = $lesson->state;
            $state['pending'] = null;
            $state['answers'][] = ['item_id' => $itemId, 'correct' => $correct, 'at' => $lesson->position + 1];
            $state['last_answer'] = [
                'item_id' => $itemId, 'review_id' => $review->id, 'stage_before' => $stageBefore,
                'answered_at' => $now->toIso8601String(), 'overridable' => $overridable,
            ];
            $this->advance($user, $lesson, $state);

            return [
                'correct' => $correct,
                // choice: the correct option first, so the page can mark it
                'expected' => $pending['mode'] === 'choice' ? array_values(array_unique([$pending['correct_option'], ...$pending['expected']])) : $pending['expected'],
                'reveal' => $this->cards->reveal($pending),
                'next_due_at' => $next['due_at']->toIso8601String(),
                'can_override' => $overridable,
            ];
        });
    }

    /**
     * POST /learn/override: the last wrong typed meaning counts as correct.
     *
     * @return array{correct: true, next_due_at: string}
     */
    public function override(User $user): array
    {
        return DB::transaction(function () use ($user) {
            $user = $this->lockUser($user);
            $lesson = $user->lessons()->latest('id')->lockForUpdate()->first();
            $last = $lesson?->state['last_answer'] ?? null;
            if ($lesson === null || $last === null || ! $last['overridable']) {
                throw ValidationException::withMessages(['answer' => 'Diese Antwort kann nicht mehr als richtig gewertet werden.']);
            }
            $review = Review::query()->whereKey($last['review_id'])->lockForUpdate()->first() ?? throw $this->stale();

            $next = Srs::next($last['stage_before'], true, CarbonImmutable::parse($last['answered_at']));
            $review->update(['stage' => $next['stage'], 'due_at' => $next['due_at']]);

            $state = $lesson->state;
            $index = array_key_last(array_filter($state['answers'], fn (array $a) => $a['item_id'] === $last['item_id']));
            $state['answers'][$index]['correct'] = true;
            $state['last_answer'] = null;
            $lesson->correct++;
            $lesson->wrong--;
            $lesson->state = $state;
            $lesson->save();

            return ['correct' => true, 'next_due_at' => $next['due_at']->toIso8601String()];
        });
    }

    /** POST /learn/start: closes the summary so the next GET starts a new lesson. */
    public function dismissSummary(User $user): void
    {
        DB::transaction(function () use ($user) {
            $lesson = $this->lockUser($user)->lessons()->latest('id')->lockForUpdate()->first();
            if ($lesson?->finished_at !== null) {
                $lesson->state = ['dismissed' => true, 'last_answer' => null] + $lesson->state; // closes the override window
                $lesson->save();
            }
        });
    }

    /** @param array<string, mixed> $state */
    private function advance(User $user, Lesson $lesson, array $state): void
    {
        $lesson->position++;
        $lesson->state = $state;
        $lesson->position >= $lesson->size ? $this->finish($user, $lesson) : $lesson->save();
    }

    /** Sets finished_at and the streak (local days). */
    private function finish(User $user, Lesson $lesson): void
    {
        $lesson->finished_at = now();
        $lesson->save();

        $today = now()->setTimezone(config('hololearn.timezone'));
        $user->streak_days = match ($user->last_lesson_on?->toDateString()) {
            $today->toDateString() => $user->streak_days,
            $today->copy()->subDay()->toDateString() => $user->streak_days + 1,
            default => 1,
        };
        $user->last_lesson_on = CarbonImmutable::parse($today->toDateString());
        $user->save();
    }

    /** @return array{0: Lesson, 1: array<string, mixed>} */
    private function pending(User $user, string $type, int $itemId): array
    {
        $lesson = $user->lessons()->whereNull('finished_at')->latest('id')->lockForUpdate()->first();
        $pending = $lesson?->state['pending'] ?? null;
        if ($lesson === null || $pending === null || $pending['type'] !== $type || $pending['item_id'] !== $itemId) {
            throw $this->stale();
        }

        return [$lesson, $pending];
    }

    private function stale(): ValidationException
    {
        return ValidationException::withMessages(['item_id' => 'Diese Karte ist nicht mehr aktuell.']);
    }

    private function lockUser(User $user): User
    {
        return User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function props(User $user, Lesson $lesson): array
    {
        $finished = $lesson->finished_at !== null;

        return [
            'lesson' => $lesson->only(['id', 'size', 'position', 'correct', 'wrong']),
            'card' => $finished ? null : $this->cards->props($lesson->state['pending']),
            'summary' => $finished ? [...$lesson->only(['correct', 'wrong', 'new_items']), 'streak_days' => $user->streak_days] : null,
            'nothing_due' => null,
        ];
    }
}
