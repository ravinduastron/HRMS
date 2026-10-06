<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

// Deliberately NOT queued, so the plain-text password never sits in a queue payload.
class TemporaryPasswordMail extends Mailable
{
    public function __construct(public User $user, public string $plainPassword, public string $reason = 'created') {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->reason === 'reset'
            ? config('payroll.name').': your password has been reset'
            : 'Your '.config('payroll.name').' account');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.temporary-password');
    }
}
