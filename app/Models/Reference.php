<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Japanese text (talent name or song title) that items are learned from.
 *
 * @property int $id
 * @property string $kind name|song
 * @property string $text_ja
 * @property string $text_key sha1 of text_ja
 * @property ?string $alt_text
 * @property string $status new|annotated|verified|rejected
 */
#[Table('refs')] // `references` is a reserved word in SQL
#[Fillable(['kind', 'text_ja', 'text_key', 'alt_text', 'status'])]
class Reference extends Model
{
    public $timestamps = false;

    protected $attributes = ['status' => 'new'];

    public static function firstOrCreateFor(string $kind, string $textJa, ?string $alt): self
    {
        $reference = self::query()->firstOrCreate(
            ['kind' => $kind, 'text_key' => sha1($textJa)],
            ['text_ja' => $textJa, 'alt_text' => $alt],
        );

        if ($reference->alt_text === null && $alt !== null) {
            $reference->update(['alt_text' => $alt]);
        }

        return $reference;
    }

    /** @return HasMany<Occurrence, $this> */
    public function occurrences(): HasMany
    {
        return $this->hasMany(Occurrence::class);
    }

    /** @return BelongsToMany<Item, $this> */
    public function items(): BelongsToMany
    {
        return $this->belongsToMany(Item::class)->withPivot('position', 'context_reading');
    }
}
