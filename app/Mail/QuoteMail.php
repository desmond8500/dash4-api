<?php

namespace App\Mail;

use App\Models\Company;
use App\Models\Quote;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Devis envoyé au client : message rédigé par l'utilisateur, PDF du devis (et proposition technique) joints. */
class QuoteMail extends Mailable
{
    /** @param array<int, array{name: string, data: string}> $files */
    public function __construct(
        public Quote $quote,
        public string $mailSubject,
        public string $body,
        public array $files,
        public ?string $replyToAddress = null,
        public ?string $replyToName = null,
        /** Avec une signature, le pied de page automatique de l'entreprise n'est pas ajouté. */
        public bool $hasSignature = false,
        /** Image de la signature (fichier), intégrée au message. */
        public ?string $signatureImage = null,
        public int $signatureImageWidth = 160,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->mailSubject,
            replyTo: $this->replyToAddress ? [new Address($this->replyToAddress, $this->replyToName)] : [],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.quote',
            text: 'mail.quote_text',
            with: [
                'body' => $this->body,
                'company' => Company::current(),
                'hasSignature' => $this->hasSignature,
                'signatureImage' => $this->signatureImage,
                'signatureImageWidth' => $this->signatureImageWidth,
            ],
        );
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return array_map(
            fn (array $f) => Attachment::fromData(fn () => $f['data'], $f['name'])->withMime('application/pdf'),
            $this->files,
        );
    }
}
