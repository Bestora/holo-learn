<?php

namespace App\Learning;

use App\Japanese\Kana;
use App\Models\Item;
use App\Models\Occurrence;
use App\Models\Reference;

/** The answer side of a card: shown on intro cards and after answering. */
class Reveal
{
    /** @return array<string, mixed> */
    public static function build(Item $item, Reference $reference, ?Occurrence $occurrence, ?string $contextReading): array
    {
        $annotation = $reference->annotation;
        $talent = $occurrence?->talent;

        return [
            'item' => ['readings' => $item->readings, 'meanings' => $item->meanings, 'level' => $item->level, 'is_special' => $item->is_special],
            'reading' => $annotation?->reading_kana,
            'romaji' => $annotation ? Kana::toRomaji($annotation->reading_kana) : null,
            'meaning' => $annotation?->meaning_en,
            'alt_text' => $reference->alt_text,
            'alt_kind' => $annotation?->alt_kind,
            'is_name' => $reference->kind === 'name',
            'words' => array_map(fn (array $w) => [
                'surface' => $w['surface'], 'reading' => $w['reading'], 'romaji' => Kana::toRomaji($w['reading']), 'ok' => (bool) ($w['ok'] ?? false),
            ], $annotation->words ?? []),
            'context_reading' => $contextReading,
            'occurrence' => $occurrence === null || $occurrence->dead_at !== null ? null : [
                'id' => $occurrence->id, 'video_id' => $occurrence->video_id, 'start_s' => $occurrence->start_s, 'end_s' => $occurrence->end_s,
                'talent' => $talent ? ['name_ja' => $talent->name_ja, 'name_en' => $talent->name_en, 'photo_url' => $talent->photo_url] : null,
            ],
        ];
    }
}
