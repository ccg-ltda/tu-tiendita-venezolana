<?php

namespace App\Services;

use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Str;

final class CheckoutReferenceGenerator
{
    private const TIMEZONE='America/Bogota';

    public function generate(int $orderId, DateTimeInterface $now, ?string $uuid=null): string
    {
        if($orderId<1||$orderId>2147483647)throw new \InvalidArgumentException('Invalid order id.');
        $uuid ??= (string) Str::uuid();
        if(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD',$uuid)!==1)throw new \InvalidArgumentException('Invalid UUID.');
        $date=(new \DateTimeImmutable('@'.$now->getTimestamp()))->setTimezone(new DateTimeZone(self::TIMEZONE))->format('Ymd');
        return 'TTV-'.$date.'-'.strtoupper(base_convert((string)$orderId,10,36)).'-'.strtoupper(substr(str_replace('-','',$uuid),0,8));
    }
}
