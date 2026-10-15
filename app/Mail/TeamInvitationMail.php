<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * دعوة عضو فريق اللوحة (A7). بالعربي بس لأن اللوحة نفسها عربي، والرابط صالح `$days` أيام ولمرة واحدة.
 * في التطوير على `log` driver (الرابط بيظهر في storage/logs/laravel.log).
 */
class TeamInvitationMail extends Mailable
{
    public function __construct(
        public readonly string $url,
        public readonly string $role,
        public readonly string $inviter,
        public readonly int $days,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'دعوة للانضمام لفريق وين');
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.team-invitation',
            with: [
                'url' => $this->url,
                'role' => $this->role,
                'inviter' => $this->inviter,
                'days' => $this->days,
            ],
        );
    }
}
