<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    /** أدوار فريق اللوحة. أي دور تاني (owner) بيفضل مستخدم تطبيق. */
    public const TEAM_ROLES = ['admin', 'moderator'];

    /**
     * نفس الـ defaults اللي في الـ migration، عشان الموديل اللي لسه متعمل (من غير refresh)
     * يبقى ليه نفس القيم اللي هتتحمّل من الداتابيز.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'locale' => 'ar',
        'status' => 'active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'suspended_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'last_active_at' => 'datetime',
            'anonymized_at' => 'datetime',
        ];
    }

    /**
     * حسابات Google وApple المربوطة. مفيش واحد = التسجيل بالإيميل والباسورد.
     *
     * @return HasMany<SocialAccount, $this>
     */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /**
     * تقييمات المستخدم بكل حالاتها (شاشة المستخدمين A7 بتعرض عددهم).
     *
     * @return HasMany<Review, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /**
     * فريق اللوحة: اللي دوره أدمن أو مشرف (حتى لو محذوف). شاشة «الفريق» بتعرض دول بس.
     *
     * @param  Builder<User>  $query
     */
    public function scopeTeam(Builder $query): void
    {
        $query->whereHas('roles', fn (Builder $roles) => $roles->whereIn('name', self::TEAM_ROLES));
    }

    /**
     * مستخدمي التطبيق: كل اللي مالوش دور فريق (صاحب المكان منهم). شاشة «مستخدمو التطبيق» بتعرض دول بس.
     *
     * @param  Builder<User>  $query
     */
    public function scopeAppUsers(Builder $query): void
    {
        $query->whereDoesntHave('roles', fn (Builder $roles) => $roles->whereIn('name', self::TEAM_ROLES));
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    /**
     * لوحة Filament مقفولة على أدوار الفريق بس (SEC-04). مفيش دور = مفيش دخول.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->status === 'active' && $this->hasAnyRole(['admin', 'moderator']);
    }
}
