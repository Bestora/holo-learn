<?php

namespace Tests\Feature;

use App\Learning\AnswerChecker;
use App\Models\Item;
use App\Models\Lesson;
use App\Models\Reference;
use App\Models\Review;
use Carbon\CarbonImmutable;
use Database\Seeders\KanaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LearnTest extends TestCase
{
    use BuildsLearning;
    use RefreshDatabase;

    private const FORBIDDEN = ['reading', 'romaji', 'meaning', 'meanings', 'readings', 'context_reading', 'alt_text', 'words', 'occurrence', 'video_id', 'reveal', 'name_en', 'name_ja'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(KanaSeeder::class); // distractors for kana options
        $this->seedRandom();
        $this->freezeTime();
    }

    private function kana(string $characters): Item
    {
        return Item::query()->where('characters', $characters)->whereIn('kind', ['hiragana', 'katakana'])->sole();
    }

    /** Keeps the seeded kana (all eligible) from filling the lesson with intros. */
    private function noNewItems(): void
    {
        config(['hololearn.new_items_per_day' => 0]);
    }

    private function withoutKana(): void
    {
        Item::query()->whereIn('kind', ['hiragana', 'katakana'])->delete();
    }

    /** @param array<string, mixed> $card */
    private function rightAnswer(array $card): string
    {
        $item = Item::query()->findOrFail($card['item']['id']);
        if ($card['mode'] === 'typed') {
            return $card['field'] === 'meaning' ? $item->meanings[0] : $item->readings[0];
        }

        return collect($card['options'])->sole(fn ($o) => $card['field'] === 'meaning' ? AnswerChecker::meaning($o, $item->meanings) : AnswerChecker::reading($o, $item->readings));
    }

    /** @param array<string, mixed> $card */
    private function wrongAnswer(array $card): string
    {
        return $card['mode'] === 'typed' ? 'xq' : collect($card['options'])->first(fn ($o) => $o !== $this->rightAnswer($card));
    }

    /** @return list<string> every key anywhere in the tree */
    private function keys(mixed $tree): array
    {
        if (! is_array($tree)) {
            return [];
        }
        $keys = [];
        foreach ($tree as $key => $value) {
            if (is_string($key)) {
                $keys[] = $key;
            }
            array_push($keys, ...$this->keys($value));
        }

        return $keys;
    }

    public function test_guests_are_redirected(): void
    {
        $this->get(route('learn.index'))->assertRedirect(route('login'));
        $this->post(route('learn.intro'))->assertRedirect(route('login'));
        $this->post(route('learn.answer'))->assertRedirect(route('login'));
        $this->post(route('learn.override'))->assertRedirect(route('login'));
        $this->post(route('learn.start'))->assertRedirect(route('login'));
    }

    public function test_first_lesson_starts_with_an_intro_for_the_lowest_level_eligible_item(): void
    {
        $this->withRefs($this->kana('ネ'), 6);  // katakana: higher level
        $this->withRefs($this->kana('い'), 6);  // more references, but gojūon order first
        $this->withRefs($this->kana('あ'), 4);
        $this->withRefs($this->kana('の'), 2);  // too few verified ones
        $this->withRefs($this->kana('の'), 3, 'rejected');
        $this->withRefs($this->kana('の'), 3, 'annotated');
        $this->actingAs($this->learner());

        $this->get(route('learn.index'))->assertInertia(fn (AssertableInertia $page) => $page
            ->component('learn/index')
            ->where('card.type', 'intro')
            ->where('card.item.characters', 'あ')
            ->where('lesson.position', 0)
            ->where('summary', null)
            ->where('nothing_due', null));

        $props = $this->learnProps();
        $this->assertSame(3, $props['card']['remaining_references']);
        $this->assertSame([0], $props['card']['reference']['highlight']);
        $this->assertSame(['a'], $props['card']['reveal']['item']['readings']);
        $this->assertNotNull($props['card']['reveal']['occurrence']['video_id']);
    }

    public function test_only_verified_references_ever_appear(): void
    {
        $items = collect(['あ', 'い', 'う', 'え'])->map(fn ($c) => $this->kana($c));
        foreach ($items as $item) {
            $this->withRefs($item, 3);
            $this->withRefs($item, 4, 'rejected');
            $this->withRefs($item, 4, 'annotated');
        }
        $this->actingAs($this->learner());

        $seen = [];
        for ($i = 0; $i < 10 && ($card = $this->learnProps()['card']); $i++) {
            $seen[] = $card['reference']['id'];
            if ($card['type'] === 'intro') {
                $this->confirmIntro($card['item']['id']);
            } else {
                $this->answer($card['item']['id'], $this->wrongAnswer($card));
            }
        }

        $this->assertCount(10, $seen);
        $this->assertSame(['verified'], Reference::query()->whereIn('id', $seen)->distinct()->pluck('status')->all());
    }

    public function test_intro_is_followed_by_a_choice_quiz_that_leaks_no_answer_and_survives_reload(): void
    {
        $a = $this->kana('あ');
        $this->withRefs($a, 4);
        $this->actingAs($this->learner());

        $intro = $this->learnProps()['card'];
        $this->assertSame('intro', $intro['type']);
        $this->postJson(route('learn.answer'), ['item_id' => $a->id, 'answer' => 'a'])->assertStatus(422); // intro is not a quiz
        $this->confirmIntro($a->id);
        $this->postJson(route('learn.intro'), ['item_id' => $a->id])->assertStatus(422); // already confirmed

        $review = Review::query()->sole();
        $this->assertSame([0, $a->id], [$review->stage, $review->item_id]);
        $this->assertTrue($review->due_at->lte(now()));

        $props = $this->learnProps();
        $card = $props['card'];
        $this->assertSame(['quiz', 'reading', 'choice', $a->id], [$card['type'], $card['field'], $card['mode'], $card['item']['id']]);
        $this->assertNotSame($intro['reference']['id'], $card['reference']['id']); // no reference twice in a lesson
        $this->assertSame([], array_values(array_intersect(self::FORBIDDEN, $this->keys($card))));
        $this->assertSame(['id', 'kind', 'characters', 'is_special'], array_keys($card['item']));
        $this->assertSame(['photo_url'], array_keys($card['talent'])); // no name on quiz cards
        $this->assertArrayHasKey('name_en', $intro['talent']);
        $this->assertSame(1, $props['lesson']['position']);

        $this->assertSame($card, $this->learnProps()['card']); // same reference, same option order
        $this->assertSame($card, $this->learnProps()['card']);
    }

    public function test_answer_grades_updates_review_and_reveals(): void
    {
        $ne = $this->kana('ね');
        $this->withRefs($ne, 4);
        $user = $this->learner();
        $this->review($user, $ne, 2);
        $this->actingAs($user);

        $card = $this->learnProps()['card'];
        $this->assertSame('typed', $card['mode']);
        $result = $this->answer($ne->id, ' NE');

        $this->assertTrue($result['correct']);
        $this->assertSame(['ne'], $result['expected']);
        $this->assertFalse($result['can_override']);
        $this->assertSame('ne', $result['reveal']['item']['readings'][0]);
        $this->assertSame('tekisuto', $result['reveal']['romaji']);
        $review = Review::query()->sole();
        $this->assertSame(3, $review->stage);
        $this->assertSame($card['reference']['id'], $review->last_reference_id);
        $this->assertSame(now()->addHours(24)->toIso8601String(), $result['next_due_at']);

        $this->postJson(route('learn.answer'), ['item_id' => $ne->id, 'answer' => 'ne'])->assertStatus(422); // double submit
        $this->assertSame(3, $review->fresh()->stage);
        $this->assertSame(1, Lesson::query()->sole()->correct);
    }

    public function test_due_reviews_come_before_new_items(): void
    {
        $a = $this->kana('あ');
        $ka = $this->kana('カ');
        $this->withRefs($a, 3);
        $this->withRefs($ka, 3);
        $user = $this->learner();
        $this->review($user, $ka, 1);
        $this->actingAs($user);

        $card = $this->learnProps()['card'];

        $this->assertSame(['quiz', $ka->id], [$card['type'], $card['item']['id']]);
    }

    public function test_a_wrong_item_returns_after_two_other_cards_and_at_most_once(): void
    {
        $this->noNewItems();
        $user = $this->learner();
        $items = collect(['あ', 'い', 'う', 'え'])->map(fn ($c) => $this->kana($c));
        foreach ($items as $i => $item) {
            $this->withRefs($item, 3);
            $this->review($user, $item, 2, now()); // equal due times: ties go to the oldest review, so あ would win at once
        }
        $this->actingAs($user);

        $order = [];
        while ($card = $this->learnProps()['card']) {
            $order[] = $card['item']['characters'];
            $this->answer($card['item']['id'], $card['item']['characters'] === 'あ' ? 'xq' : $this->rightAnswer($card));
        }

        $this->assertSame(['あ', 'い', 'う', 'あ', 'え'], $order);
        $props = $this->learnProps();
        $this->assertSame(['correct' => 3, 'wrong' => 2, 'new_items' => 0, 'streak_days' => 1], $props['summary']);
        $this->assertNotNull(Lesson::query()->sole()->finished_at); // ended early with 5 cards
    }

    public function test_a_wrong_item_comes_back_early_when_nothing_else_is_left(): void
    {
        $this->noNewItems();
        $user = $this->learner();
        $a = $this->kana('あ');
        $this->withRefs($a, 3);
        $this->review($user, $a, 2);
        $this->actingAs($user);

        $this->learnProps();
        $this->answer($a->id, 'xq');
        $card = $this->learnProps()['card'];

        $this->assertSame($a->id, $card['item']['id']);
    }

    public function test_no_reference_twice_within_a_lesson(): void
    {
        $x = $this->kana('あ');
        $this->withRefs($x, 5);
        $this->withRefs($this->kana('ア'), 3);
        $this->actingAs($this->learner());

        $refs = [];
        while ($card = $this->learnProps()['card']) {
            if ($card['item']['id'] === $x->id) {
                $refs[] = $card['reference']['id'];
            }
            if ($card['type'] === 'intro') {
                $this->confirmIntro($card['item']['id']);
            } else {
                $this->answer($card['item']['id'], $card['item']['id'] === $x->id ? $this->wrongAnswer($card) : $this->rightAnswer($card));
            }
        }

        $this->assertCount(3, $refs); // intro, quiz, retry
        $this->assertSame($refs, array_values(array_unique($refs)));
    }

    public function test_never_the_same_reference_twice_in_a_row_across_twenty_reviews(): void
    {
        config(['hololearn.new_items_per_day' => 1]); // only あ
        $a = $this->kana('あ');
        $this->withRefs($a, 5);
        $this->actingAs($this->learner());

        $previous = null;
        for ($i = 0; $i < 20; $i++) {
            $card = $this->nextCard();
            if ($card['type'] === 'intro') {
                $this->confirmIntro($a->id);
                $this->noNewItems(); // only あ, also on later days
                $previous = $card['reference']['id'];
                $card = $this->nextCard();
            }
            $this->assertNotSame($previous, $card['reference']['id'], "review {$i}");
            $previous = $card['reference']['id'];
            $i % 3 === 2 ? $this->answer($a->id, $this->wrongAnswer($card)) : $this->answer($a->id, $this->rightAnswer($card));
            $this->travelTo(Review::query()->sole()->due_at->addMinute());
        }
    }

    public function test_dead_occurrences_give_a_text_only_reveal(): void
    {
        $ne = $this->kana('ね');
        foreach ($this->withRefs($ne, 3, talent: $this->talent('Nene')) as $reference) {
            $reference->occurrences()->update(['dead_at' => now()]);
        }
        $this->actingAs($this->learner());

        $card = $this->learnProps()['card'];

        $this->assertNull($card['reveal']['occurrence']);
        $this->assertNull($card['talent']);
    }

    public function test_an_occurrence_dying_after_issue_is_not_revealed(): void
    {
        $ne = $this->kana('ね');
        $this->withRefs($ne, 3);
        $user = $this->learner();
        $this->review($user, $ne, 2);
        $this->actingAs($user);

        Http::fake(['www.youtube.com/oembed*' => Http::response('Not Found', 404)]); // YouTube confirms it is gone
        $card = $this->learnProps()['card'];
        $reference = Reference::query()->findOrFail($card['reference']['id']);
        $this->post(route('occurrences.dead', $reference->occurrences()->sole()))->assertNoContent();

        $this->assertNull($this->answer($ne->id, 'ne')['reveal']['occurrence']);
    }

    public function test_reviewed_items_without_verified_references_are_skipped(): void
    {
        $this->noNewItems();
        $user = $this->learner();
        $a = $this->makeItem('kanji', '花', ['はな'], ['flower'], 10); // kana are never skipped: they show alone
        $i = $this->makeItem('kanji', '海', ['うみ'], ['sea'], 10);
        $aRefs = $this->withRefs($a, 3, context: 'はな');
        $this->withRefs($i, 3, context: 'うみ');
        $this->review($user, $a, 2, now()->subDays(2));
        $this->review($user, $i, 2, now()->subDay());
        Reference::query()->whereIn('id', $aRefs->pluck('id'))->update(['status' => 'rejected']);
        $this->actingAs($user);

        $this->assertSame($i->id, $this->learnProps()['card']['item']['id']);
    }

    public function test_a_pending_card_whose_reference_lost_verification_is_replaced(): void
    {
        $user = $this->learner();
        $a = $this->kana('あ');
        $this->withRefs($a, 4);
        $this->review($user, $a, 2);
        $this->actingAs($user);

        $first = $this->learnProps()['card'];
        Reference::query()->whereKey($first['reference']['id'])->update(['status' => 'rejected']);
        $second = $this->learnProps()['card'];

        $this->assertSame($a->id, $second['item']['id']);
        $this->assertNotSame($first['reference']['id'], $second['reference']['id']);
    }

    public function test_an_answer_to_a_card_whose_reference_lost_verification_is_refused(): void
    {
        $user = $this->learner();
        $a = $this->kana('あ');
        $this->withRefs($a, 4);
        $this->review($user, $a, 2);
        $this->actingAs($user);

        $card = $this->learnProps()['card'];
        Reference::query()->whereKey($card['reference']['id'])->update(['status' => 'rejected']);

        $this->postJson(route('learn.answer'), ['item_id' => $a->id, 'answer' => 'a'])->assertStatus(422); // its reveal is not shown
        $this->assertSame(2, Review::query()->sole()->stage);
        $this->assertNotSame($card['reference']['id'], $this->learnProps()['card']['reference']['id']);
    }

    public function test_nothing_due_reports_the_next_review(): void
    {
        $this->noNewItems();
        $user = $this->learner();
        $a = $this->kana('あ');
        $this->withRefs($a, 3);
        $this->review($user, $a, 3, now()->addHours(5));
        $this->actingAs($user);

        $props = $this->learnProps();

        $this->assertNull($props['card']);
        $this->assertNull($props['lesson']);
        $this->assertSame(now()->addHours(5)->toIso8601String(), $props['nothing_due']['next_due_at']);
    }

    public function test_radicals_without_meanings_are_never_offered(): void
    {
        $this->withoutKana();
        $empty = $this->makeItem('radical', '乙', [], [], 0);
        $this->withRefs($empty, 5);
        $this->actingAs($this->learner());

        $this->assertNull($this->learnProps()['card']);
    }

    public function test_question_field_and_mode_by_kind_and_stage(): void
    {
        $user = $this->learner();
        $kanji = $this->makeItem('kanji', '花', ['か', 'はな'], ['flower'], 10);
        $this->withRefs($kanji, 3, context: 'はな');
        $loan = $this->makeItem('vocab', 'アイドル', ['あいどる'], ['idol'], 2);
        $this->withRefs($loan, 3, context: 'あいどる');
        $radical = $this->makeItem('radical', '艹', [], ['grass'], 10);
        $this->withRefs($radical, 3);
        // distractor material
        foreach (['海' => ['うみ', 'sea'], '空' => ['そら', 'sky'], '星' => ['ほし', 'star'], '月' => ['つき', 'moon']] as $c => [$r, $m]) {
            $this->withRefs($this->makeItem('kanji', $c, [$r], [$m], 11), 1, context: $r);
        }
        foreach (['ミーム' => 'meme', 'ライフ' => 'life', 'パック' => 'pack'] as $c => $m) {
            $this->makeItem('vocab', $c, [], [$m], 2);
        }
        foreach (['氵' => 'water', '木' => 'tree', '口' => 'mouth'] as $c => $m) {
            $this->makeItem('radical', $c, [], [$m], 10);
        }
        $this->actingAs($user);

        $cases = [
            [$kanji, 0, 'reading', 'choice'], [$kanji, 1, 'meaning', 'choice'], [$kanji, 2, 'reading', 'typed'],
            [$kanji, 3, 'meaning', 'typed'], [$loan, 0, 'meaning', 'choice'], [$loan, 2, 'meaning', 'typed'],
            [$radical, 0, 'meaning', 'choice'], [$radical, 4, 'meaning', 'choice'],
            [$this->kana('ね'), 1, 'reading', 'choice'], [$this->kana('ね'), 2, 'reading', 'typed'],
        ];
        foreach ($cases as [$item, $stage, $field, $mode]) {
            if ($item->kind === 'hiragana') {
                $this->withRefs($item, 3);
            }
            Review::query()->delete();
            Lesson::query()->delete();
            $this->review($user, $item, $stage);

            $card = $this->learnProps()['card'];

            $this->assertSame([$item->id, $field, $mode], [$card['item']['id'], $card['field'], $card['mode']], "{$item->characters} stage {$stage}");
            $this->assertSame($mode === 'choice' ? 4 : null, $card['options'] === null ? null : count($card['options']));
            if ($mode === 'choice') {
                $this->assertSame($card['options'], array_values(array_unique($card['options'])));
                $correct = collect($card['options'])->filter(fn ($o) => $field === 'meaning'
                    ? AnswerChecker::meaning($o, $item->meanings)
                    : AnswerChecker::reading($o, $item->kind === 'kanji' ? ['はな'] : $item->readings));
                $this->assertCount(1, $correct, "{$item->characters} stage {$stage}");
            }
        }
    }

    public function test_kanji_reading_in_a_name_is_marked_and_expects_the_unit_reading(): void
    {
        $user = $this->learner();
        $kanji = $this->makeItem('kanji', '鳥', ['ちょう', 'とり'], ['bird'], 10);
        $this->makeRef([[$kanji, 1, 'たかなし']], kind: 'name', text: '小鳥遊キアラ', alt: 'Takanashi Kiara');
        $this->withRefs($kanji, 2, context: 'とり');
        $this->review($user, $kanji, 2);
        $this->actingAs($user);

        do {
            Lesson::query()->delete();
            $card = $this->learnProps()['card'];
        } while ($card['reference']['kind'] !== 'name');

        $this->assertTrue($card['is_name_reading']);
        $this->assertSame([1], $card['reference']['highlight']);
        $this->assertSame(['photo_url'], array_keys($card['talent'])); // the romaji name would give the reading away
        $result = $this->answer($kanji->id, 'takanashi');
        $this->assertTrue($result['correct']);
        $this->assertSame(['たかなし'], $result['expected']);
        $this->assertTrue($result['reveal']['is_name']);
        $this->assertSame('Takanashi Kiara', $result['reveal']['alt_text']);
        $this->assertSame('たかなし', $result['reveal']['context_reading']);
    }

    /** @return iterable<string, array{string, string, string, int, string, list<int>, list<int>, bool}> */
    public static function wordSpans(): iterable
    {
        // [ref kind, text, item, stage, field, highlight, word, is_name_reading]
        yield '亡 in a song, reading' => ['song', '花に亡霊', 'kanji:亡', 2, 'reading', [2], [2, 3], false];
        yield '亡 in a song, meaning' => ['song', '花に亡霊', 'kanji:亡', 3, 'meaning', [2], [2], false];
        yield '亡霊 vocab' => ['song', '花に亡霊', 'vocab:亡霊', 2, 'reading', [2], [2, 3], false];
        yield '小 in a name: whole unit' => ['name', '小鳥遊キアラ', 'kanji:小', 2, 'reading', [0], [0, 1, 2], true];
        yield '小 in a name, meaning' => ['name', '小鳥遊キアラ', 'kanji:小', 3, 'meaning', [0], [0], false];
        yield 'ね in a name' => ['name', '桃鈴ねね', 'hiragana:ね', 2, 'reading', [2, 3], [2, 3], false];
    }

    /**
     * @param  list<int>  $highlight
     * @param  list<int>  $word
     */
    #[DataProvider('wordSpans')]
    public function test_word_span_of_the_asked_reading(string $kind, string $text, string $item, int $stage, string $field, array $highlight, array $word, bool $nameReading): void
    {
        $refs = [
            '花に亡霊' => fn () => $this->makeRef([[$bou = $this->makeItem('kanji', '亡', ['ぼう'], ['deceased'], 10), 2, 'ぼうれい'],
                [$this->makeItem('vocab', '亡霊', ['ぼうれい'], ['ghost'], 11), 2, 'ぼうれい']], text: $text, words: [['花', 'はな'], ['に', 'に'], ['亡霊', 'ぼうれい']]),
            '小鳥遊キアラ' => fn () => $this->makeRef([[$this->makeItem('kanji', '小', ['しょう'], ['little'], 10), 0, 'たかなし']], kind: 'name', text: $text,
                words: [['小', 'たか', 0], ['鳥遊', 'なし', 0], ['キアラ', 'きあら', 1]]),
            '桃鈴ねね' => fn () => $this->makeRef([[$this->kana('ね'), 2], [$this->kana('ね'), 3]], kind: 'name', text: $text,
                words: [['桃鈴', 'ももすず', 0], ['ねね', 'ねね', 1]]),
        ];
        $refs[$text]();
        [$itemKind, $characters] = explode(':', $item);
        $user = $this->learner();
        $this->review($user, Item::query()->where(['kind' => $itemKind, 'characters' => $characters])->sole(), $stage);
        $this->actingAs($user);

        $card = $this->learnProps()['card'];

        $this->assertSame($field, $card['field']);
        $this->assertSame(['highlight' => $highlight, 'word' => $word], array_intersect_key($card['reference'], ['highlight' => 1, 'word' => 1]));
        $this->assertSame($nameReading, $card['is_name_reading']);
    }

    public function test_word_span_falls_back_to_highlight_when_words_do_not_align(): void
    {
        $kanji = $this->makeItem('kanji', '亡', ['ぼう'], ['deceased'], 10);
        $this->makeRef([[$kanji, 2, 'ぼうれい']], text: '花に亡霊', words: [['花', 'はな'], ['亡霊', 'ぼうれい']]);
        $user = $this->learner();
        $this->review($user, $kanji, 2);
        $this->actingAs($user);

        $this->assertSame([2], $this->learnProps()['card']['reference']['word']);
    }

    public function test_start_is_idempotent(): void
    {
        config(['hololearn.lesson_size' => 1]);
        $ne = $this->kana('ね');
        $this->withRefs($ne, 3);
        $user = $this->learner();
        $this->review($user, $ne, 2);
        $this->actingAs($user);

        $card = $this->learnProps()['card'];
        $this->post(route('learn.start'))->assertRedirect(route('learn.index'));
        $this->post(route('learn.start'))->assertRedirect(route('learn.index'));
        $this->assertSame(1, Lesson::query()->count()); // the open lesson stays
        $this->assertSame($card, $this->learnProps()['card']);

        $this->answer($ne->id, 'ne'); // finishes the lesson
        Review::query()->update(['due_at' => now()->subMinute()]);
        $this->post(route('learn.start'));
        $this->post(route('learn.start'));
        $this->assertNotNull($this->learnProps()['card']);
        $this->post(route('learn.start'));
        $this->assertSame(2, Lesson::query()->count());
        $this->assertSame(1, Lesson::query()->whereNull('finished_at')->count());
    }

    public function test_a_kana_without_references_is_taught_as_the_character_alone(): void
    {
        config(['hololearn.new_items_per_day' => 1]);
        $a = $this->kana('あ'); // first in gojūon order
        $user = $this->learner();
        $this->actingAs($user);

        $intro = $this->learnProps()['card'];
        $this->assertSame(['intro', $a->id, null, null, 0], [$intro['type'], $intro['item']['id'], $intro['reference'], $intro['talent'], $intro['remaining_references']]);
        $this->assertSame(['あ', 'a', [], null, null], [$intro['reveal']['reading'], $intro['reveal']['romaji'], $intro['reveal']['words'], $intro['reveal']['occurrence'], $intro['reveal']['alt_text']]);
        $this->confirmIntro($a->id);

        $quiz = $this->learnProps()['card'];
        $this->assertSame(['quiz', 'choice', null, null], [$quiz['type'], $quiz['mode'], $quiz['reference'], $quiz['talent']]);
        $this->assertContains('a', $quiz['options']);
        $result = $this->answer($a->id, 'a');
        $this->assertTrue($result['correct']);
        $this->assertNull($result['reveal']['occurrence']);
        $this->assertNull(Review::query()->sole()->last_reference_id);

        Review::query()->update(['stage' => 2, 'due_at' => now()->subMinute()]);
        $typed = $this->nextCard();
        $this->assertSame(['typed', null], [$typed['mode'], $typed['reference']]);
        $this->assertFalse($this->answer($a->id, 'i')['correct']);
    }

    public function test_reveal_lists_the_display_meaning_first_and_romaji_per_word(): void
    {
        config(['hololearn.new_items_per_day' => 0]);
        $user = $this->learner();
        $star = $this->makeItem('kanji', '星', ['ほし'], ['spot', 'star'], 10);
        $star->update(['meanings' => ['(celestial) star', 'spot', 'dot']]);
        $this->makeRef([[$star, 0, 'ほしまち']], kind: 'name', text: '星街すいせい', words: [['星街', 'ほしまち', 0], ['すいせい', 'すいせい', 1]]);
        $this->review($user, $star, 3);
        $this->actingAs($user);

        $this->learnProps();
        $reveal = $this->answer($star->id, 'star')['reveal'];

        $this->assertSame(['star', 'spot', 'dot'], $reveal['item']['meanings']); // '(celestial) star' shown as 'star'
        $this->assertSame('hoshimachi suisei', $reveal['romaji']);
        $this->assertSame(['hoshimachi', 'suisei'], array_column($reveal['words'], 'romaji'));
    }

    public function test_a_typo_that_spells_another_items_meaning_is_wrong(): void
    {
        config(['hololearn.new_items_per_day' => 0]);
        $user = $this->learner();
        $horse = $this->makeItem('kanji', '馬', ['うま'], ['horse'], 10);
        $this->withRefs($horse, 2, context: 'うま');
        $this->makeItem('kanji', '家', ['いえ'], ['house'], 10);
        $this->review($user, $horse, 3);
        $this->actingAs($user);

        $this->learnProps();
        $this->assertFalse($this->answer($horse->id, 'house')['correct']);

        Lesson::query()->delete();
        Review::query()->update(['stage' => 3, 'due_at' => now()->subMinute()]);
        $this->learnProps();
        $this->assertTrue($this->answer($horse->id, 'hors')['correct']);
    }

    public function test_a_typo_that_is_a_synonym_from_a_related_item_is_right(): void
    {
        config(['hololearn.new_items_per_day' => 0]);
        $user = $this->learner();
        $color = $this->makeItem('kanji', '色', ['いろ'], ['color'], 10);
        $this->withRefs($color, 2, context: 'いろ');
        $this->review($user, $color, 3);
        $this->actingAs($user);

        $sameCharacters = $this->makeItem('vocab', '色', ['いろ'], ['colour'], 10);
        $this->learnProps();
        $this->assertTrue($this->answer($color->id, 'colour')['correct']);

        $sameCharacters->delete();
        $this->makeItem('vocab', 'カラー', ['からー'], ['Color', 'colour'], 2); // shares 'color'
        Lesson::query()->delete();
        Review::query()->update(['stage' => 3, 'due_at' => now()->subMinute()]);
        $this->learnProps();
        $this->assertTrue($this->answer($color->id, 'colour')['correct']);
    }

    public function test_nothing_due_tells_when_new_items_return(): void
    {
        config(['hololearn.new_items_per_day' => 1]);
        $this->travelTo(CarbonImmutable::parse('2026-11-23 15:00', 'UTC'));
        $user = $this->learner();
        $this->actingAs($user);

        $this->confirmIntro($this->learnProps()['card']['item']['id']);
        $card = $this->learnProps()['card'];
        $this->answer($card['item']['id'], $this->rightAnswer($card));
        $this->post(route('learn.start')); // nothing to dismiss yet: lesson still open
        $this->assertNotNull($this->learnProps()['summary']);
        $this->post(route('learn.start'));

        $this->assertSame([
            'next_due_at' => now()->addHours(4)->toIso8601String(),
            'new_items_at' => '2026-11-23T23:00:00+00:00', // midnight in Berlin
        ], $this->learnProps()['nothing_due']);
    }

    public function test_next_due_at_is_never_in_the_past(): void
    {
        config(['hololearn.new_items_per_day' => 0]);
        $user = $this->learner();
        $radical = $this->makeItem('radical', '艹', [], ['grass'], 10);
        $this->withRefs($radical, 3);
        $this->review($user, $radical, 0, now()->subDay()); // no distractors: cannot become a card
        $later = $this->kana('ね');
        $this->review($user, $later, 3, now()->addHours(3));
        $this->actingAs($user);

        $this->assertSame(now()->addHours(3)->toIso8601String(), $this->learnProps()['nothing_due']['next_due_at']);
    }

    public function test_dismissing_the_summary_closes_the_override_window(): void
    {
        config(['hololearn.lesson_size' => 1, 'hololearn.new_items_per_day' => 0]);
        $user = $this->learner();
        $kanji = $this->makeItem('kanji', '花', ['はな'], ['flower'], 10);
        $this->withRefs($kanji, 3, context: 'はな');
        $this->review($user, $kanji, 3);
        $this->actingAs($user);

        $this->learnProps();
        $this->assertTrue($this->answer($kanji->id, 'xq')['can_override']);
        $this->post(route('learn.start'));

        $this->postJson(route('learn.override'))->assertStatus(422);
        $this->assertNull(Lesson::query()->sole()->state['last_answer']);
    }

    public function test_choice_falls_back_to_typed_without_enough_distractors(): void
    {
        $user = $this->learner();
        $kanji = $this->makeItem('kanji', '花', ['はな'], ['flower'], 10);
        $this->withRefs($kanji, 3, context: 'はな');
        $this->makeItem('kanji', '海', ['うみ'], ['sea'], 10);
        $this->review($user, $kanji, 0);
        $this->actingAs($user);

        $card = $this->learnProps()['card'];

        $this->assertSame(['reading', 'typed', null], [$card['field'], $card['mode'], $card['options']]);
    }

    public function test_a_radical_without_distractors_is_skipped(): void
    {
        $this->withoutKana();
        $user = $this->learner();
        $radical = $this->makeItem('radical', '艹', [], ['grass'], 10);
        $this->withRefs($radical, 3);
        $this->review($user, $radical, 0);
        $this->actingAs($user);

        $this->assertNull($this->learnProps()['card']);
    }

    public function test_override_regrades_a_wrong_typed_meaning(): void
    {
        $this->noNewItems();
        $user = $this->learner();
        $kanji = $this->makeItem('kanji', '花', ['はな'], ['flower'], 10);
        $this->withRefs($kanji, 3, context: 'はな');
        $this->review($user, $kanji, 3);
        $this->actingAs($user);

        $card = $this->learnProps()['card'];
        $this->assertSame(['meaning', 'typed'], [$card['field'], $card['mode']]);
        $result = $this->answer($kanji->id, 'blossom');
        $this->assertFalse($result['correct']);
        $this->assertTrue($result['can_override']);
        $this->assertSame(1, Review::query()->sole()->stage);
        $this->travel(5)->minutes();

        $this->postJson(route('learn.override'))->assertOk()->assertJson([
            'correct' => true, 'next_due_at' => now()->subMinutes(5)->addHours(48)->toIso8601String(),
        ]);

        $review = Review::query()->sole();
        $this->assertSame(4, $review->stage);
        $lesson = Lesson::query()->sole();
        $this->assertSame([1, 0], [$lesson->correct, $lesson->wrong]);
        $this->postJson(route('learn.override'))->assertStatus(422); // only once
        $this->assertNull($this->learnProps()['card']); // counted as correct: not asked again
    }

    public function test_override_is_refused_for_other_answers_and_after_the_next_card(): void
    {
        $user = $this->learner();
        $kanji = $this->makeItem('kanji', '花', ['はな'], ['flower'], 10);
        $this->withRefs($kanji, 3, context: 'はな');
        $ne = $this->kana('ね');
        $this->withRefs($ne, 3);
        $this->review($user, $kanji, 2, now()->subHours(2));
        $this->review($user, $ne, 2);
        $this->actingAs($user);

        $this->postJson(route('learn.override'))->assertStatus(422); // nothing answered
        $this->learnProps();
        $result = $this->answer($kanji->id, 'xq'); // typed reading
        $this->assertFalse($result['can_override']);
        $this->postJson(route('learn.override'))->assertStatus(422);

        Review::query()->where('item_id', $kanji->id)->update(['stage' => 3, 'due_at' => now()->subDay()]);
        Lesson::query()->delete();
        $this->learnProps();
        $this->assertTrue($this->answer($kanji->id, 'xq')['can_override']);
        $this->learnProps(); // issues the next card
        $this->postJson(route('learn.override'))->assertStatus(422);
    }

    public function test_a_lesson_finishes_after_ten_cards_with_summary_and_streak(): void
    {
        $user = $this->learner();
        foreach (['あ', 'い', 'う', 'え', 'お', 'か', 'き', 'く', 'け', 'こ', 'さ', 'し'] as $i => $c) {
            $this->withRefs($this->kana($c), 3);
            $this->review($user, $this->kana($c), 2, now()->subHours(20 - $i));
        }
        $this->actingAs($user);

        for ($i = 0; $i < 10; $i++) {
            $card = $this->learnProps()['card'];
            $this->answer($card['item']['id'], $i === 0 ? 'xq' : $this->rightAnswer($card));
        }

        $props = $this->learnProps();
        $this->assertNull($props['card']);
        $this->assertSame(['correct' => 9, 'wrong' => 1, 'new_items' => 0, 'streak_days' => 1], $props['summary']);
        $this->assertSame(10, $props['lesson']['position']);
        $this->assertSame(1, $user->fresh()->streak_days);
        $this->assertSame($props['summary'], $this->learnProps()['summary']); // summary stays until dismissed

        $this->post(route('learn.start'))->assertRedirect(route('learn.index'));
        $props = $this->learnProps();
        $this->assertNull($props['summary']);
        $this->assertSame(0, $props['lesson']['position']);
        $this->assertNotNull($props['card']);
    }

    /** @return iterable<string, array{?string, int, int}> */
    public static function streakCases(): iterable
    {
        yield 'first lesson' => [null, 0, 1];
        yield 'yesterday' => ['2026-11-22', 4, 5];
        yield 'gap' => ['2026-11-20', 4, 1];
        yield 'same day' => ['2026-11-23', 4, 4];
    }

    #[DataProvider('streakCases')]
    public function test_streak(?string $lastLessonOn, int $streak, int $expected): void
    {
        config(['hololearn.lesson_size' => 1]);
        $this->travelTo(CarbonImmutable::parse('2026-11-23 23:30', 'Europe/Berlin')); // 22:30 UTC
        $user = $this->learner();
        $user->forceFill(['streak_days' => $streak, 'last_lesson_on' => $lastLessonOn])->save();
        $ne = $this->kana('ね');
        $this->withRefs($ne, 3);
        $this->review($user, $ne, 2);
        $this->actingAs($user);

        $this->learnProps();
        $this->answer($ne->id, 'ne');

        $user->refresh();
        $this->assertSame([$expected, '2026-11-23'], [$user->streak_days, $user->last_lesson_on?->toDateString()]);
        $this->assertSame($expected, $this->learnProps()['summary']['streak_days']);
    }

    public function test_streak_counts_local_days(): void
    {
        config(['hololearn.lesson_size' => 1]);
        $user = $this->learner();
        $ne = $this->kana('ね');
        $this->withRefs($ne, 3);
        $this->actingAs($user);

        // 23:30 UTC on Nov 23 is already Nov 24 in Berlin
        $this->travelTo(CarbonImmutable::parse('2026-11-23 23:30', 'UTC'));
        $this->review($user, $ne, 2);
        $this->learnProps();
        $this->answer($ne->id, 'ne');
        $this->assertSame('2026-11-24', $user->fresh()->last_lesson_on?->toDateString());

        $this->travelTo(CarbonImmutable::parse('2026-11-25 12:00', 'UTC'));
        Review::query()->update(['due_at' => now()->subMinute()]);
        $this->nextCard();
        $this->answer($ne->id, 'ne');
        $this->assertSame(2, $user->fresh()->streak_days);
    }

    public function test_invalid_requests(): void
    {
        $this->actingAs($this->learner());

        $this->postJson(route('learn.answer'), [])->assertStatus(422)->assertJsonValidationErrors(['item_id']);
        $this->postJson(route('learn.intro'), ['item_id' => 999])->assertStatus(422);
        $this->postJson(route('learn.answer'), ['item_id' => 999, 'answer' => 'x'])->assertStatus(422);
    }
}
