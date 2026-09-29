<?php

namespace App\Services;

use App\Exceptions\CheckoutConsistencyException;
use App\Exceptions\CheckoutLockTimeoutException;
use App\Exceptions\CheckoutReservationPlanningException;
use App\Repositories\CheckoutSheetsRepository;

/** Single switch point for the four coordinated checkout writers. */
final class CheckoutWriterGateway
{
    public function __construct(
        private readonly AppsScriptCheckoutClient $appsScript,
        private readonly CheckoutDirectPreparationService $preparation,
        private readonly CheckoutPaymentEventService $payments,
        private readonly CheckoutReleaseCandidateReader $candidates,
        private readonly CheckoutExpiredReservationReleaseService $releases,
        private readonly CheckoutAdminOrderStatusService $admin,
        private readonly CheckoutSheetsRepository $sheets,
    ) {}

    public function backend(): string { return config('checkout.writer_backend') === 'direct' ? 'direct' : 'apps_script'; }

    public function prepareCheckout(array $checkout): array
    {
        if ($this->backend() === 'apps_script') return $this->appsScript->prepareCheckout($checkout);
        try {
            $items=array_map(static fn(array $item):array=>['id'=>$item['product_id'],'qty'=>$item['quantity']],$checkout['items']);
            return $this->preparation->prepare(['customer'=>$checkout['customer'],'items'=>$items],$checkout['idempotency_key']);
        } catch (CheckoutReservationPlanningException $e) { throw $this->error($e->checkoutCode()); }
        catch (CheckoutLockTimeoutException) { throw $this->error('LOCK_TIMEOUT'); }
        catch (CheckoutConsistencyException) { throw $this->error('CONSISTENCY_UNCERTAIN'); }
    }

    public function recordPaymentEvent(array $transaction): array
    {
        if ($this->backend() === 'apps_script') return $this->appsScript->recordPaymentEvent($transaction);
        $result=$this->payments->record(['transaction'=>$transaction]);
        if (!($result['ok']??false)) throw $this->error($result['error']['code']??null);
        return $result['data'];
    }

    public function getExpiredReservationCandidates(int $limit=20): array
    {
        if ($this->backend() === 'apps_script') return $this->appsScript->getExpiredReservationCandidates($limit);
        try{return $this->candidates->candidates($limit);}catch(CheckoutConsistencyException){throw $this->error('CONSISTENCY_UNCERTAIN');}
    }

    public function commitExpiredReservations(array $releases): array
    {
        if ($this->backend() === 'apps_script') return $this->appsScript->commitExpiredReservations($releases);
        $result=['released'=>[],'held'=>[],'review_required'=>[]];
        foreach($releases as $release){
            try{$outcome=$this->releases->release($release['reference'],$release['expected_revision'],$release['verified_final_attempts']);}
            catch(CheckoutLockTimeoutException){throw $this->error('LOCK_TIMEOUT');}
            catch(CheckoutConsistencyException){throw $this->error('CONSISTENCY_UNCERTAIN');}
            catch(\RuntimeException $e){if($e->getMessage()==='ORDER_NOT_FOUND')throw $this->error('ORDER_NOT_FOUND');throw $e;}
            $kind=$outcome['result'];
            if(in_array($kind,['RELEASED','REPLAY'],true)){$order=$this->sheets->findOrderByReference($outcome['reference']);if($order===null)throw $this->error('CONSISTENCY_UNCERTAIN');$result['released'][]=['order_id'=>$order['order_id'],'reference'=>$outcome['reference'],'reservation_status'=>'RELEASED','revision'=>$outcome['revision'],'idempotency_replayed'=>$kind==='REPLAY'];}
            elseif($kind==='PAYMENT_APPROVED')$result['review_required'][]=['reference'=>$outcome['reference'],'reason'=>'PAYMENT_APPROVED'];
            else $result['held'][]=['reference'=>$outcome['reference'],'reason'=>'HELD'];
        }
        return $result;
    }

    public function adminGetOrder(int $orderId): array
    {
        if ($this->backend() === 'apps_script') return $this->appsScript->adminGetOrder($orderId);
        $order=$this->sheets->readOrderById($orderId);if($order===null)throw $this->error('ORDER_NOT_FOUND');
        $items=array_map(static fn(array $item):array=>['id'=>$item['order_item_id'],'product_id'=>$item['product_id'],'product_name'=>$item['product_name'],'unit_price'=>$item['unit_price_cop'],'quantity'=>$item['quantity']],$this->sheets->readOrderItemsByOrderId($orderId));
        return ['id'=>$order['order_id'],'reference'=>$order['reference'],'status'=>$order['status'],'payment_status'=>$order['payment_status'],'reservation_status'=>$order['reservation_status'],'paid_at'=>$order['paid_at']===''?null:$order['paid_at'],'payment'=>null,'customer_name'=>$order['customer_name'],'customer_email'=>$order['customer_email'],'customer_phone'=>$order['customer_phone'],'customer_document'=>$order['customer_document'],'address'=>$order['address'],'extra'=>$order['extra']===''?null:$order['extra'],'city'=>$order['city'],'region'=>$order['region'],'postal'=>$order['postal']===''?null:$order['postal'],'total'=>$order['total_cop'],'created_at'=>$order['created_at'],'items'=>$items];
    }

    public function adminUpdateOrderStatus(int $orderId,string $status): array
    {
        if ($this->backend() === 'apps_script') return $this->appsScript->adminUpdateOrderStatus($orderId,$status);
        $result=$this->admin->update(['order_id'=>$orderId,'status'=>$status]);
        if(!($result['ok']??false))throw $this->error($result['error']['code']??null);
        return $result['data'];
    }

    private function error(?string $code): AppsScriptCheckoutException
    {
        $status=match($code){'INVALID_REQUEST'=>400,'INSUFFICIENT_STOCK','PRODUCT_NOT_FOUND','PRODUCT_INACTIVE','INVALID_PRODUCT_PRICE','IDEMPOTENCY_CONFLICT','RESERVATION_EXPIRED'=>409,'ORDER_NOT_FOUND','NOT_FOUND'=>404,'AMOUNT_MISMATCH','CURRENCY_MISMATCH','TRANSACTION_CONFLICT','INVALID_STATUS_TRANSITION','PAYMENT_NOT_APPROVED'=>422,'LOCK_TIMEOUT'=>503,default=>502};
        return new AppsScriptCheckoutException($status,$code);
    }
}
