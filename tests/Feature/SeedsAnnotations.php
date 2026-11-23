<?php

namespace Tests\Feature;

use App\Annotations\Decomposer;
use App\Annotations\Verifier;
use App\Models\Annotation;
use App\Models\DictKanji;
use App\Models\DictWord;
use App\Models\Reference;
use Database\Seeders\KanaSeeder;

/** Small dictionary and helpers for verifier/decomposer tests. */
trait SeedsAnnotations
{
    protected function seedDictionary(): void
    {
        $this->seed(KanaSeeder::class);

        foreach ([
            ['花', 'はな', [['flower', 'blossom'], ['beauty']]],
            ['亡霊', 'ぼうれい', [['ghost', 'departed spirit']]],
            ['今日', 'きょう', [['today']]],
            ['今日', 'こんにち', [['today'], ['these days']]],
            ['宇宙', 'うちゅう', [['universe', 'cosmos', 'space']]],
            ['明日', 'あした', [['tomorrow']]],
            ['明日', 'あす', [['tomorrow'], ['near future']]],
            ['我ら', 'われら', [['we', 'us']]],
            ['海賊団', 'かいぞくだん', [['pirate crew']]],
            ['歌う', 'うたう', [['to sing']]],
            ['来る', 'くる', [['to come']]],
            ['桃鈴', 'ももすず', [['Momosuzu']], true],
            ['桃鈴', 'とうり', [['Touri']], true],
            ['小鳥遊', 'たかなし', [['Takanashi']], true],
        ] as $row) {
            DictWord::query()->forceCreate([
                'spelling' => $row[0], 'reading' => $row[1], 'glosses' => $row[2], 'is_name' => $row[3] ?? false,
            ]);
        }

        foreach ([
            ['花', ['か', 'け', 'はな'], ['flower'], ['亻', '匕', '艹']],
            ['亡', ['ぼう', 'もう', 'ない'], ['deceased', 'dying'], ['亠', '乚']],
            ['霊', ['れい', 'りょう', 'たま'], ['spirits', 'soul'], ['一', '雨', '业']],
            ['今', ['こん', 'きん', 'いま'], ['now'], ['𠆢', '一', '乙']],
            ['日', ['にち', 'じつ', 'ひ', 'か'], ['day', 'sun'], ['日']],
            ['宇', ['う'], ['eaves', 'roof'], ['宀', '于']],
            ['宙', ['ちゅう'], ['mid-air'], ['宀', '由']],
            ['桃', ['とう', 'もも'], ['peach'], ['木', '儿', '冫']],
            ['鈴', ['れい', 'りん', 'すず'], ['small bell'], ['金', '𠆢', '一', '卩']],
            ['宝', ['ほう', 'たから'], ['treasure'], ['宀', '王', '丶']],
            ['鐘', ['しょう', 'かね'], ['bell'], ['金', '立', '日', '里']],
            ['我', ['が', 'われ'], ['ego', 'I'], ['戈', '手']],
            ['海', ['かい', 'うみ'], ['sea', 'ocean'], ['氵', '毋']],
            ['賊', ['ぞく'], ['burglar'], ['貝', '戈', '十']],
            ['団', ['だん', 'とん'], ['group'], ['囗', '寸']],
            ['歌', ['か', 'うた'], ['song', 'sing'], ['欠', '口', '亅']],
            ['来', ['らい', 'く'], ['come'], ['木', '丷']],
            ['匕', ['ひ'], ['spoon'], ['匕']],
            ['艸', ['そう'], ['grass', 'grass radical (no. 140)'], ['艸']],
            ['艹', [], ['grass', 'radical number 140'], ['艹']],
            ['亻', [], ['radical number 9'], ['亻']],
            ['儿', [], ['legs radical (no. 10)'], ['儿']],
            ['小', ['しょう', 'ちい'], ['little', 'small'], ['小']],
            ['鳥', ['ちょう', 'とり'], ['bird'], ['鳥']],
            ['遊', ['ゆう', 'あそ'], ['play'], ['辶', '方', '子']],
            ['人', ['じん', 'ひと'], ['person'], ['人']],
            ['水', ['すい', 'みず'], ['water'], ['水']],
            ['一', ['いち', 'ひと'], ['one'], ['一']],
        ] as [$character, $plain, $meanings, $radicals]) {
            DictKanji::query()->forceCreate([
                'character' => $character, 'readings' => ['on' => [], 'kun' => [], 'nanori' => [], 'plain' => $plain],
                'meanings' => $meanings, 'radicals' => $radicals,
            ]);
        }
    }

    /**
     * @param  list<array{0: string, 1: string, 2?: string, 3?: string}>  $words  [surface, reading, lemma?, lemma_reading?]
     */
    protected function annotated(string $kind, string $text, string $reading, array $words, ?string $alt = null, string $altKind = 'translation'): Reference
    {
        $reference = Reference::query()->create([
            'kind' => $kind, 'text_ja' => $text, 'text_key' => Reference::keyFor($text), 'alt_text' => $alt, 'status' => 'annotated',
        ]);
        $this->annotate($reference, $reading, $words, $altKind);

        return $reference;
    }

    /** @param list<array{0: string, 1: string, 2?: string, 3?: string}> $words */
    protected function annotate(Reference $reference, string $reading, array $words, string $altKind = 'translation'): void
    {
        Annotation::query()->updateOrCreate(['reference_id' => $reference->id], [
            'reading_kana' => $reading, 'meaning_en' => 'm', 'alt_kind' => $altKind, 'model' => 'test',
            'words' => array_map(fn (array $w) => array_filter([
                'surface' => $w[0], 'reading' => $w[1], 'lemma' => $w[2] ?? null, 'lemma_reading' => $w[3] ?? null,
            ], fn ($v) => $v !== null), $words),
        ]);
        $reference->unsetRelation('annotation');
    }

    /** @return list<string> */
    protected function verify(Reference $reference): array
    {
        $errors = app(Verifier::class)->verify($reference);
        app(Decomposer::class)->decompose($reference);
        $reference->refresh();

        return $errors;
    }

    /** @return list<bool> */
    protected function okFlags(Reference $reference): array
    {
        return array_column($reference->annotation()->sole()->words, 'ok');
    }

    /**
     * Links as "kind:characters@position=context_reading", sorted.
     *
     * @return list<string>
     */
    protected function links(Reference $reference, ?string $kind = null): array
    {
        $links = $reference->items()->get()
            ->filter(fn ($item) => $kind === null || $item->kind === $kind)
            ->map(fn ($item) => "{$item->kind}:{$item->characters}@{$item->pivot->position}".($item->pivot->context_reading === null ? '' : "={$item->pivot->context_reading}"))
            ->sort()->values()->all();

        return $links;
    }
}
