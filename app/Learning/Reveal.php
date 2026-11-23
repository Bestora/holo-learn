<?php

namespace App\Learning;

use App\Japanese\Kana;
use App\Models\Item;
use App\Models\Occurrence;
use App\Models\Reference;

/** The answer side of a card: shown on intro cards and after answering. */
class Reveal
{
    /**
     * Without a reference (a kana shown alone) reading and romaji come from the item.
     *
     * @return array<string, mixed>
     */
    public static function build(Item $item, ?Reference $reference, ?Occurrence $occurrence, ?string $contextReading): array
    {
        $annotation = $reference?->annotation;
        $talent = $occurrence?->talent;
        $words = array_map(fn (array $w) => [
            'surface' => $w['surface'], 'reading' => $w['reading'], 'romaji' => Kana::toRomaji($w['reading']), 'ok' => (bool) ($w['ok'] ?? false),
        ], $annotation->words ?? []);

        return [
            'item' => ['readings' => $item->readings, 'meanings' => Question::meaningsForDisplay($item), 'level' => $item->level, 'is_special' => $item->is_special],
            'reading' => $reference === null ? $item->characters : $annotation?->reading_kana,
            'romaji' => match (true) {
                $reference === null => $item->readings[0] ?? Kana::toRomaji($item->characters),
                $words !== [] => implode(' ', array_column($words, 'romaji')), // hoshimachi suisei
                default => $annotation ? Kana::toRomaji($annotation->reading_kana) : null,
            },
            'meaning' => $annotation?->meaning_en,
            'alt_text' => $reference?->alt_text,
            'alt_kind' => $annotation?->alt_kind,
            'is_name' => $reference?->kind === 'name',
            'words' => $words,
            'context_reading' => $contextReading,
            'occurrence' => $occurrence === null || $occurrence->dead_at !== null ? null : [
                'id' => $occurrence->id, 'video_id' => $occurrence->video_id, 'start_s' => $occurrence->start_s, 'end_s' => $occurrence->end_s,
                'talent' => $talent ? ['name_ja' => $talent->name_ja, 'name_en' => $talent->name_en, 'photo_url' => $talent->photo_url] : null,
            ],
        ];
    }
}
