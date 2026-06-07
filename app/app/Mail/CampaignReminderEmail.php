<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CampaignReminderEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public User $user, public int $daysLeft)
    {
        $this->onQueue('default');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your \$500 ad credit is sitting unused");
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.campaign-reminder',
            with: ['user' => $this->user, 'daysLeft' => $this->daysLeft],
        );
    }
}
