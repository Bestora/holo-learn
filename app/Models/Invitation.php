<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $token
 * @property bool $is_admin
 * @property Carbon|null $used_at
 */
#[Fillable(['token', 'is_admin', 'used_at'])]
class Invitation extends Model
{
    protected function casts(): array
    {
        return [
            'is_admin' => 'boolean',
            'used_at' => 'datetime',
        ];
    }
}
