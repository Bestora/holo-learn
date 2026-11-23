<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Where a reference appears: a talent's name, or a song in a video.
 *
 * @property int $id
 * @property int $reference_id
 * @property int $talent_id
 * @property ?string $video_id
 * @property ?int $start_s
 * @property ?int $end_s
 * @property string $source_id
 * @property ?Carbon $dead_at
 */
#[Fillable(['reference_id', 'talent_id', 'video_id', 'start_s', 'end_s', 'source_id', 'dead_at'])]
class Occurrence extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'start_s' => 'integer',
            'end_s' => 'integer',
            'dead_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Reference, $this> */
    public function reference(): BelongsTo
    {
        return $this->belongsTo(Reference::class);
    }

    /** @return BelongsTo<Talent, $this> */
    public function talent(): BelongsTo
    {
        return $this->belongsTo(Talent::class);
    }
}
