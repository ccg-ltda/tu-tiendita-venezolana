@php($statusLabel = 'Entregado')
@php($badgeColor = '#ffffff')
@php($badgeBackground = '#2e7d32')
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #fff8ed;">
    <tr><td align="center" style="padding: 28px 12px;">
        <table role="presentation" width="640" cellpadding="0" cellspacing="0" border="0" style="width: 100%; max-width: 640px; background-color: #ffffff; border: 1px solid #eadfc9; border-radius: 10px; overflow: hidden;">
            @include('emails.orders.partials.brand-header')
            <tr><td style="padding: 30px 32px; font-family: Arial, Helvetica, sans-serif; color: #283b4d; font-size: 15px; line-height: 22px;">
                <h1 style="margin: 0 0 10px; color: #0b2a4a; font-size: 24px; line-height: 30px;">Pedido entregado</h1>
                <p style="margin: 0;">Hola, {{ $entry['customer_name'] }}. Confirmamos que tu pedido fue entregado.</p>
                @include('emails.orders.partials.order-overview')
                @include('emails.orders.partials.items-summary', ['entry' => $entry])
                <p style="margin: 16px 0 0;">Gracias por comprar en Tu Tiendita Venezolana. ¡Esperamos verte pronto!</p>
            </td></tr>
            @include('emails.orders.partials.brand-footer')
        </table>
    </td></tr>
</table>
