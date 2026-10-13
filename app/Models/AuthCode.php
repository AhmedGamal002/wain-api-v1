<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * كود 6 أرقام لنسيت الباسورد أو تأكيد الإيميل. بيتعامل معاه AuthCodeService بس.
 */
class AuthCode extends Model
{
    public const TYPE_PASSWORD_RESET = 'password_reset';

    public const TYPE_EMAIL_VERIFY = 'email_verify';

    protected $guarded = [];

    protected $hidden = ['code_hash', 'reset_token_hash'];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'reset_token_expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}
