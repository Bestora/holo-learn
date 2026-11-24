<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user. Registration requires a redeemable invitation,
     * one use of which is consumed in the same transaction that creates the user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        $invalidInvite = 'This invitation is invalid or has already been used.';

        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            'invite' => ['required', 'string'],
        ], [
            'invite.required' => 'You can only register with an invitation.',
            'invite.string' => $invalidInvite,
        ])->validate();

        return DB::transaction(function () use ($input, $invalidInvite) {
            $invitation = Invitation::firstWhere('token', $input['invite']);

            // Conditional increment so concurrent registrations cannot exceed max_uses.
            $claimed = $invitation?->isRedeemable() && Invitation::whereKey($invitation->id)
                ->redeemable()
                ->increment('uses', 1, ['used_at' => now()]);

            if (! $claimed) {
                throw ValidationException::withMessages(['invite' => $invalidInvite]);
            }

            return User::forceCreate([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
                'is_admin' => $invitation->is_admin,
                'invitation_id' => $invitation->id,
                // The invitation is the trust anchor; there is no mail server to verify with.
                'email_verified_at' => now(),
            ]);
        });
    }
}
