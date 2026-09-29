<?php

namespace Tests\Unit\Services;

use App\Contracts\GoogleSheetsValuesClient;
use App\Exceptions\CheckoutConsistencyException;
use App\Repositories\CheckoutSheetsRepository;
use App\Services\CheckoutReleaseCandidateReader;
use App\Services\CheckoutUtcTimestamp;
use Tests\TestCase;

final class CheckoutReleaseCandidateReaderTest extends TestCase
{
    private const EXPIRY='2026-09-27T12:00:00.000Z';

    public function test_active_expired_after_grace_is_a_candidate(): void
    {
        $candidate=$this->reader()->candidates(20,new \DateTimeImmutable('2026-09-27T12:10:00.000Z'));
        $this->assertCount(1,$candidate);
        $this->assertSame(17,$candidate[0]['order_id']);
        $this->assertSame('TTV-CANDIDATE',$candidate[0]['reference']);
        $this->assertSame([], $candidate[0]['payment_attempts']);
    }

    public function test_grace_boundary_is_literal(): void
    {
        $reader=$this->reader();
        $this->assertSame([],$reader->candidates(20,new \DateTimeImmutable('2026-09-27T12:09:59.999Z')));
        $this->assertCount(1,$reader->candidates(20,new \DateTimeImmutable('2026-09-27T12:10:00.000Z')));
        $this->assertCount(1,$reader->candidates(20,new \DateTimeImmutable('2026-09-27T12:10:00.001Z')));
    }

    public function test_non_active_or_non_pending_states_are_not_candidates(): void
    {
        foreach([
            ['PENDING','PENDING','RELEASED'],
            ['PENDING','APPROVED','CONSUMED'],
            ['PAYMENT_REVIEW_REQUIRED','APPROVED','RELEASED'],
        ] as [$status,$paymentStatus,$reservationStatus]){
            $this->assertSame([],$this->reader($this->tables([$this->order($status,$paymentStatus,$reservationStatus)]))->candidates(20,new \DateTimeImmutable('2026-09-27T12:10:00.000Z')));
        }
    }

    public function test_pending_payment_is_returned_for_verification_and_approved_payment_excludes(): void
    {
        $pending=$this->reader($this->tables([$this->order()],[$this->payment('PENDING')]))->candidates(20,new \DateTimeImmutable('2026-09-27T12:10:00.000Z'));
        $this->assertCount(1,$pending);
        $this->assertSame([['wompi_transaction_id'=>'tx-1','status'=>'PENDING','amount_in_cents'=>500000,'currency'=>'COP','updated_at'=>'2026-09-27T11:00:00.000Z']],$pending[0]['payment_attempts']);
        $this->assertSame([],$this->reader($this->tables([$this->order()],[$this->payment('APPROVED')]))->candidates(20,new \DateTimeImmutable('2026-09-27T12:10:00.000Z')));
    }

    public function test_multiple_mixed_orders_preserve_sheet_order_and_limit(): void
    {
        $released=$this->order('PENDING','PENDING','RELEASED',18,'TTV-RELEASED');
        $active=$this->order('PENDING','PENDING','ACTIVE',19,'TTV-SECOND');
        $result=$this->reader($this->tables([$released,$this->order(),$active]))->candidates(1,new \DateTimeImmutable('2026-09-27T12:10:00.000Z'));
        $this->assertCount(1,$result);
        $this->assertSame(17,$result[0]['order_id']);
    }

    public function test_invalid_revision_or_timestamp_fails_closed_for_entire_global_state(): void
    {
        $invalidRevision=$this->order();$invalidRevision[25]='1.5';
        try{$this->reader($this->tables([$invalidRevision]))->candidates(20,new \DateTimeImmutable('2026-09-27T12:10:00.000Z'));$this->fail('Expected invalid revision rejection.');}catch(CheckoutConsistencyException){$this->addToAssertionCount(1);}
        $invalidTimestamp=$this->order();$invalidTimestamp[5]='2026-09-27 12:00:00';
        $this->expectException(CheckoutConsistencyException::class);
        $this->reader($this->tables([$invalidTimestamp]))->candidates(20,new \DateTimeImmutable('2026-09-27T12:10:00.000Z'));
    }

    public function test_invalid_non_candidate_row_fails_closed_like_apps_script_global_validation(): void
    {
        $invalid=$this->order('PENDING','PENDING','RELEASED',18,'TTV-BAD');$invalid[10]='';
        $this->expectException(CheckoutConsistencyException::class);
        $this->reader($this->tables([$this->order(),$invalid]))->candidates(20,new \DateTimeImmutable('2026-09-27T12:10:00.000Z'));
    }

    private function reader(?array $tables=null): CheckoutReleaseCandidateReader
    {
        $tables ??=$this->tables([$this->order()]);
        $client=new class($tables) implements GoogleSheetsValuesClient {
            public function __construct(private array $tables) {}
            public function getValues(string $range): array { return []; }
            public function batchGetValues(array $ranges): array { return array_map(function(string $range):array{$sheet=explode('!',$range,2)[0];return str_ends_with($range,'1:1')?[array_values($this->tables[$sheet][0])]:$this->tables[$sheet];},$ranges); }
            public function updateValues(string $range,array $values): array { throw new \LogicException('Candidate reader must not write.'); }
            public function appendValues(string $range,array $values): array { throw new \LogicException('Candidate reader must not write.'); }
            public function batchUpdateValues(array $data): array { throw new \LogicException('Candidate reader must not write.'); }
        };
        return new CheckoutReleaseCandidateReader(new CheckoutSheetsRepository($client),new CheckoutUtcTimestamp);
    }

    /** @param list<list<mixed>> $orders @param list<list<mixed>> $payments */
    private function tables(array $orders,array $payments=[]): array
    {
        return ['Pedidos'=>[self::orderHeaders(),...$orders],'Pagos'=>[self::paymentHeaders(),...$payments]];
    }

    /** @return list<string> */
    private static function orderHeaders(): array
    { return ['order_id','reference','status','payment_status','reservation_status','reservation_expires_at','paid_at','payment_last_event_at','checkout_idempotency_key','checkout_payload_hash','release_id','release_fingerprint','released_at','customer_name','customer_email','customer_phone','customer_document','address','extra','city','region','postal','total_cop','created_at','updated_at','revision']; }
    /** @return list<string> */
    private static function paymentHeaders(): array
    { return ['payment_attempt_id','order_id','wompi_transaction_id','status','payment_method','amount_in_cents','currency','created_at','updated_at']; }
    /** @return list<mixed> */
    private function order(string $status='PENDING',string $paymentStatus='PENDING',string $reservationStatus='ACTIVE',int $id=17,string $reference='TTV-CANDIDATE'): array
    {
        $released=$reservationStatus==='RELEASED';$approved=$paymentStatus==='APPROVED';
        return [$id,$reference,$status,$paymentStatus,$reservationStatus,self::EXPIRY,$approved?'2026-09-27T11:30:00.000Z':'',$approved?'2026-09-27T11:30:00.000Z':'','key-'.$id,str_repeat('a',64),$released?'11111111-1111-4111-8111-111111111111':'',$released?str_repeat('b',64):'',$released?'2026-09-27T11:20:00.000Z':'','Customer','customer@example.test','+573000000000','1000000000','Address','','City','Region','',5000,'2026-09-27T11:00:00.000Z','2026-09-27T11:00:00.000Z',1];
    }
    /** @return list<mixed> */
    private function payment(string $status): array
    { return [1,17,'tx-1',$status,'CARD',500000,'COP','2026-09-27T11:00:00.000Z','2026-09-27T11:00:00.000Z']; }
}
