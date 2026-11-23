<?php

namespace Tests\Feature;

use App\Models\Annotation;
use App\Models\Item;
use App\Models\Occurrence;
use App\Models\Reference;
use App\Models\Review;
use App\Models\Talent;
use App\Models\User;
use Illuminate\Support\Collection;
use Random\Engine\Mt19937;
use Random\Randomizer;

/** Builds items, verified references, occurrences and learners directly (no verifier) for learning tests. */
trait BuildsLearning
{
    private int $sequence = 0;

    protected function seedRandom(int $seed = 1): void
    {
        $this->app->instance(Randomizer::class, new Randomizer(new Mt19937($seed)));
    }

    protected function learner(): User
    {
        return User::factory()->create();
    }

    /**
     * @param  list<string>  $readings
     * @param  list<string>  $meanings
     */
    protected function makeItem(string $kind, string $characters, array $readings = [], array $meanings = [], int $level = 0, bool $special = false): Item
    {
        return Item::query()->updateOrCreate(
            ['kind' => $kind, 'characters' => $characters],
            ['readings' => $readings, 'meanings' => $meanings, 'level' => $level, 'is_special' => $special],
        );
    }

    protected function talent(string $nameEn, ?int $priority = null, bool $secondary = false, ?string $group = null): Talent
    {
        return Talent::query()->create([
            'holodex_channel_id' => 'UC'.++$this->sequence, 'name_ja' => $nameEn.'（ja）', 'name_en' => $nameEn,
            'photo_url' => "https://example.test/{$this->sequence}.jpg", 'priority' => $priority, 'is_secondary' => $secondary,
            'group_name' => $group,
        ]);
    }

    /**
     * A reference linking the given items, with an annotation and one live occurrence (unless $talent is false).
     *
     * @param  list<array{0: Item, 1: int, 2?: ?string}>  $links  [item, position, context_reading]
     * @param  ?list<array{0: string, 1: string, 2?: int}>  $words  [surface, reading, unit]; default: the whole text as one word
     */
    protected function makeRef(array $links, string $status = 'verified', string $kind = 'song', ?string $text = null, Talent|false|null $talent = null, ?string $alt = null, ?array $words = null): Reference
    {
        $text ??= 'テキスト'.++$this->sequence;
        $reference = Reference::query()->create([
            'kind' => $kind, 'text_ja' => $text, 'text_key' => Reference::keyFor($text), 'alt_text' => $alt ?? 'Alt '.$this->sequence, 'status' => $status,
        ]);
        Annotation::query()->create([
            'reference_id' => $reference->id, 'reading_kana' => 'てきすと', 'meaning_en' => 'Text '.$this->sequence,
            'alt_kind' => 'translation', 'model' => 'test', 'words' => array_map(fn (array $w) => ['surface' => $w[0], 'reading' => $w[1], 'ok' => true] + (isset($w[2]) ? ['unit' => $w[2]] : []), $words ?? [[$text, 'てきすと']]),
        ]);
        foreach ($links as $link) {
            $link[0]->references()->attach($reference->id, ['position' => $link[1], 'context_reading' => $link[2] ?? null]);
        }
        if ($talent !== false) {
            $this->occurrence($reference, $talent ?? $this->talent('Talent '.$this->sequence));
        }

        return $reference;
    }

    protected function occurrence(Reference $reference, Talent $talent, bool $dead = false): Occurrence
    {
        return Occurrence::query()->create([
            'reference_id' => $reference->id, 'talent_id' => $talent->id, 'video_id' => 'vid'.++$this->sequence,
            'start_s' => 10, 'end_s' => 20, 'source_id' => 'src'.$this->sequence, 'dead_at' => $dead ? now() : null,
        ]);
    }

    /** @return Collection<int, Reference> */
    protected function withRefs(Item $item, int $count, string $status = 'verified', ?string $context = null, ?Talent $talent = null): Collection
    {
        return collect(range(1, $count))->map(fn () => $this->makeRef([[$item, 0, $context]], $status, talent: $talent));
    }

    protected function review(User $user, Item $item, int $stage = 0, mixed $dueAt = null): Review
    {
        return Review::query()->create(['user_id' => $user->id, 'item_id' => $item->id, 'stage' => $stage, 'due_at' => $dueAt ?? now()->subHour()]);
    }

    /** @return array<string, mixed> */
    protected function learnProps(): array
    {
        return $this->get(route('learn.index'))->assertOk()->viewData('page')['props'];
    }

    /** The next card, dismissing a summary first. @return array<string, mixed> */
    protected function nextCard(): ?array
    {
        $props = $this->learnProps();
        if ($props['summary'] !== null) {
            $this->post(route('learn.start'))->assertRedirect(route('learn.index'));
            $props = $this->learnProps();
        }

        return $props['card'];
    }

    /** @return array<string, mixed> */
    protected function answer(int $itemId, string $answer): array
    {
        return $this->postJson(route('learn.answer'), ['item_id' => $itemId, 'answer' => $answer])->assertOk()->json();
    }

    protected function confirmIntro(int $itemId): void
    {
        $this->post(route('learn.intro'), ['item_id' => $itemId])->assertRedirect(route('learn.index'));
    }
}
