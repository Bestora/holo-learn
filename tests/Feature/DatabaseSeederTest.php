<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_test_user_only_outside_production()
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->app->make(DatabaseSeeder::class)->setContainer($this->app)->__invoke();
        $this->assertFalse(User::query()->where('email', 'test@example.com')->exists());

        $this->app->detectEnvironment(fn () => 'testing');
        $this->app->make(DatabaseSeeder::class)->setContainer($this->app)->__invoke();
        $this->assertTrue(User::query()->where('email', 'test@example.com')->exists());
    }
}
