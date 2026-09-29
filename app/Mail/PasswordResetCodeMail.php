<?php

namespace App\Mail;

use App\Models\User;
use App\Services\PasswordResetService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The reset-code email (plain text: readable in any mail app and in the dev log). */
class PasswordResetCodeMail extends Mailable
{
    public function __construct(
        public readonly User $user,
        public readonly string $code,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Papaya HatidGo: ang code mo para sa bagong password');
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.password-reset-code',
            with: [
                'firstName' => $this->user->first_name,
                'code' => $this->code,
                'minutes' => PasswordResetService::CODE_LIFETIME_MINUTES,
            ],
        );
    }
}
