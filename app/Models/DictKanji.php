<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $character
 * @property array{on: list<string>, kun: list<string>, nanori: list<string>, plain: list<string>} $readings
 * @property list<string> $meanings
 * @property list<string> $radicals
 */
class DictKanji extends Model
{
    protected $table = 'dict_kanji';

    protected $primaryKey = 'character';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'readings' => 'array',
            'meanings' => 'array',
            'radicals' => 'array',
        ];
    }
}
