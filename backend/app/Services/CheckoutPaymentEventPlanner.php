<?php

namespace App\Services;

use App\Exceptions\CheckoutPaymentEventException;

/** Literal counterpart of planificarPedidoEventoPago_. */
final class CheckoutPaymentEventPlanner
{
    /** @param array<string,mixed> $order @param array<string,mixed> $event @param array{revision:int,paid_at:?string,payment_last_event_at:?string,combination:string} $state @return array{changed:bool,event_result:string,values:array<string,mixed>} */
    public function plan(array $order,array $event,array $state,string $processingNow):array
    {
        $values=$order;$last=$state['payment_last_event_at'];$advance=$last===null||$event['event_occurred_ms']>(new CheckoutUtcTimestamp)->parse($last)['epoch_ms'];
        $mutate=function()use(&$values,$event,$state,$advance,$processingNow):bool{if(!$advance)return false;$values['payment_last_event_at']=$event['event_occurred_at'];$values['updated_at']=$processingNow;$values['revision']=$this->next($state['revision']);return true;};
        if($event['status']!=='APPROVED')return ['changed'=>$mutate(),'event_result'=>'RECORDED','values'=>$values];
        if(($order['reservation_status']??null)==='CONSUMED'&&($order['payment_status']??null)==='APPROVED')return ['changed'=>$mutate(),'event_result'=>'APPROVED','values'=>$values];
        if(($order['reservation_status']??null)==='ACTIVE'){$values['payment_status']='APPROVED';$values['reservation_status']='CONSUMED';$values['paid_at']=$event['event_occurred_at'];$values['payment_last_event_at']=$event['event_occurred_at'];$values['updated_at']=$processingNow;$values['revision']=$this->next($state['revision']);return ['changed'=>true,'event_result'=>'APPROVED','values'=>$values];}
        if(($order['reservation_status']??null)==='RELEASED'){$changed=false;if($order['payment_status']!=='APPROVED'){$values['payment_status']='APPROVED';$changed=true;}if($order['status']!=='PAYMENT_REVIEW_REQUIRED'){$values['status']='PAYMENT_REVIEW_REQUIRED';$changed=true;}if(!($order['paid_at']??null)){$values['paid_at']=$event['event_occurred_at'];$changed=true;}if(($order['payment_last_event_at']??null)!==$event['event_occurred_at']){$values['payment_last_event_at']=$event['event_occurred_at'];$changed=true;}if($changed){$values['updated_at']=$processingNow;$values['revision']=$this->next($state['revision']);}return ['changed'=>$changed,'event_result'=>'PAYMENT_REVIEW_REQUIRED','values'=>$values];}
        throw new CheckoutPaymentEventException('CONSISTENCY_UNCERTAIN');
    }
    private function next(int $revision):int{if($revision>=2147483647)throw new CheckoutPaymentEventException('CONSISTENCY_UNCERTAIN');return $revision+1;}
}
