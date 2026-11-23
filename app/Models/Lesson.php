<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A run of up to `size` cards. Layout of `state`: see App\Learning\LessonFlow.
 *
 * @property int $id
 * @property int $user_id
 * @property int $size
 * @property int $position cards completed
 * @property int $correct
 * @property int $wrong
 * @property int $new_items
 * @property array<string, mixed> $state
 * @property ?CarbonImmutable $finished_at
 */
#[Fillable(['user_id', 'size', 'position', 'correct', 'wrong', 'new_items', 'state', 'finished_at'])]
class Lesson extends Model
{
    protected $attributes = ['position' => 0, 'correct' => 0, 'wrong' => 0, 'new_items' => 0];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'position' => 'integer',
            'correct' => 'integer',
            'wrong' => 'integer',
            'new_items' => 'integer',
            'state' => 'array',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
