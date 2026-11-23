<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A user's spaced-repetition state for one item.
 *
 * @property int $id
 * @property int $user_id
 * @property int $item_id
 * @property int $stage 0–8
 * @property Carbon $due_at
 * @property ?int $last_reference_id
 * @property Carbon $created_at
 */
#[Fillable(['user_id', 'item_id', 'stage', 'due_at', 'last_reference_id'])]
class Review extends Model
{
    protected function casts(): array
    {
        return ['stage' => 'integer', 'due_at' => 'datetime'];
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
