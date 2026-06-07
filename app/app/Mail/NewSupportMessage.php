<?php

namespace App\Mail;

use App\Models\SupportMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewSupportMessage extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public SupportMessage $msg)
    {
        $this->onQueue('default');
    }

    public function envelope(): Envelope
    {
        $subject = '[Layout.ai support] ' . str($this->msg->body)->limit(60);
        $replyToName = $this->msg->user?->name ?: $this->msg->email;

        return new Envelope(
            subject: (string) $subject,
            replyTo: [new Address($this->msg->email, $replyToName)],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.support-new');
    }
}
