<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property string $token
 * @property bool $is_admin
 * @property int $max_uses
 * @property int $uses
 * @property Carbon|null $expires_at
 * @property Carbon|null $used_at last time the invitation was redeemed
 * @property Carbon|null $created_at
 */
#[Fillable(['token', 'is_admin', 'max_uses', 'uses', 'expires_at', 'used_at'])]
class Invitation extends Model
{
    /** Same as the column defaults, so a freshly created invitation has them too. */
    protected $attributes = ['max_uses' => 1, 'uses' => 0];

    protected function casts(): array
    {
        return [
            'is_admin' => 'boolean',
            'max_uses' => 'integer',
            'uses' => 'integer',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $invitation) {
            if ($invitation->is_admin && $invitation->max_uses > 1) {
                throw new LogicException('Admin invitations are single-use.');
            }
        });
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** Keep in sync with the redeemable() scope below. */
    public function isRedeemable(): bool
    {
        return $this->uses < $this->max_uses && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /** @param  Builder<self>  $query */
    #[Scope]
    protected function redeemable(Builder $query): void
    {
        $query->whereColumn('uses', '<', 'max_uses')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }
}
