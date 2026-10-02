@php($statusLabel = 'En camino')
@php($badgeColor = '#ffffff')
@php($badgeBackground = '#1769aa')
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #fff8ed;">
    <tr><td align="center" style="padding: 28px 12px;">
        <table role="presentation" width="640" cellpadding="0" cellspacing="0" border="0" style="width: 100%; max-width: 640px; background-color: #ffffff; border: 1px solid #eadfc9; border-radius: 10px; overflow: hidden;">
            @include('emails.orders.partials.brand-header')
            <tr><td style="padding: 30px 32px; font-family: Arial, Helvetica, sans-serif; color: #283b4d; font-size: 15px; line-height: 22px;">
                <h1 style="margin: 0 0 10px; color: #0b2a4a; font-size: 24px; line-height: 30px;">¡Tu pedido va en camino!</h1>
                <p style="margin: 0;">Hola, {{ $entry['customer_name'] }}. Estamos llevando tu pedido a la dirección indicada.</p>
                @include('emails.orders.partials.order-overview')
                @if (!empty($entry['address']) || !empty($entry['city']))
                    <p style="margin: 0 0 20px; padding: 12px 14px; background-color: #f0f6fb; border-left: 4px solid #1769aa;"><strong style="color: #0b2a4a;">Dirección de entrega:</strong><br />{{ $entry['address'] }}@if (!empty($entry['city'])), {{ $entry['city'] }}@endif</p>
                @endif
                @include('emails.orders.partials.items-summary', ['entry' => $entry])
            </td></tr>
            @include('emails.orders.partials.brand-footer')
        </table>
    </td></tr>
</table>
