<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $holodex_channel_id
 * @property ?string $name_ja
 * @property string $name_en
 * @property ?string $group_name
 * @property ?string $photo_url
 * @property bool $inactive
 * @property bool $is_secondary
 * @property ?int $priority
 */
#[Table('talents')] // the inflector treats "talent" as uncountable
#[Fillable(['holodex_channel_id', 'name_ja', 'name_en', 'group_name', 'photo_url', 'inactive', 'is_secondary', 'priority'])]
class Talent extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'inactive' => 'boolean',
            'is_secondary' => 'boolean',
            'priority' => 'integer',
        ];
    }

    /** @return HasMany<Occurrence, $this> */
    public function occurrences(): HasMany
    {
        return $this->hasMany(Occurrence::class);
    }

    /** @return BelongsToMany<Reference, $this> */
    public function references(): BelongsToMany
    {
        return $this->belongsToMany(Reference::class, 'occurrences')->distinct();
    }
}
