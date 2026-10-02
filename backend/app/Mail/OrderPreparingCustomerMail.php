<?php

namespace App\Mail;

use App\Mail\Concerns\ProvidesInlineOrderLogo;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class OrderPreparingCustomerMail extends Mailable
{
    use Queueable;
    use ProvidesInlineOrderLogo;
    /** @param array<string,mixed> $entry */
    public function __construct(public readonly array $entry) {}
    public function envelope(): Envelope { return new Envelope(subject: 'Tu pedido está en preparación: '.$this->entry['reference']); }
    public function content(): Content { return new Content(view: 'emails.orders.preparing-customer', with: ['inlineLogoPath' => $this->inlineOrderLogoPath()]); }
}
