<?php

namespace App\Modules\Shoppers\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** The code a shopper types back to prove the email is theirs. */
final class VerificationCode extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $code,
        public readonly string $shopName,
        public readonly int $minutes,
        public readonly string $language,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: (string) __('shoppers::ui.mail.subject', ['code' => $this->code], $this->language));
    }

    public function content(): Content
    {
        return new Content(view: 'shoppers::mail.code');
    }
}
