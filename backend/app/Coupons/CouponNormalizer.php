<?php

namespace App\Coupons;

use DateTimeImmutable;
use DateTimeZone;

final class CouponNormalizer
{
    public const HEADERS = ['coupon_id','code','description','active','discount_type','discount_value','minimum_order_cop','max_uses','used_count','starts_at','ends_at','created_at','updated_at','revision'];
    private const BOGOTA = 'America/Bogota';

    /** @return array{coupon_id:int,code:string,description:string|null,active:bool,discount_type:'percent'|'fixed',discount_value:int,minimum_order_cop:int|null,max_uses:int|null,used_count:int,starts_at:string|null,ends_at:string|null,created_at:string,updated_at:string,revision:int} */
    public static function normalize(mixed $coupon): array
    {
        if (! is_array($coupon)) throw new CouponContractException('Coupon must be an array.');
        $id = self::integer($coupon['coupon_id'] ?? null, 1);
        $code = self::code($coupon['code'] ?? null);
        $description = self::description($coupon['description'] ?? null);
        $active = self::boolean($coupon['active'] ?? null);
        $type = is_string($coupon['discount_type'] ?? null) ? strtolower(trim($coupon['discount_type'])) : null;
        $value = self::integer($coupon['discount_value'] ?? null, 1);
        $minimum = self::nullableInteger($coupon['minimum_order_cop'] ?? null, 1);
        $maxUses = self::nullableInteger($coupon['max_uses'] ?? null, 1);
        $usedCount = array_key_exists('used_count', $coupon) ? self::integer($coupon['used_count'], 0) : 0;
        $startsAt = self::commercialDate($coupon['starts_at'] ?? null, 'starts_at');
        $endsAt = self::commercialDate($coupon['ends_at'] ?? null, 'ends_at');
        $createdAt = self::auditTimestamp($coupon['created_at'] ?? null, 'created_at');
        $updatedAt = self::auditTimestamp($coupon['updated_at'] ?? null, 'updated_at');
        $revision = array_key_exists('revision', $coupon) ? self::integer($coupon['revision'], 1) : 1;

        if ($id === null || $code === null || $active === null || ! in_array($type, ['percent', 'fixed'], true) || $value === null || $usedCount === null || $createdAt === null || $updatedAt === null || $revision === null) throw new CouponContractException('Coupon contract is invalid.');
        if ($type === 'percent' && $value > 99) throw new CouponContractException('Percentage discount must be between 1 and 99.');
        if ($maxUses !== null && $usedCount > $maxUses) throw new CouponContractException('Coupon used_count cannot exceed max_uses.');
        if ($startsAt !== null && $endsAt !== null && $endsAt <= $startsAt) throw new CouponContractException('Coupon end must be after its start.');

        return ['coupon_id'=>$id,'code'=>$code,'description'=>$description,'active'=>$active,'discount_type'=>$type,'discount_value'=>$value,'minimum_order_cop'=>$minimum,'max_uses'=>$maxUses,'used_count'=>$usedCount,'starts_at'=>$startsAt?->format('Y-m-d\\TH:i:s.vP'),'ends_at'=>$endsAt?->format('Y-m-d\\TH:i:s.vP'),'created_at'=>$createdAt->format('Y-m-d\\TH:i:s.v\\Z'),'updated_at'=>$updatedAt->format('Y-m-d\\TH:i:s.v\\Z'),'revision'=>$revision];
    }

    /** @param list<mixed> $row */
    public static function sheetRow(array $row): array { $row=array_pad($row,count(self::HEADERS),null); return self::normalize(array_combine(self::HEADERS,array_slice($row,0,count(self::HEADERS))) ?: []); }

    public static function normalizeCode(mixed $value): ?string
    {
        if (! is_string($value)) return null;

        $value = trim($value);

        return $value === '' ? null : strtoupper($value);
    }

    private static function code(mixed $value): ?string { return self::normalizeCode($value); }
    private static function description(mixed $value): ?string { if ($value === null) return null; if (! is_string($value)) throw new CouponContractException('Coupon description must be text.'); $value=trim($value); return $value === '' ? null : $value; }
    private static function integer(mixed $value,int $minimum): ?int { if (!is_int($value)&&(!is_string($value)||preg_match('/^(0|[1-9][0-9]*)$/D',$value)!==1))return null; $integer=(int)$value; return $integer >= $minimum && $integer <= 2147483647 ? $integer : null; }
    private static function nullableInteger(mixed $value,int $minimum): ?int
    {
        if ($value === null || $value === '') return null;
        $integer = self::integer($value, $minimum);
        if ($integer === null) throw new CouponContractException('Coupon optional amount is invalid.');
        return $integer;
    }
    private static function boolean(mixed $value): ?bool { return match($value){true,1,'1','TRUE','true'=>true,false,0,'0','FALSE','false'=>false,default=>null}; }
    private static function commercialDate(mixed $value,string $field): ?DateTimeImmutable { if($value===null||$value==='')return null; if(!is_string($value)||preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}-05:00$/D',$value)!==1)throw new CouponContractException("Coupon {$field} must be a Bogota ISO-8601 timestamp."); try{$date=new DateTimeImmutable($value);$bogota=$date->setTimezone(new DateTimeZone(self::BOGOTA));if($bogota->format('Y-m-d\\TH:i:s.vP')!==$value)throw new CouponContractException("Coupon {$field} must use America/Bogota.");return $bogota;}catch(CouponContractException $e){throw $e;}catch(\Throwable){throw new CouponContractException("Coupon {$field} is invalid.");} }
    private static function auditTimestamp(mixed $value,string $field): ?DateTimeImmutable { if(!is_string($value)||preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/D',$value)!==1)return null;try{$date=new DateTimeImmutable($value);return $date->format('Y-m-d\\TH:i:s.v\\Z')===$value?$date:null;}catch(\Throwable){return null;} }
}
