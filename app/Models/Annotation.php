<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reading, meaning and word segmentation of a reference, produced by an AI agent.
 *
 * @property int $id
 * @property int $reference_id
 * @property string $reading_kana hiragana
 * @property string $meaning_en
 * @property list<array{surface: string, reading: string, lemma?: string, lemma_reading?: string, ok?: bool}> $words
 * @property string $alt_kind romaji|translation|none
 * @property string $model
 * @property ?array<mixed> $check_errors
 */
#[Fillable(['reference_id', 'reading_kana', 'meaning_en', 'words', 'alt_kind', 'model', 'check_errors'])]
class Annotation extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'words' => 'array',
            'check_errors' => 'array',
        ];
    }

    /** @return BelongsTo<Reference, $this> */
    public function reference(): BelongsTo
    {
        return $this->belongsTo(Reference::class);
    }
}
