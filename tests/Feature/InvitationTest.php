<?php

namespace Tests\Feature;

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    use RefreshDatabase;

    private function validUserData(): array
    {
        return [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ];
    }

    public function test_rejects_registration_without_invite()
    {
        $this->post('/register', $this->validUserData())->assertSessionHasErrors('invite');
        $this->assertSame(0, User::count());
    }

    public function test_registers_with_a_valid_invite_and_marks_it_used()
    {
        $inv = Invitation::create(['token' => 'abc', 'is_admin' => true]);

        $this->post('/register', $this->validUserData() + ['invite' => 'abc'])->assertRedirect();

        $this->assertTrue(User::first()->is_admin);
        $this->assertNotNull($inv->fresh()->used_at);
    }

    public function test_rejects_a_used_invite()
    {
        Invitation::create(['token' => 'abc', 'used_at' => now()]);

        $this->post('/register', $this->validUserData() + ['invite' => 'abc'])->assertSessionHasErrors('invite');
        $this->assertSame(0, User::count());
    }

    public function test_register_page_passes_invite_from_query()
    {
        $this->get('/register?invite=abc')
            ->assertInertia(fn (Assert $page) => $page->component('auth/register')->where('invite', 'abc'));
    }

    public function test_prints_an_invite_url()
    {
        $this->artisan('invite:create')->expectsOutputToContain('/register?invite=')->assertSuccessful();
    }
}
