<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border: 1px solid #e8dcc4; border-collapse: collapse; font-family: Arial, Helvetica, sans-serif; color: #283b4d; font-size: 13px;">
    <thead><tr style="background-color: #0b2a4a;"><th align="left" style="padding: 10px; color: #ffffff; font-size: 12px;">Producto</th><th align="center" style="padding: 10px; color: #ffffff; font-size: 12px;">Cant.</th><th align="right" style="padding: 10px; color: #ffffff; font-size: 12px;">Precio</th><th align="right" style="padding: 10px; color: #ffffff; font-size: 12px;">Subtotal</th></tr></thead>
    <tbody>
    @foreach ($entry['items'] as $item)
        <tr><td style="padding: 10px; border-top: 1px solid #eee3ce;">{{ $item['product_name'] }}</td><td align="center" style="padding: 10px; border-top: 1px solid #eee3ce;">{{ $item['quantity'] }}</td><td align="right" style="padding: 10px; border-top: 1px solid #eee3ce; white-space: nowrap;">${{ number_format($item['unit_price_cop'], 0, ',', '.') }}</td><td align="right" style="padding: 10px; border-top: 1px solid #eee3ce; white-space: nowrap; font-weight: 700;">${{ number_format($item['unit_price_cop'] * $item['quantity'], 0, ',', '.') }}</td></tr>
    @endforeach
    </tbody>
</table>
