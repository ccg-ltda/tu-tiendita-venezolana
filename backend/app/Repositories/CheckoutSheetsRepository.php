<?php

namespace App\Repositories;

use App\Contracts\GoogleSheetsValuesClient;
use App\Exceptions\CheckoutConsistencyException;
use App\Services\CheckoutReleaseDurableMarkerVerifier;
use App\Services\CheckoutReleaseFinalOrderVerifier;
use App\Services\CheckoutReleaseOrderBeforeVerifier;
use App\Services\CheckoutReleasePaymentStateClassifier;
use App\Services\CheckoutUtcTimestamp;

final class CheckoutSheetsRepository
{
    private const PRODUCTS_HEADERS=['product_id','category','subcategory','name','presentation','price_cop','inventory','active','image_path','legacy_img','created_at','updated_at','revision'];
    private const ORDERS_HEADERS=['order_id','reference','status','payment_status','reservation_status','reservation_expires_at','paid_at','payment_last_event_at','checkout_idempotency_key','checkout_payload_hash','release_id','release_fingerprint','released_at','customer_name','customer_email','customer_phone','customer_document','address','extra','city','region','postal','total_cop','created_at','updated_at','revision'];
    private const ORDER_ITEMS_HEADERS=['order_item_id','order_id','product_id','product_name','unit_price_cop','quantity','created_at'];
    private const PAYMENTS_HEADERS=['payment_attempt_id','order_id','wompi_transaction_id','status','payment_method','amount_in_cents','currency','created_at','updated_at'];

    public function __construct(private readonly GoogleSheetsValuesClient $sheets) {}

    /** @return list<array<string,mixed>> */
    public function readProducts(): array
    { return $this->readTable('Productos',self::PRODUCTS_HEADERS,fn(array $row):array=>$this->product($row)); }
    /** @return list<array<string,mixed>> */
    public function readOrders(): array
    { return $this->readTable('Pedidos',self::ORDERS_HEADERS,fn(array $row):array=>$this->order($row)); }
    /** @return list<array<string,mixed>> */
    public function readOrderItems(): array
    { return $this->readTable('PedidoItems',self::ORDER_ITEMS_HEADERS,fn(array $row):array=>$this->orderItem($row)); }
    /** @return list<array<string,mixed>> */
    public function readPayments(): array
    { return $this->readTable('Pagos',self::PAYMENTS_HEADERS,fn(array $row):array=>$this->payment($row)); }
    /** Raw rows for the record_payment_event port. Its Apps Script counterpart
     * validates only the selected order, while validating every payment itself. */
    /** @return list<array<string,mixed>> */
    public function readOrdersForPaymentEvent(): array
    {
        $rows=$this->readRawTable('Pedidos',self::ORDERS_HEADERS);
        return array_map(fn(array $row):array=>$this->paymentEventOrderRow($row),$rows);
    }
    /** @return list<array<string,mixed>> */
    public function readPaymentsForPaymentEvent(): array
    {
        $rows=$this->readRawTable('Pagos',self::PAYMENTS_HEADERS);
        return array_map(fn(array $row):array=>$this->paymentEventRow($row),$rows);
    }
    /** Raw persisted rows for the literal admin_update_order_status port. */
    /** @return list<array<string,mixed>> */
    public function readOrdersForAdminStatus(): array
    { return $this->readRawTable('Pedidos',self::ORDERS_HEADERS); }
    /**
     * Read-only source for the direct release-candidate port. Values API
     * formatted numbers are normalized only for persisted checkout state;
     * the caller performs Apps Script's complete release global validation.
     * @return list<array<string,mixed>>
     */
    public function readOrdersForReleaseCandidates(): array
    {
        $rows=$this->readRawTable('Pedidos',self::ORDERS_HEADERS);
        return array_map(fn(array $row):array=>$this->releaseCandidateOrderRow($row),$rows);
    }
    /** @return list<array<string,mixed>> */
    public function readPaymentsForReleaseCandidates(): array
    {
        $rows=$this->readRawTable('Pagos',self::PAYMENTS_HEADERS);
        return array_map(fn(array $row):array=>$this->paymentEventRow($row),$rows);
    }

