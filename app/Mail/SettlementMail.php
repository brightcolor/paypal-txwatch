<?php

namespace App\Mail;

use App\Models\Settlement;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Queue\SerializesModels;

/**
 * Emails a settlement to the customer with the PDF attached. The rendered PDF
 * bytes are passed in (not re-rendered here) so sending stays fast and the
 * attachment matches exactly what the operator saw.
 */
class SettlementMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Settlement $settlement,
        public string $pdf,
        public string $filename,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Abrechnung: ' . $this->settlement->title,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.settlement',
            with: ['settlement' => $this->settlement],
        );
    }

    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdf, $this->filename)->withMime('application/pdf'),
        ];
    }
}
