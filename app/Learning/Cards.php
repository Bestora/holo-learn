<?php

namespace App\Learning;

use App\Models\Item;
use App\Models\Occurrence;
use App\Models\Reference;
use App\Models\Review;
use App\Models\User;

/**
 * Builds pending cards (stored in lessons.state, including the server-only answer) and turns
 * them into the props the page sees. Quiz props never carry the answer.
 */
class Cards
{
    public function __construct(private CardPicker $picker, private Choices $choices) {}

    /**
     * @param  list<int>  $usedReferences
     * @return ?array<string, mixed> pending card
     */
    public function intro(User $user, Item $item, array $usedReferences): ?array
    {
        $pick = $this->picker->pick($user, $item, null, $usedReferences);

        return $pick === null ? null : $this->pending('intro', $item, null, $pick, Question::field($item, 0), 'choice', null, null, []);
    }

    /**
     * Null when the item has no verified reference any more, or is a radical without enough distractors.
     *
     * @param  list<int>  $usedReferences
     * @return ?array<string, mixed> pending card
     */
    public function quiz(User $user, Review $review, array $usedReferences): ?array
    {
        $item = $review->item;
        $pick = $this->picker->pick($user, $item, $review->last_reference_id, $usedReferences);
        if ($pick === null) {
            return null;
        }

        $field = Question::field($item, $review->stage);
        $mode = Question::mode($item, $review->stage);
        $expected = Question::expected($item, $field, $this->picker->links($item, $pick['reference']->id)['context_readings']);
        $correct = Question::correctOption($item, $field, $expected);
        $options = $mode === 'choice' ? $this->choices->options($item, $field, $correct, $expected) : null;
        if ($mode === 'choice' && $options === null) {
            if ($item->kind === 'radical') {
                return null;
            }
            $mode = 'typed';
        }

        return $this->pending('quiz', $item, $review, $pick, $field, $mode, $options, $mode === 'choice' ? $correct : null, $expected);
    }

    /**
     * Whether a stored pending card may still be shown (its reference is still verified and linked).
     *
     * @param  array<string, mixed>  $pending
     */
    public function isValid(array $pending): bool
    {
        $item = Item::query()->whereKey($pending['item_id'])->first();

        return $item !== null && $this->picker->isShowable($item, $pending['reference_id']);
    }

    /**
     * @param  array<string, mixed>  $pending
     * @return array<string, mixed>
     */
    public function props(array $pending): array
    {
        [$item, $reference, $occurrence] = $this->load($pending);
        $links = $this->picker->links($item, $reference->id);
        $quiz = $pending['type'] === 'quiz';
        $talent = $occurrence?->talent;

        $card = [
            'type' => $pending['type'],
            'item' => ['id' => $item->id, 'kind' => $item->kind, 'characters' => $item->characters, 'is_special' => $item->is_special],
            'reference' => ['id' => $reference->id, 'kind' => $reference->kind, 'text_ja' => $reference->text_ja, 'highlight' => $links['positions']],
            'talent' => $talent === null ? null : [
                'name_ja' => $talent->name_ja,
                // a name reference's romaji name is its reading
                'name_en' => $quiz && $reference->kind === 'name' ? null : $talent->name_en,
                'photo_url' => $talent->photo_url,
            ],
            'field' => $pending['field'],
            'mode' => $pending['mode'],
            'options' => $pending['options'],
            'remaining_references' => $this->picker->remaining($item, $reference->id),
            'is_name_reading' => $reference->kind === 'name' && $pending['field'] === 'reading' && in_array($item->kind, ['kanji', 'vocab'], true),
        ];
        if (! $quiz) {
            $card['reveal'] = Reveal::build($item, $reference, $occurrence, $links['context_readings'][0] ?? null);
        }

        return $card;
    }

    /**
     * @param  array<string, mixed>  $pending
     * @return array<string, mixed>
     */
    public function reveal(array $pending): array
    {
        [$item, $reference, $occurrence] = $this->load($pending);

        return Reveal::build($item, $reference, $occurrence, $this->picker->links($item, $reference->id)['context_readings'][0] ?? null);
    }

    /**
     * @param  array<string, mixed>  $pending
     * @return array{Item, Reference, ?Occurrence}
     */
    private function load(array $pending): array
    {
        return [
            Item::query()->whereKey($pending['item_id'])->firstOrFail(),
            Reference::query()->with('annotation')->whereKey($pending['reference_id'])->firstOrFail(),
            $pending['occurrence_id'] === null ? null : Occurrence::query()->with('talent')->whereKey($pending['occurrence_id'])->first(),
        ];
    }

    /**
     * @param  array{reference: Reference, occurrence: ?Occurrence}  $pick
     * @param  ?list<string>  $options
     * @param  list<string>  $expected
     * @return array<string, mixed>
     */
    private function pending(string $type, Item $item, ?Review $review, array $pick, string $field, string $mode, ?array $options, ?string $correct, array $expected): array
    {
        return [
            'type' => $type,
            'item_id' => $item->id,
            'review_id' => $review?->id,
            'reference_id' => $pick['reference']->id,
            'occurrence_id' => $pick['occurrence']?->id,
            'field' => $field,
            'mode' => $mode,
            'options' => $options,
            'correct_option' => $correct,
            'expected' => $expected,
            'issued_at' => now()->toIso8601String(),
        ];
    }
}
