<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WelcomeEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public User $user)
    {
        $this->onQueue('default');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Welcome to Layout.ai — your first 1,000 ads are ready');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.welcome', with: ['user' => $this->user]);
    }
}
