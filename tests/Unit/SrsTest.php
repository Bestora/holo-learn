<?php

namespace Tests\Unit;

use App\Learning\Srs;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SrsTest extends TestCase
{
    #[DataProvider('correctCases')]
    public function test_correct_moves_up_one_stage(int $stage, int $next, int $hours): void
    {
        $now = CarbonImmutable::parse('2026-11-23 12:00:00');

        $result = Srs::next($stage, true, $now);

        $this->assertSame($next, $result['stage']);
        $this->assertEquals($now->addHours($hours), $result['due_at']);
    }

    /** @return array<string, array{int, int, int}> */
    public static function correctCases(): array
    {
        return [
            '0→1' => [0, 1, 4], '1→2' => [1, 2, 8], '2→3' => [2, 3, 24], '3→4' => [3, 4, 48],
            '4→5' => [4, 5, 168], '5→6' => [5, 6, 336], '6→7' => [6, 7, 720], '7→8' => [7, 8, 2880],
            '8 stays' => [8, 8, 2880],
        ];
    }

    #[DataProvider('wrongCases')]
    public function test_wrong_drops_two_stages_and_is_due_now(int $stage, int $next): void
    {
        $now = CarbonImmutable::parse('2026-11-23 12:00:00');

        $result = Srs::next($stage, false, $now);

        $this->assertSame($next, $result['stage']);
        $this->assertEquals($now, $result['due_at']);
    }

    /** @return array<string, array{int, int}> */
    public static function wrongCases(): array
    {
        return ['0 stays 0' => [0, 0], '1→0' => [1, 0], '2→0' => [2, 0], '5→3' => [5, 3], '8→6' => [8, 6]];
    }
}