    /** @return array<string,mixed>|null */
    public function findProductById(int $productId): ?array
    { foreach($this->readProducts() as $product)if($product['product_id']===$productId)return $product;return null; }
    /** @return array<string,mixed>|null */
    public function findOrderByIdempotencyKey(string $key): ?array
    { foreach($this->readOrders() as $order)if($order['checkout_idempotency_key']===$key)return $order;return null; }
    /** @return array<string,mixed>|null */
    public function findOrderByReference(string $reference): ?array
    { foreach($this->readOrders() as $order)if($order['reference']===$reference)return $order;return null; }
    /** @return list<array<string,mixed>> */
    public function findPaymentsByOrderId(int $orderId): array
    { return array_values(array_filter($this->readPayments(),fn(array $payment):bool=>$payment['order_id']===$orderId)); }
    /** @return array<string,mixed>|null */
    public function readOrderById(int $orderId): ?array
    { foreach($this->readOrders() as $order)if($order['order_id']===$orderId)return $order;return null; }
    /** @return list<array<string,mixed>> */
    public function readOrderItemsByOrderId(int $orderId): array
    { return array_values(array_filter($this->readOrderItems(),fn(array $item):bool=>$item['order_id']===$orderId)); }
    /** @param list<int> $productIds @return list<array<string,mixed>> */
    public function readProductsByIds(array $productIds): array
    { $wanted=array_fill_keys($productIds,true);return array_values(array_filter($this->readProducts(),fn(array $product):bool=>isset($wanted[$product['product_id']]))); }

