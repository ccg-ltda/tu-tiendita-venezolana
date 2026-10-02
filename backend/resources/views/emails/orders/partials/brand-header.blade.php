<tr>
    <td style="padding: 24px 32px; background-color: #0b2a4a; border-bottom: 5px solid #f4c430;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr>
                @if (!empty($inlineLogoPath) && isset($message))
                    <td width="70" style="width: 70px; vertical-align: middle;">
                        <table role="presentation" width="70" height="70" cellpadding="0" cellspacing="0" border="0" style="width: 70px; height: 70px; background-color: #fff8ed; border-radius: 8px;">
                            <tr><td align="center" valign="middle" style="width: 70px; height: 70px; vertical-align: middle;">
                                <img src="{{ $message->embed($inlineLogoPath) }}" alt="Tu Tiendita Venezolana" width="58" style="display: block; width: 58px; height: auto; border: 0;" />
                            </td></tr>
                        </table>
                    </td>
                @endif
                <td style="padding-left: 12px; vertical-align: middle; font-family: Arial, Helvetica, sans-serif; color: #ffffff;">
                    <div style="font-size: 20px; font-weight: 700; line-height: 24px;">Tu Tiendita Venezolana</div>
                    <div style="font-size: 12px; color: #f8d66d; line-height: 18px;">Sabor y cercanía en cada pedido</div>
                </td>
            </tr>
        </table>
    </td>
</tr>
