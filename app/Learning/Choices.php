<?php

namespace App\Learning;

use App\Models\Item;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Random\Randomizer;

/** Four shuffled options for a choice card: the correct one plus three distractors. */
class Choices
{
    private const NEAREST = 8;

    private const MEANING_GROUPS = ['kanji', 'vocab', 'loanword', 'radical'];

    public function __construct(private Randomizer $random) {}

    /**
     * Null when fewer than three distractors exist (the caller falls back to typed or skips).
     * Distractors are distinct after normalisation and never accepted as correct.
     *
     * @param  list<string>  $expected
     * @return ?list<string>
     */
    public function options(Item $item, string $field, string $correct, array $expected): ?array
    {
        $options = [$correct];
        $keys = [$this->key($field, $correct)];
        $others = Item::query()->whereKeyNot($item->id)->get();

        foreach ($this->groups($item, $field) as $groups) {
            foreach ($this->candidates($item, $field, $others->filter(fn (Item $o) => in_array(Question::group($o), $groups, true))) as $candidate) {
                $key = $this->key($field, $candidate);
                $accepted = $field === 'meaning' ? AnswerChecker::meaning($candidate, $expected) : AnswerChecker::reading($candidate, $expected);
                if ($key === '' || in_array($key, $keys, true) || $accepted) {
                    continue;
                }
                $options[] = $candidate;
                $keys[] = $key;
                if (count($options) === 4) {
                    return $this->random->shuffleArray($options);
                }
            }
        }

        return null;
    }

    /**
     * Distractor sources in order: same group first, then other groups asking the same field.
     *
     * @return list<list<string>>
     */
    private function groups(Item $item, string $field): array
    {
        if ($field === 'reading') {
            return match ($item->kind) {
                'hiragana' => [['hiragana'], ['katakana']],
                'katakana' => [['katakana'], ['hiragana']],
                'kanji' => [['kanji'], ['vocab']],
                default => [['vocab'], ['kanji']],
            };
        }
        $own = Question::group($item);

        return [[$own], ...($own === 'loanword' ? [['vocab']] : []), array_values(array_diff(self::MEANING_GROUPS, [$own, ...($own === 'loanword' ? ['vocab'] : [])]))];
    }

    /**
     * One candidate string per item. Kana: random order; others: nearest levels first (random among equals).
     *
     * @param  Collection<int, Item>  $items
     * @return list<string>
     */
    private function candidates(Item $item, string $field, Collection $items): array
    {
        $items = $this->random->shuffleArray($items->values()->all());
        if (! Question::isKana($item)) {
            usort($items, fn (Item $a, Item $b) => abs($a->level - $item->level) <=> abs($b->level - $item->level)); // stable
            $items = array_slice(array_values(array_filter($items, fn (Item $o) => $this->candidate($o, $field) !== '')), 0, self::NEAREST);
        }
        if ($field === 'reading' && ! Question::isKana($item)) {
            (new EloquentCollection($items))->load(['references' => fn ($q) => $q->where('status', 'verified')]);
        }

        return array_values(array_filter(array_map(fn (Item $o) => $this->candidate($o, $field), $items), fn (string $c) => $c !== ''));
    }

    private function candidate(Item $item, string $field): string
    {
        if ($field === 'meaning') {
            return Question::displayMeaning($item);
        }
        $context = $item->relationLoaded('references') ? $item->references->pluck('pivot.context_reading')->filter()->first() : null;

        return $context ?? $item->readings[0] ?? '';
    }

    private function key(string $field, string $option): string
    {
        return $field === 'meaning' ? AnswerChecker::normalizeMeaning($option) : AnswerChecker::readingKey($option);
    }
}
