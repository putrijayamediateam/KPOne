<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** HR-17b: one fixed hiring message as plain text. The text is built from a fixed template and never stored. */
class HiringMessageMail extends Mailable
{
    public function __construct(public readonly string $subjectLine, public readonly string $bodyText) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Klinik Putrijaya: '.$this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.staff-message', with: ['body' => $this->bodyText]);
    }
}
