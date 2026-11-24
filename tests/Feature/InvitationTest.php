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

    private function validUserData(string $email = 'test@example.com'): array
    {
        return [
            'name' => 'Test User',
            'email' => $email,
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
        $this->assertSame(1, $inv->fresh()->uses);
        $this->assertTrue(User::first()->invitation->is($inv));
        $this->assertSame(1, $inv->users()->count());
    }

    public function test_a_group_invite_can_be_used_up_to_max_uses()
    {
        Invitation::create(['token' => 'abc', 'max_uses' => 2]);

        $this->post('/register', $this->validUserData('a@example.com') + ['invite' => 'abc'])->assertSessionHasNoErrors();
        auth()->logout();
        $this->post('/register', $this->validUserData('b@example.com') + ['invite' => 'abc'])->assertSessionHasNoErrors();
        auth()->logout();
        $this->post('/register', $this->validUserData('c@example.com') + ['invite' => 'abc'])
            ->assertSessionHasErrors(['invite' => 'This invitation is invalid or has already been used.']);

        $this->assertSame(2, User::count());
        $this->assertSame(2, Invitation::first()->uses);
    }

    public function test_rejects_an_expired_invite()
    {
        Invitation::create(['token' => 'abc', 'max_uses' => 5, 'expires_at' => now()->subMinute()]);

        $this->post('/register', $this->validUserData() + ['invite' => 'abc'])->assertSessionHasErrors('invite');
        $this->assertSame(0, User::count());
    }

    public function test_redeemable_check_and_scope_agree()
    {
        $ok = Invitation::create(['token' => 'a', 'max_uses' => 2, 'uses' => 1, 'expires_at' => now()->addDay()]);
        $full = Invitation::create(['token' => 'b', 'max_uses' => 2, 'uses' => 2]);
        $expired = Invitation::create(['token' => 'c', 'expires_at' => now()->subDay()]);

        $this->assertTrue($ok->isRedeemable());
        $this->assertFalse($full->isRedeemable());
        $this->assertFalse($expired->isRedeemable());
        $this->assertSame([$ok->id], Invitation::redeemable()->pluck('id')->all());
    }

    public function test_a_concurrent_redemption_of_the_last_use_fails_cleanly()
    {
        Invitation::create(['token' => 'abc']);
        // Another registration consumes the last use between our read and our increment.
        Invitation::retrieved(fn (Invitation $i) => Invitation::whereKey($i->id)->update(['uses' => 1]));

        $this->post('/register', $this->validUserData() + ['invite' => 'abc'])
            ->assertSessionHasErrors(['invite' => 'This invitation is invalid or has already been used.']);

        $this->assertSame(0, User::count());
        Invitation::flushEventListeners();
    }

    public function test_rejects_a_used_invite()
    {
        Invitation::create(['token' => 'abc', 'uses' => 1]);

        $this->post('/register', $this->validUserData() + ['invite' => 'abc'])->assertSessionHasErrors('invite');
        $this->assertSame(0, User::count());
    }

    public function test_taken_email_cannot_be_probed_without_a_redeemable_invite()
    {
        User::factory()->create(['email' => 'taken@example.com']);
        Invitation::create(['token' => 'used', 'uses' => 1]);

        foreach ([[], ['invite' => 'nope'], ['invite' => 'used']] as $invite) {
            $this->post('/register', $this->validUserData('taken@example.com') + $invite)
                ->assertSessionHasErrors('invite')->assertSessionDoesntHaveErrors('email');
        }
    }

    public function test_registration_is_limited_to_five_attempts_per_minute()
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/register', $this->validUserData() + ['invite' => 'nope'])->assertSessionHasErrors('invite');
        }

        $this->post('/register', $this->validUserData() + ['invite' => 'nope'])->assertTooManyRequests();
    }

    public function test_admin_invitations_are_single_use()
    {
        $this->expectException(\LogicException::class);

        Invitation::create(['token' => 'abc', 'is_admin' => true, 'max_uses' => 2]);
    }

    public function test_register_page_passes_invite_from_query()
    {
        $this->get('/register?invite=abc')
            ->assertInertia(fn (Assert $page) => $page->component('auth/register')->where('invite', 'abc'));
    }

    public function test_prints_an_invite_url()
    {
        $this->artisan('invite:create')->expectsOutputToContain('/register?invite=')->assertSuccessful();

        $inv = Invitation::first();
        $this->assertSame(1, $inv->max_uses);
        $this->assertNull($inv->expires_at);
    }

    public function test_creates_a_group_invite_with_expiry()
    {
        $this->artisan('invite:create --uses=10 --days=14')
            ->expectsOutputToContain('/register?invite=')
            ->expectsOutputToContain('Uses: 0/10')
            ->assertSuccessful();

        $inv = Invitation::first();
        $this->assertSame(10, $inv->max_uses);
        $this->assertTrue($inv->expires_at->between(now()->addDays(14)->subMinute(), now()->addDays(14)->addMinute()));
    }

    public function test_rejects_invalid_invite_options()
    {
        $this->artisan('invite:create --admin --uses=2')
            ->expectsOutputToContain('Admin invitations are single-use')->assertFailed();
        $this->artisan('invite:create --uses=0')->assertFailed();
        $this->artisan('invite:create --uses=501')->assertFailed();
        $this->artisan('invite:create --uses=abc')->assertFailed();
        $this->artisan('invite:create --days=0')->assertFailed();

        $this->assertSame(0, Invitation::count());
    }

    public function test_lists_invitations_with_shortened_tokens()
    {
        $inv = Invitation::create(['token' => 'abcdefghijklmnop', 'max_uses' => 10, 'uses' => 3]);

        $this->artisan('invite:list')
            ->expectsTable(
                ['ID', 'Token', 'Admin', 'Uses', 'Expires at', 'Created at'],
                [[$inv->id, 'abcdefgh', 'no', '3/10', 'never', $inv->created_at->toDateTimeString()]],
            )
            ->assertSuccessful();
    }
}
