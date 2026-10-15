<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
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
