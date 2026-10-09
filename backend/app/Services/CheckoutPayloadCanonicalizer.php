<?php

namespace App\Services;

final class CheckoutPayloadCanonicalizer
{
    /** @param list<array{id:int|string,qty:int|string}> $items @return list<array{product_id:int,quantity:int}> */
    public function normalizeItems(array $items): array
    {
        $quantities=[];
        foreach($items as $item){
            if(!is_array($item)||($id=$this->positiveInteger($item['id']??null,2147483647))===null||($quantity=$this->positiveInteger($item['qty']??null,999))===null)throw new \InvalidArgumentException('Invalid checkout items.');
            $quantities[$id]=($quantities[$id]??0)+$quantity;
            if($quantities[$id]>999)throw new \InvalidArgumentException('Invalid checkout items.');
        }
        if($quantities===[]||count($quantities)>50)throw new \InvalidArgumentException('Invalid checkout items.');
        ksort($quantities,SORT_NUMERIC);
        return array_map(static fn(int $id,int $quantity):array=>['product_id'=>$id,'quantity'=>$quantity],array_keys($quantities),array_values($quantities));
    }
    /** @param array{name:string,email:string,phone:string,document:string,address:string,extra:string|null,city:string,region:string,postal:string|null} $customer @param list<array{id:int|string,qty:int|string}> $items @return array{items:list<array{product_id:int,quantity:int}>,canonical:string,payload_hash:string} */
    public function canonicalize(array $customer,array $items,?string $couponCode=null): array
    {
        $normalized=$this->normalizeItems($items);
        $couponCode = \App\Coupons\CouponNormalizer::normalizeCode($couponCode);
        $canonical=json_encode(['customer'=>$this->customer($customer),'items'=>$normalized,'coupon_code'=>$couponCode],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        return ['items'=>$normalized,'coupon_code'=>$couponCode,'canonical'=>$canonical,'payload_hash'=>hash('sha256',$canonical)];
    }
    /** @param array{name:string,email:string,phone:string,document:string,address:string,extra:string|null,city:string,region:string,postal:string|null} $customer @return array{name:string,email:string,phone:string,document:string,address:string,extra:string|null,city:string,region:string,postal:string|null} */
    private function customer(array $customer): array
    {
        $fields=['name','email','phone','document','address','extra','city','region','postal'];
        if(array_keys($customer)!==$fields)throw new \InvalidArgumentException('Invalid normalized checkout customer.');
        foreach(['name','email','phone','document','address','city','region'] as $field)if(!is_string($customer[$field]))throw new \InvalidArgumentException('Invalid normalized checkout customer.');
        foreach(['extra','postal'] as $field)if($customer[$field]!==null&&!is_string($customer[$field]))throw new \InvalidArgumentException('Invalid normalized checkout customer.');
        return $customer;
    }
    private function positiveInteger(mixed $value,int $max): ?int
    {
        if(is_int($value))return $value>=1&&$value<=$max?$value:null;
        if(!is_string($value)||preg_match('/^[1-9]\d*$/D',$value)!==1)return null;
        $integer=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>$max]]);
        return $integer===false?null:$integer;
    }
}
