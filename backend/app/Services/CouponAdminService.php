<?php
namespace App\Services;
use App\Coupons\CouponContractException;
use App\Coupons\CouponDiscountResolver;
use App\Coupons\CouponNormalizer;
use App\Repositories\MySqlCouponRepository;
use DateTimeImmutable;
use DateTimeZone;
final class CouponAdminService {
 public function __construct(private readonly MySqlCouponRepository $coupons,private readonly CouponDiscountResolver $resolver){}
 public function list():array{return array_map(fn(array $coupon):array=>$this->view($coupon),$this->coupons->all());}
 public function show(int $id):array{$coupon=$this->coupons->findByCouponId($id);if($coupon===null)throw new PersistenceException(404,'COUPON_NOT_FOUND',null,'El cupón no existe.');return $this->view($coupon);}
 public function create(array $input):array{return $this->view($this->coupons->create($this->candidate($input,null)));}
 public function update(int $id,array $input):array{$existing=$this->coupons->findByCouponId($id);if($existing===null)throw new PersistenceException(404,'COUPON_NOT_FOUND',null,'El cupón no existe.');return $this->view($this->coupons->update($id,$input['expected_revision'],$this->candidate($input,$id,$existing['used_count'])));}
 private function candidate(array $input,?int $id,int $usedCount=0):array{$now=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d\\TH:i:s.v\\Z');try{return CouponNormalizer::normalize(['coupon_id'=>$id??1,'code'=>$input['code'],'description'=>$input['description']??null,'active'=>$input['active'],'discount_type'=>$input['discount_type'],'discount_value'=>$input['discount_value'],'minimum_order_cop'=>$input['minimum_order_cop']??null,'max_uses'=>$input['max_uses']??null,'used_count'=>$usedCount,'starts_at'=>$input['starts_at']??null,'ends_at'=>$input['ends_at']??null,'created_at'=>$now,'updated_at'=>$now,'revision'=>1]);}catch(CouponContractException $e){throw new PersistenceException(422,'INVALID_COUPON',$e,$e->getMessage());}}
 private function view(array $coupon):array{return [...$coupon,'status'=>$this->resolver->status($coupon,new DateTimeImmutable('now',new DateTimeZone('UTC')))];}
}
