<?php

namespace App\Mail;

use App\Models\AuthCode;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * إيميل كود 6 أرقام (نسيت الباسورد أو تأكيد الإيميل). النص بلغة المستخدم من lang/{ar,en}/mail.php.
 * في التطوير على `log` driver (الكود بيظهر في storage/logs/laravel.log).
 */
class AuthCodeMail extends Mailable
{
    public function __construct(
        public readonly string $type,
        public readonly string $code,
        public readonly int $minutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __($this->type === AuthCode::TYPE_PASSWORD_RESET ? 'mail.password_reset.subject' : 'mail.email_verify.subject'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.auth-code',
            with: [
                'heading' => __($this->type === AuthCode::TYPE_PASSWORD_RESET ? 'mail.password_reset.heading' : 'mail.email_verify.heading'),
                'code' => $this->code,
                'minutes' => $this->minutes,
            ],
        );
    }
}
