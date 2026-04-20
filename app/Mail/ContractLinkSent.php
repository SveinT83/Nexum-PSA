<?php

namespace App\Mail;

use App\Models\CS\Contracts\Contracts;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContractLinkSent extends Mailable
{
    use Queueable, SerializesModels;

    public $contract;
    public $type;
    public $url;

    /**
     * Create a new message instance.
     */
    public function __construct(Contracts $contract, string $type)
    {
        $this->contract = $contract;
        $this->type = $type; // 'quote' or 'contract'
        $this->url = route('contracts.public.view', ['token' => $contract->secure_token]);
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $subject = $this->type === 'quote'
            ? 'Tilbud på tjenester fra ' . config('app.name')
            : 'Kontrakt på tjenester fra ' . config('app.name');

        return new Envelope(
            subject: $subject,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'emails.contracts.link-sent',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
