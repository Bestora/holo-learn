<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $character
 * @property array{on: list<string>, kun: list<string>, nanori: list<string>, plain: list<string>} $readings
 * @property list<string> $meanings
 * @property list<string> $radicals
 * @property ?int $jlpt KANJIDIC2 old four-level scale: 4 = easiest … 1 = hardest
 * @property ?int $grade
 * @property ?int $frequency rank among the 2500 most used kanji
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
