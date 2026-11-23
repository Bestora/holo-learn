<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $spelling
 * @property string $reading
 * @property list<list<string>> $glosses English glosses per sense
 * @property bool $is_name
 */
class DictWord extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'glosses' => 'array',
            'is_name' => 'boolean',
        ];
    }
}
