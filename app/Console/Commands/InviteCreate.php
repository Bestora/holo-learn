<?php

namespace App\Console\Commands;

use App\Models\Invitation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class InviteCreate extends Command
{
    protected $signature = 'invite:create
        {--admin : Invitation for an admin account (always single-use)}
        {--uses=1 : How many people can register with the link (1-500)}
        {--days= : Days until the link expires (default: never)}';

    protected $description = 'Create an invitation and print the registration link';

    public function handle(): int
    {
        $validator = Validator::make($this->options(), [
            'uses' => ['required', 'integer', 'between:1,500'],
            'days' => ['nullable', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $uses = (int) $this->option('uses');
        $days = $this->option('days');

        if ($this->option('admin') && $uses > 1) {
            $this->error('Admin invitations are single-use; --uses cannot be combined with --admin.');

            return self::FAILURE;
        }

        $invitation = Invitation::create([
            'token' => Str::random(40),
            'is_admin' => (bool) $this->option('admin'),
            'max_uses' => $uses,
            'expires_at' => $days === null ? null : now()->addDays((int) $days),
        ]);

        $this->line(route('register', ['invite' => $invitation->token]));
        $this->line(sprintf('Uses: 0/%d, expires: %s', $uses, $invitation->expires_at?->toDateTimeString() ?? 'never'));

        return self::SUCCESS;
    }
}
