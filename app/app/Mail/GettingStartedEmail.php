<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class GettingStartedEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public User $user)
    {
        $this->onQueue('default');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: '3 quick steps to start running your ads');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.getting-started', with: ['user' => $this->user]);
    }
}
