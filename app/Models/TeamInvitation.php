<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * دعوة عضو فريق (A7). الإنشاء والقبول في `App\Support\TeamInvitations`، والـ token في الإيميل بس (المخزّن hash).
 */
class TeamInvitation extends Model
{
    public const UPDATED_AT = null;

    /** الأدوار اللي الدعوة بتديها (دور الفريق بس). */
    public const ROLES = ['admin', 'moderator'];

    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * الدعوات اللي لسه ينفع تتقبل: ما اتقبلتش وما انتهتش.
     *
     * @param  Builder<TeamInvitation>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }

    public function isExpired(): bool
    {
        return $this->accepted_at === null && ! $this->expires_at->isFuture();
    }
}
