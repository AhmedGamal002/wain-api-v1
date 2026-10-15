<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * حساب Google أو Apple مربوط بمستخدم. بيحدد «طريقة التسجيل» في شاشة المستخدمين (A7).
 */
class SocialAccount extends Model
{
    public const PROVIDER_GOOGLE = 'google';

    public const PROVIDER_APPLE = 'apple';

    public const UPDATED_AT = null;

    protected $guarded = [];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
