<?php

namespace App\Learning;

use Carbon\CarbonImmutable;

/** Fixed spaced-repetition stages: correct = one up, wrong = two down and due again at once. */
class Srs
{
    public const MAX_STAGE = 8;

    /** @return array{stage: int, due_at: CarbonImmutable} */
    public static function next(int $stage, bool $correct, CarbonImmutable $now): array
    {
        if (! $correct) {
            return ['stage' => max(0, $stage - 2), 'due_at' => $now];
        }

        $stage = min(self::MAX_STAGE, $stage + 1);

        /** @var list<int> $hours */
        $hours = config('hololearn.intervals_hours');

        return ['stage' => $stage, 'due_at' => $now->addHours($hours[$stage - 1])];
    }
}