    /** @param list<mixed> $finalOrderValues @return array<string,mixed> */
    public function writeTechnicalOrder(int $rowNumber, array $finalOrderValues): array
    {
        $this->validRow($rowNumber);$this->validValues($finalOrderValues,self::ORDERS_HEADERS);
        $values=$finalOrderValues;$values[2]='RESERVATION_PREPARING';$values[3]='';$values[4]='';$values[5]='';$values[25]=0;
        $this->sheets->updateValues('Pedidos!A'.$rowNumber.':Z'.$rowNumber,[$values]);
        $order=$this->readOrderById((int)$values[0]);
        if($order===null||!$this->same($order,$values,self::ORDERS_HEADERS))throw new CheckoutConsistencyException;
        return $order;
    }
    /** @param list<list<mixed>> $itemRows */
    public function appendOrderItems(array $itemRows): void
    {
        if($itemRows===[])throw new CheckoutConsistencyException;
        foreach($itemRows as $row)$this->validValues($row,self::ORDER_ITEMS_HEADERS);
        $this->sheets->appendValues('PedidoItems!A:G',$itemRows);
        $orderId=(int)$itemRows[0][1];$actual=$this->readOrderItemsByOrderId($orderId);
        foreach($itemRows as $expected){$matches=array_values(array_filter($actual,fn(array $item):bool=>$item['order_item_id']===(int)$expected[0]));if(count($matches)!==1||!$this->same($matches[0],$expected,self::ORDER_ITEMS_HEADERS))throw new CheckoutConsistencyException;}
    }
    /** @param list<array{product_id:int,row_number:int,inventory_before:int,inventory_after:int,revision_before:int,revision_after:int}> $inventoryPlan */
    public function applyInventoryPlan(array $inventoryPlan): void
    {
        foreach($inventoryPlan as $change){$this->validInventoryChange($change);$products=$this->readProductsByIds([$change['product_id']]);if(count($products)!==1)throw new CheckoutConsistencyException;$product=$products[0];if($product['sheet_row']!==$change['row_number']||$product['inventory']!==$change['inventory_before']||$product['revision']!==$change['revision_before'])throw new CheckoutConsistencyException;$values=$this->valuesFromRow($product,self::PRODUCTS_HEADERS);$values[6]=$change['inventory_after'];$values[11]=now('UTC')->format('Y-m-d\\TH:i:s.v\\Z');$values[12]=$change['revision_after'];$this->sheets->updateValues('Productos!A'.$change['row_number'].':M'.$change['row_number'],[$values]);$verified=$this->readProductsByIds([$change['product_id']]);if(count($verified)!==1||$verified[0]['inventory']!==$change['inventory_after']||$verified[0]['revision']!==$change['revision_after'])throw new CheckoutConsistencyException;}
    }
    /** @param list<mixed> $finalOrderValues @return array<string,mixed> */
    public function writeFinalOrder(int $rowNumber,array $finalOrderValues): array
    {
        $this->validRow($rowNumber);$this->validValues($finalOrderValues,self::ORDERS_HEADERS);
        $this->sheets->updateValues('Pedidos!A'.$rowNumber.':Z'.$rowNumber,[$finalOrderValues]);
        $order=$this->readOrderById((int)$finalOrderValues[0]);
        if($order===null||!$this->same($order,$finalOrderValues,self::ORDERS_HEADERS))throw new CheckoutConsistencyException;
        return $order;
    }
    /**
     * Restricted caller-facing primitive for a disposable laboratory command.
     * The command independently rejects the production spreadsheet and uses it
     * only to restore a temporary invalid price after a no-write assertion.
     * @param list<mixed> $productValues
     * @return array<string,mixed>
     */
    public function replaceProductRowForLaboratory(int $rowNumber,array $productValues): array
    {
        $this->validRow($rowNumber);$this->validValues($productValues,self::PRODUCTS_HEADERS);
        $this->sheets->updateValues('Productos!A'.$rowNumber.':M'.$rowNumber,[$productValues]);
        $products=$this->readProductsByIds([(int)$productValues[0]]);
        if(count($products)!==1||$products[0]['sheet_row']!==$rowNumber||!$this->same($products[0],$productValues,self::PRODUCTS_HEADERS))throw new CheckoutConsistencyException;
        return $products[0];
    }
    /** @param list<array{product_id:int,row_number:int,inventory_before:int,inventory_after:int,revision_before:int,revision_after:int,updated_at:string}> $plan */
    public function restoreReleaseInventory(array $plan): void
    { foreach($plan as $change){$products=$this->readProductsByIds([$change['product_id']]);$p=$products[0]??null;if($p===null||$p['sheet_row']!==$change['row_number']||$p['inventory']!==$change['inventory_before']||$p['revision']!==$change['revision_before']||$change['inventory_after']<$change['inventory_before'])throw new CheckoutConsistencyException;$v=$this->valuesFromRow($p,self::PRODUCTS_HEADERS);$v[6]=$change['inventory_after'];$v[11]=$change['updated_at'];$v[12]=$change['revision_after'];$this->sheets->updateValues('Productos!A'.$change['row_number'].':M'.$change['row_number'],[$v]);$after=$this->readProductsByIds([$change['product_id']])[0]??null;if($after===null||$after['inventory']!==$change['inventory_after']||$after['revision']!==$change['revision_after'])throw new CheckoutConsistencyException;} }
    /** Absolute idempotent release writes; caller has proven each row BEFORE or AFTER. @param list<array<string,mixed>> $plan */
    public function writeReleaseInventoryAfter(array $plan): void
    { foreach($plan as $change){$p=$this->readProductsByIds([$change['product_id']])[0]??null;if($p===null||$p['sheet_row']!==$change['row_number'])throw new CheckoutConsistencyException;$v=$this->valuesFromRow($p,self::PRODUCTS_HEADERS);$v[6]=$change['inventory_after'];$v[11]=$change['updated_at_after'];$v[12]=$change['revision_after'];$this->sheets->updateValues('Productos!A'.$change['row_number'].':M'.$change['row_number'],[$v]);$after=$this->readProductsByIds([$change['product_id']])[0]??null;if($after===null||$after['inventory']!==$change['inventory_after']||$after['revision']!==$change['revision_after']||$after['updated_at']!==$change['updated_at_after'])throw new CheckoutConsistencyException;} }
    /** Absolute, idempotent release payment write. @param array<string,mixed> $change */
    public function writeReleasePaymentAfter(array $change): void
    {
        $payment=null;foreach($this->readPayments() as $row)if($row['payment_attempt_id']===$change['payment_attempt_id'])$payment=$row;
        if($payment===null||$payment['sheet_row']!==$change['row_number'])throw new CheckoutConsistencyException;
        $classifier=new CheckoutReleasePaymentStateClassifier(new CheckoutUtcTimestamp);
        $state=$classifier->classify($payment,$change);
        if($state==='BEFORE'){$v=$this->valuesFromRow($payment,self::PAYMENTS_HEADERS);$v[3]=$change['status_after'];$v[8]=$change['updated_at_after'];$this->sheets->updateValues('Pagos!A'.$change['row_number'].':I'.$change['row_number'],[$v]);}
        $after=null;foreach($this->readPayments() as $row)if($row['payment_attempt_id']===$change['payment_attempt_id'])$after=$row;
        if($after===null||$classifier->classify($after,$change)!=='AFTER')throw new CheckoutConsistencyException;
    }
    /** Absolute release order write, verified as Apps Script's durable journal marker. @param array<string,mixed> $expected */
    public function writeReleaseOrderAfter(int $rowNumber,array $expected): array
    {
        $order=$this->readOrderById($expected['order_id']??0);if($order===null||$order['sheet_row']!==$rowNumber)throw new CheckoutConsistencyException;
        $before=new CheckoutReleaseOrderBeforeVerifier(new CheckoutUtcTimestamp);
        $marker=new CheckoutReleaseDurableMarkerVerifier(new CheckoutUtcTimestamp);
        if($before->matches($order,$expected)){
            $v=$this->valuesFromRow($order,self::ORDERS_HEADERS);$v[2]=$expected['status_after'];$v[3]=$expected['payment_status_after'];$v[4]=$expected['reservation_status_after'];$v[6]=$expected['paid_at_after'];$v[7]=$expected['payment_last_event_at_after'];$v[10]=$expected['release_id'];$v[11]=$expected['release_fingerprint'];$v[12]=$expected['released_at_after'];$v[24]=$expected['updated_at_after'];$v[25]=$expected['revision_after'];$this->sheets->updateValues('Pedidos!A'.$rowNumber.':Z'.$rowNumber,[$v]);
        }elseif(!$marker->matches($order,$expected)){
            throw new CheckoutConsistencyException;
        }
        $after=$this->readOrderById($expected['order_id']);$final=new CheckoutReleaseFinalOrderVerifier(new CheckoutUtcTimestamp);if($after===null||!$final->matches($after,$expected))throw new CheckoutConsistencyException;return $after;
    }
    /** @param list<array{row_number:int,payment_attempt_id:int,status:string,updated_at:string}> $plan */
    public function updateReleasePayments(array $plan): void
    { foreach($plan as $change){$payment=null;foreach($this->readPayments() as $row)if($row['payment_attempt_id']===$change['payment_attempt_id'])$payment=$row;if($payment===null||$payment['sheet_row']!==$change['row_number']||$payment['status']!=='PENDING')throw new CheckoutConsistencyException;$v=$this->valuesFromRow($payment,self::PAYMENTS_HEADERS);$v[3]=$change['status'];$v[8]=$change['updated_at'];$this->sheets->updateValues('Pagos!A'.$change['row_number'].':I'.$change['row_number'],[$v]);$after=null;foreach($this->readPayments() as $row)if($row['payment_attempt_id']===$change['payment_attempt_id'])$after=$row;if($after===null||$after['status']!==$change['status']||$after['updated_at']!==$change['updated_at'])throw new CheckoutConsistencyException;} }
    /** @param list<mixed> $values */
    public function writeReleasedOrder(int $rowNumber,array $values): array { return $this->writeFinalOrder($rowNumber,$values); }
    /** @param list<mixed> $values */
    public function appendPaymentEventRow(array $values): void
    {
        $this->validValues($values,self::PAYMENTS_HEADERS);$this->sheets->appendValues('Pagos!A:I',[$values]);
        $expected=$this->mapValues($values,self::PAYMENTS_HEADERS);$matches=array_values(array_filter($this->readPaymentsForPaymentEvent(),fn(array $row):bool=>$row['payment_attempt_id']===$expected['payment_attempt_id']));
        if(count($matches)!==1||!$this->samePaymentEvent($matches[0],$expected))throw new CheckoutConsistencyException;
    }
    /** @param list<mixed> $values */
    public function writePaymentEventRow(int $rowNumber,array $values): void
    {
        $this->validRow($rowNumber);$this->validValues($values,self::PAYMENTS_HEADERS);$this->sheets->updateValues('Pagos!A'.$rowNumber.':I'.$rowNumber,[$values]);
        $expected=$this->mapValues($values,self::PAYMENTS_HEADERS);$matches=array_values(array_filter($this->readPaymentsForPaymentEvent(),fn(array $row):bool=>$row['sheet_row']===$rowNumber));
        if(count($matches)!==1||!$this->samePaymentEvent($matches[0],$expected))throw new CheckoutConsistencyException;
    }
    /** @param list<mixed> $values */
    public function writePaymentEventOrderRow(int $rowNumber,array $values): void
    {
        $this->validRow($rowNumber);$this->validValues($values,self::ORDERS_HEADERS);$this->sheets->updateValues('Pedidos!A'.$rowNumber.':Z'.$rowNumber,[$values]);
        $expected=$this->mapValues($values,self::ORDERS_HEADERS);$matches=array_values(array_filter($this->readOrdersForPaymentEvent(),fn(array $row):bool=>$row['sheet_row']===$rowNumber));
        if(count($matches)!==1||!$this->samePaymentEvent($matches[0],$expected))throw new CheckoutConsistencyException;
    }
    /**
     * Full-row write for admin status only. The caller must build $values from
     * the raw row reread while holding CheckoutLock; this primitive verifies it
     * durably and never supplies an alternate snapshot.
     * @param list<mixed> $values @return array<string,mixed>
     */
    public function writeAdminOrderStatusAfter(int $rowNumber,array $values): array
    {
        $this->validRow($rowNumber);$this->validValues($values,self::ORDERS_HEADERS);$expectedIntegers=[];
        foreach(['order_id'=>1,'total_cop'=>0,'revision'=>1] as $field=>$minimum){$expectedIntegers[$field]=$this->storedPaymentEventInteger($values[array_search($field,self::ORDERS_HEADERS,true)],$minimum);}
        foreach($expectedIntegers as $field=>$value)$values[array_search($field,self::ORDERS_HEADERS,true)]=$value;
        $this->sheets->updateValues('Pedidos!A'.$rowNumber.':Z'.$rowNumber,[$values]);
        $matches=array_values(array_filter($this->readOrdersForAdminStatus(),fn(array $row):bool=>$row['sheet_row']===$rowNumber));
        if(count($matches)!==1)throw new CheckoutConsistencyException;
        foreach(self::ORDERS_HEADERS as $index=>$field){if(isset($expectedIntegers[$field])){if($this->storedPaymentEventInteger($matches[0][$field]??null,$field==='total_cop'?0:1)!==$expectedIntegers[$field])throw new CheckoutConsistencyException;}elseif($matches[0][$field]!==$values[$index])throw new CheckoutConsistencyException;}
        return $matches[0];
    }

