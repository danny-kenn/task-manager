<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class EventMail extends Mailable
{
    public function __construct(public User $user, public string $headline, public ?string $body, public ?string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->headline.' · '.config('app.name'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.event');
    }
}
