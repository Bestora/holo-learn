<?php

namespace App\Learning;

use App\Annotations\Verifier;
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
            'reference' => [
                'id' => $reference->id, 'kind' => $reference->kind, 'text_ja' => $reference->text_ja,
                'highlight' => $links['positions'], 'word' => $this->word($item, $reference, $pending['field'], $links['positions']),
            ],
            // quiz: no name, a talent's name can spell the asked reading (Momosuzu Nene → ね)
            'talent' => match (true) {
                $talent === null => null,
                $quiz => ['photo_url' => $talent->photo_url],
                default => ['name_ja' => $talent->name_ja, 'name_en' => $talent->name_en, 'photo_url' => $talent->photo_url],
            },
            'field' => $pending['field'],
            'mode' => $pending['mode'],
            'options' => $pending['options'],
            'remaining_references' => $this->picker->remaining($item, $reference->id),
            'is_name_reading' => $reference->kind === 'name' && $pending['field'] === 'reading' && $item->kind === 'kanji',
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
     * Positions of the whole word whose reading is asked: the annotation word holding the item (a kanji
     * in a name: its whole unit); vocab always its word. Kana, radicals and kanji meanings: the highlight,
     * as when the words do not align with the text.
     *
     * @param  list<int>  $highlight
     * @return list<int>
     */
    private function word(Item $item, Reference $reference, string $field, array $highlight): array
    {
        if ($highlight === [] || ! ($item->kind === 'vocab' || ($item->kind === 'kanji' && $field === 'reading'))) {
            return $highlight;
        }
        $words = $reference->annotation->words ?? [];
        $aligned = Verifier::align($reference->text_ja, array_column($words, 'surface'));
        foreach ($aligned ?? [] as $i => $chars) {
            if (in_array($highlight[0], array_column($chars, 1), true)) {
                $unit = $reference->kind === 'name' ? ($words[$i]['unit'] ?? null) : null;
                $indexes = $unit === null ? [$i] : array_keys(array_filter($words, fn (array $w) => ($w['unit'] ?? null) === $unit));
                $positions = array_unique(array_merge(...array_map(fn (int $j) => array_column($aligned[$j], 1), $indexes)));
                sort($positions);

                return $positions;
            }
        }

        return $highlight;
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
