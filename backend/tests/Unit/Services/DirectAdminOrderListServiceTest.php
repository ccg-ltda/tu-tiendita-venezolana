<?php

namespace Tests\Unit\Services;

use App\Contracts\GoogleSheetsValuesClient;
use App\Repositories\CheckoutSheetsRepository;
use App\Services\DirectAdminOrderListService;
use Tests\TestCase;

final class DirectAdminOrderListServiceTest extends TestCase
{
    public function test_it_maps_sorts_and_pages_direct_order_rows_without_mutating_them(): void
    {
        $rows = [$this->order(2, 'TTV-2'), $this->order(9, 'TTV-9'), $this->order(4, 'TTV-4')];
        [$service, $client] = $this->service($rows);

        $result = $service->list(1, 2);

        $this->assertSame([9, 4], array_column($result['orders'], 'id'));
        $this->assertSame('APPROVED', $result['orders'][0]['payment_status']);
        $this->assertSame('2026-09-30T10:00:00.000Z', $result['orders'][0]['created_at']);
        $this->assertSame(['current_page' => 1, 'per_page' => 2, 'total' => 3, 'last_page' => 2], $result['pagination']);
        $this->assertSame($rows, array_slice($client->tables['Pedidos'], 1));
    }

    public function test_out_of_range_page_is_empty_but_keeps_the_existing_pagination_contract(): void
    {
        [$service] = $this->service([$this->order(2, 'TTV-2')]);

        $result = $service->list(9, 25);

        $this->assertSame([], $result['orders']);
        $this->assertSame(['current_page' => 9, 'per_page' => 25, 'total' => 1, 'last_page' => 1], $result['pagination']);
    }

    public function test_empty_source_has_last_page_one(): void
    {
        [$service] = $this->service([]);

        $this->assertSame(['orders' => [], 'pagination' => ['current_page' => 1, 'per_page' => 25, 'total' => 0, 'last_page' => 1]], $service->list(1, 25));
    }

    /** @param list<list<mixed>> $orders @return array{0:DirectAdminOrderListService,1:object} */
    private function service(array $orders): array
    {
        $tables = ['Pedidos' => [self::headers(), ...$orders]];
        $client = new class($tables) implements GoogleSheetsValuesClient {
            public function __construct(public array $tables) {}
            public function getValues(string $range): array { return []; }
            public function batchGetValues(array $ranges): array { return array_map(fn (string $range): array => str_ends_with($range, '1:1') ? [$this->tables['Pedidos'][0]] : $this->tables['Pedidos'], $ranges); }
            public function updateValues(string $range, array $values): array { throw new \LogicException('Read only.'); }
            public function appendValues(string $range, array $values): array { throw new \LogicException('Read only.'); }
            public function batchUpdateValues(array $data): array { throw new \LogicException('Read only.'); }
        };
        return [new DirectAdminOrderListService(new CheckoutSheetsRepository($client)), $client];
    }

    /** @return list<string> */
    private static function headers(): array
    {
        return ['order_id','reference','status','payment_status','reservation_status','reservation_expires_at','paid_at','payment_last_event_at','checkout_idempotency_key','checkout_payload_hash','release_id','release_fingerprint','released_at','customer_name','customer_email','customer_phone','customer_document','address','extra','city','region','postal','total_cop','created_at','updated_at','revision'];
    }

    /** @return list<mixed> */
    private function order(int $id, string $reference): array
    {
        return [$id,$reference,'PENDING','APPROVED','CONSUMED','2026-09-30T11:00:00.000Z','2026-09-30T10:00:00.000Z','2026-09-30T10:00:00.000Z',sprintf('00000000-0000-4000-8000-%012d',$id),str_repeat('a',64),'','','','Cliente','customer@example.test','3000000000','1','Calle 1','','Bogota','Bogota','',10000,'2026-09-30T10:00:00.000Z','2026-09-30T10:00:00.000Z',1];
    }
}