    /** @param list<string> $headers @param callable(array<string,mixed>):array<string,mixed> $normalizer @return list<array<string,mixed>> */
    private function readTable(string $sheet,array $headers,callable $normalizer): array
    {
        [$headerRows,$tableRows]=$this->sheets->batchGetValues([$sheet.'!1:1',$sheet.'!A:'.$this->columnLetter(count($headers))]);
        $header=$headerRows[0]??null;
        if(!is_array($header)||$header!==$headers)throw new CheckoutConsistencyException;
        $rows=[];
        foreach(array_slice($tableRows,1) as $offset=>$values){
            if(!is_array($values))throw new CheckoutConsistencyException;
            $values=array_slice(array_pad($values,count($headers),''),0,count($headers));
            if(!array_filter($values,fn(mixed $value):bool=>$value!==''&&$value!==null))continue;
            $row=[];foreach($headers as $index=>$name)$row[$name]=$values[$index];
            $rows[]=$normalizer($row)+['sheet_row'=>$offset+2];
        }
        return $this->assertUnique($sheet,$rows);
    }
    /** @param list<string> $headers @return list<array<string,mixed>> */
    private function readRawTable(string $sheet,array $headers): array
    {
        [$headerRows,$tableRows]=$this->sheets->batchGetValues([$sheet.'!1:1',$sheet.'!A:'.$this->columnLetter(count($headers))]);$header=$headerRows[0]??null;
        if(!is_array($header)||$header!==$headers)throw new CheckoutConsistencyException;$rows=[];
        foreach(array_slice($tableRows,1) as $offset=>$values){if(!is_array($values))throw new CheckoutConsistencyException;$values=array_slice(array_pad($values,count($headers),''),0,count($headers));if(!array_filter($values,fn(mixed $value):bool=>$value!==''&&$value!==null))continue;$rows[]=$this->mapValues($values,$headers)+['sheet_row'=>$offset+2];}
        return $rows;
    }
    private function columnLetter(int $number): string
    { $letter='';while($number>0){$number--; $letter=chr(65+$number%26).$letter;$number=intdiv($number,26);}return $letter; }
    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function assertUnique(string $sheet,array $rows): array
    {
        $fields=match($sheet){'Productos'=>['product_id'],'Pedidos'=>['order_id','reference','checkout_idempotency_key'],'PedidoItems'=>['order_item_id'],'Pagos'=>['payment_attempt_id','wompi_transaction_id']};
        foreach($fields as $field){$seen=[];foreach($rows as $row){$value=$row[$field];if($value===''&&$field==='checkout_idempotency_key')continue;if(isset($seen[(string)$value]))throw new CheckoutConsistencyException;$seen[(string)$value]=true;}}
        return $rows;
    }
    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function product(array $row): array
    { $this->positive($row['product_id']);$this->integer($row['price_cop']);$this->nonNegative($row['inventory']);$this->positive($row['revision']);if(!is_string($row['name'])||trim($row['name'])==='')throw new CheckoutConsistencyException;if(!in_array($row['active'],[true,'true','TRUE'],true)&&!in_array($row['active'],[false,'false','FALSE'],true))throw new CheckoutConsistencyException;$row['product_id']=(int)$row['product_id'];$row['price_cop']=(int)$row['price_cop'];$row['inventory']=(int)$row['inventory'];$row['revision']=(int)$row['revision'];$row['active']=in_array($row['active'],[true,'true','TRUE'],true);return $row; }
    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function order(array $row): array
    { $this->positive($row['order_id']);$this->positive($row['revision'],true);$this->text($row['reference'],1,120);$this->text($row['checkout_idempotency_key'],0,36);$this->hashOrBlank($row['checkout_payload_hash']);$this->nonNegative($row['total_cop']);foreach(['status','payment_status','reservation_status'] as $field)$this->text($row[$field],0,100);$row['order_id']=(int)$row['order_id'];$row['revision']=(int)$row['revision'];$row['total_cop']=(int)$row['total_cop'];return $row; }
    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function orderItem(array $row): array
    { foreach(['order_item_id','order_id','product_id','unit_price_cop','quantity'] as $field)$this->positive($row[$field]);$this->text($row['product_name'],1,500);foreach(['order_item_id','order_id','product_id','unit_price_cop','quantity'] as $field)$row[$field]=(int)$row[$field];return $row; }
    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function payment(array $row): array
    { foreach(['payment_attempt_id','order_id','amount_in_cents'] as $field)$this->positive($row[$field]);$this->text($row['wompi_transaction_id'],1,200);$this->text($row['payment_method'],1,100);if(!in_array($row['status'],['PENDING','APPROVED','DECLINED','VOIDED','ERROR'],true)||$row['currency']!=='COP')throw new CheckoutConsistencyException;foreach(['payment_attempt_id','order_id','amount_in_cents'] as $field)$row[$field]=(int)$row[$field];return $row; }
    /**
     * The Values API's default FORMATTED_VALUE rendering returns numeric Sheet
     * cells as decimal strings. Apps Script getValues() yields numbers instead.
     * This is deliberately limited to persisted payment-event Sheet rows;
     * external transaction input remains validated by CheckoutPaymentEventNormalizer.
     * @param array<string,mixed> $row @return array<string,mixed>
     */
    private function paymentEventRow(array $row): array
    {
        foreach(['payment_attempt_id','order_id','amount_in_cents'] as $field)$row[$field]=$this->storedPaymentEventInteger($row[$field]??null,1);
        return $row;
    }
    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function paymentEventOrderRow(array $row): array
    {
        $row['order_id']=$this->storedPaymentEventInteger($row['order_id']??null,1);
        $row['total_cop']=$this->storedPaymentEventInteger($row['total_cop']??null,0);
        $row['revision']=$this->storedPaymentEventInteger($row['revision']??null,1);
        return $row;
    }
    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function releaseCandidateOrderRow(array $row): array
    {
        $row['order_id']=$this->storedPaymentEventInteger($row['order_id']??null,1);
        $row['total_cop']=$this->storedPaymentEventInteger($row['total_cop']??null,0);
        // Apps Script permits revision 0 only for RESERVATION_PREPARING.
        $row['revision']=$this->storedPaymentEventInteger($row['revision']??null,0);
        return $row;
    }
    private function storedPaymentEventInteger(mixed $value,int $minimum): int
    {
        if(is_int($value)){
            if($value<$minimum||$value>2147483647)throw new CheckoutConsistencyException;
            return $value;
        }
        if(!is_string($value)||preg_match('/^(?:0|[1-9][0-9]*)$/D',$value)!==1||strlen($value)>10||(strlen($value)===10&&strcmp($value,'2147483647')>0)||(int)$value<$minimum)throw new CheckoutConsistencyException;
        return (int)$value;
    }
    private function positive(mixed $value,bool $allowZero=false): void
    { if(!is_int($value)&&!(is_string($value)&&preg_match('/^\d+$/',$value)))throw new CheckoutConsistencyException;$number=(int)$value;if($number<($allowZero?0:1)||$number>2147483647)throw new CheckoutConsistencyException; }
    private function nonNegative(mixed $value): void
    { $this->positive($value,true); }
    private function integer(mixed $value): void
    { if(!is_int($value)&&!(is_string($value)&&preg_match('/^-?\d+$/',$value)))throw new CheckoutConsistencyException;if((int)$value < -2147483647 || (int)$value > 2147483647)throw new CheckoutConsistencyException; }
    private function text(mixed $value,int $min,int $max): void
    { if(!is_string($value)||$value!==trim($value)||strlen($value)<$min||strlen($value)>$max||preg_match('/[\x00-\x1F\x7F]/',$value))throw new CheckoutConsistencyException; }
    private function hashOrBlank(mixed $value): void
    { if($value===''||$value===null)return;if(!is_string($value)||preg_match('/^[0-9a-f]{64}$/D',$value)!==1)throw new CheckoutConsistencyException; }
    /** @param list<mixed> $values @param list<string> $headers */
    private function validValues(array $values,array $headers): void
    { if(count($values)!==count($headers))throw new CheckoutConsistencyException; }
    private function validRow(int $row): void
    { if($row<2)throw new CheckoutConsistencyException; }
    /** @param array<string,mixed> $change */
    private function validInventoryChange(array $change): void
    { if(array_keys($change)!==['product_id','row_number','inventory_before','inventory_after','revision_before','revision_after'])throw new CheckoutConsistencyException;foreach($change as $value)if(!is_int($value))throw new CheckoutConsistencyException;if($change['product_id']<1||$change['row_number']<2||$change['inventory_before']<0||$change['inventory_after']<0||$change['inventory_after']>$change['inventory_before']||$change['revision_before']<1||$change['revision_after']!==$change['revision_before']+1)throw new CheckoutConsistencyException; }
    /** @param array<string,mixed> $row @param list<string> $headers @return list<mixed> */
    private function valuesFromRow(array $row,array $headers): array
    { return array_map(fn(string $header):mixed=>$row[$header],$headers); }
    /** @param list<mixed> $values @param list<string> $headers @return array<string,mixed> */
    private function mapValues(array $values,array $headers):array{return array_combine($headers,$values)?:throw new CheckoutConsistencyException;}
    /** @param array<string,mixed> $actual @param array<string,mixed> $expected */
    private function samePaymentEvent(array $actual,array $expected):bool
    {foreach($expected as $field=>$value){if(in_array($field,['order_id','payment_attempt_id','amount_in_cents','total_cop','revision'],true)){if($actual[$field]!==$value)return false;}elseif($actual[$field]!==$value)return false;}return true;}
    /** @param array<string,mixed> $row @param list<mixed> $values @param list<string> $headers */
    private function same(array $row,array $values,array $headers): bool
    { foreach($headers as $index=>$header){$actual=$row[$header];$expected=$values[$index];if(in_array($header,['product_id','price_cop','inventory','revision','order_id','total_cop','order_item_id','unit_price_cop','quantity'],true)){if((int)$actual!==$expected)return false;}elseif($actual!==$expected)return false;}return true; }
}
