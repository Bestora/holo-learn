<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Normalizer;
use RuntimeException;

/**
 * A Japanese text (talent name or song title) that items are learned from.
 *
 * @property int $id
 * @property string $kind name|song
 * @property string $text_ja
 * @property string $text_key see keyFor()
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
            ['kind' => $kind, 'text_key' => self::keyFor($textJa)],
            ['text_ja' => $textJa, 'alt_text' => $alt],
        );

        if ($reference->alt_text === null && $alt !== null) {
            $reference->update(['alt_text' => $alt]);
        }

        return $reference;
    }

    /**
     * sha1 of the normalized text (NFKC, 〜 → ~, whitespace collapsed, Latin lowercased),
     * so "ＡＢＣ 花" and "abc 花" or "～" and "〜" spellings share one reference.
     */
    public static function keyFor(string $textJa): string
    {
        if (! class_exists(Normalizer::class)) {
            throw new RuntimeException('PHP-Erweiterung intl fehlt (Normalizer), nötig für Referenz-Schlüssel');
        }

        $text = (string) Normalizer::normalize($textJa, Normalizer::FORM_KC);
        $text = preg_replace('/\s+/u', ' ', str_replace('〜', '~', $text)) ?? $text;

        return sha1(strtolower(mb_trim($text)));
    }

    /** @return HasMany<Occurrence, $this> */
    public function occurrences(): HasMany
    {
        return $this->hasMany(Occurrence::class);
    }

    /** @return BelongsToMany<Talent, $this> */
    public function talents(): BelongsToMany
    {
        return $this->belongsToMany(Talent::class, 'occurrences');
    }

    /** @return HasOne<Annotation, $this> */
    public function annotation(): HasOne
    {
        return $this->hasOne(Annotation::class);
    }

    /** @return BelongsToMany<Item, $this> */
    public function items(): BelongsToMany
    {
        return $this->belongsToMany(Item::class)->withPivot('position', 'context_reading');
    }
}
