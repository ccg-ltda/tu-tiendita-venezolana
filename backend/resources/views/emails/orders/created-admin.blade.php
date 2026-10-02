@php($statusLabel = 'Pedido creado')
@php($badgeColor = '#7a5200')
@php($badgeBackground = '#ffe7a3')
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #fff8ed;">
    <tr><td align="center" style="padding: 28px 12px;">
        <table role="presentation" width="640" cellpadding="0" cellspacing="0" border="0" style="width: 100%; max-width: 640px; background-color: #ffffff; border: 1px solid #eadfc9; border-radius: 10px; overflow: hidden;">
            @include('emails.orders.partials.brand-header')
            <tr><td style="padding: 30px 32px; font-family: Arial, Helvetica, sans-serif; color: #283b4d; font-size: 15px; line-height: 22px;">
                <h1 style="margin: 0 0 10px; color: #0b2a4a; font-size: 24px; line-height: 30px;">Nuevo pedido recibido</h1>
                <p style="margin: 0;">Pedido <strong>{{ $entry['reference'] }}</strong> · ID {{ $entry['order_id'] }}</p>
                @include('emails.orders.partials.order-overview')
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 20px 0; background-color: #fff7e4; border-left: 4px solid #d62828; font-family: Arial, Helvetica, sans-serif; font-size: 14px; line-height: 21px;">
                    <tr><td style="padding: 14px 16px;"><strong style="color: #0b2a4a;">Cliente</strong><br />{{ $entry['customer_name'] }}<br />{{ $entry['customer_email'] }} · {{ $entry['customer_phone'] }}<br />{{ $entry['address'] }}@if (!empty($entry['extra'])), {{ $entry['extra'] }}@endif<br />{{ $entry['city'] }}</td></tr>
                </table>
                @include('emails.orders.partials.items-summary', ['entry' => $entry])
                <p style="margin: 16px 0 0;"><strong style="color: #0b2a4a;">Pago:</strong> APPROVED &nbsp; <strong style="color: #0b2a4a;">Operación:</strong> PENDING</p>
            </td></tr>
            @include('emails.orders.partials.brand-footer')
        </table>
    </td></tr>
</table>
