<?php

namespace App\Console\Commands;

use App\Models\Invitation;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class InviteCreate extends Command
{
    protected $signature = 'invite:create {--admin : Einladung für ein Admin-Konto}';

    protected $description = 'Erzeugt eine Einladung und gibt den Registrierungslink aus';

    public function handle(): int
    {
        $invitation = Invitation::create([
            'token' => Str::random(40),
            'is_admin' => (bool) $this->option('admin'),
        ]);

        $this->line(route('register', ['invite' => $invitation->token]));

        return self::SUCCESS;
    }
}
