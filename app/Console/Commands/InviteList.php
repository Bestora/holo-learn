<?php

namespace App\Console\Commands;

use App\Models\Invitation;
use Illuminate\Console\Command;

class InviteList extends Command
{
    protected $signature = 'invite:list';

    protected $description = 'List all invitations';

    public function handle(): int
    {
        $this->table(
            ['ID', 'Token', 'Admin', 'Uses', 'Expires at', 'Created at'],
            Invitation::orderBy('id')->get()->map(fn (Invitation $i) => [
                $i->id,
                substr($i->token, 0, 8),
                $i->is_admin ? 'yes' : 'no',
                "{$i->uses}/{$i->max_uses}",
                $i->expires_at?->toDateTimeString() ?? 'never',
                $i->created_at?->toDateTimeString(),
            ]),
        );

        return self::SUCCESS;
    }
}
