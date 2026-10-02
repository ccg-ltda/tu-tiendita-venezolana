<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 20px 0; border: 1px solid #e8dcc4; border-radius: 8px; background-color: #fffdf8;">
    <tr>
        <td style="padding: 14px 16px; border-bottom: 1px solid #eee3ce; font-family: Arial, Helvetica, sans-serif; color: #536171; font-size: 12px;">REFERENCIA<br /><strong style="color: #0b2a4a; font-size: 15px;">{{ $entry['reference'] }}</strong></td>
        <td style="padding: 14px 16px; border-bottom: 1px solid #eee3ce; font-family: Arial, Helvetica, sans-serif; color: #536171; font-size: 12px; text-align: right;">ESTADO<br /><span style="display: inline-block; margin-top: 3px; padding: 4px 9px; border-radius: 12px; background-color: {{ $badgeBackground }}; color: {{ $badgeColor }}; font-size: 12px; font-weight: 700;">{{ $statusLabel }}</span></td>
    </tr>
    <tr>
        <td colspan="2" style="padding: 14px 16px; font-family: Arial, Helvetica, sans-serif; color: #536171; font-size: 12px; text-align: right;">TOTAL DEL PEDIDO<br /><strong style="color: #0b2a4a; font-size: 20px;">${{ number_format($entry['total_cop'], 0, ',', '.') }} COP</strong></td>
    </tr>
</table>
