<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $kind hiragana|katakana|radical|kanji|vocab
 * @property string $characters
 * @property list<string> $readings
 * @property list<string> $meanings
 * @property int $level learned in ascending order, see App\Learning\ItemLevel
 * @property bool $is_special optional bonus item
 */
#[Fillable(['kind', 'characters', 'readings', 'meanings', 'level', 'is_special'])]
class Item extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'readings' => 'array',
            'meanings' => 'array',
            'is_special' => 'boolean',
        ];
    }

    /** @return BelongsToMany<Reference, $this> */
    public function references(): BelongsToMany
    {
        return $this->belongsToMany(Reference::class)->withPivot('position', 'context_reading');
    }
}
