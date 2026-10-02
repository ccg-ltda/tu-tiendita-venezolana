<?php

namespace App\Mail;

use App\Mail\Concerns\ProvidesInlineOrderLogo;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class OrderCreatedAdminMail extends Mailable
{
    use Queueable;
    use ProvidesInlineOrderLogo;

    /** @param array<string,mixed> $entry */
    public function __construct(public readonly array $entry) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Nuevo pedido '.$this->entry['reference'].' (#'.$this->entry['order_id'].')');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.orders.created-admin', with: ['inlineLogoPath' => $this->inlineOrderLogoPath()]);
    }
}
