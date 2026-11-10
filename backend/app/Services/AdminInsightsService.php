<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Números del panel (Dashboard y Clientes). Solo lectura.
 * Una venta = pedido con pago APROBADO. Las fechas se agrupan en hora de Colombia.
 */
final class AdminInsightsService
{
    private const TZ = 'America/Bogota';
    private const MAX_ORDERS = 20000;

    /** @return array<string,mixed> */
    public function dashboard(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = $from->diffInDays($to) + 1;
        $prevTo = $from->subDay();
        $prevFrom = $prevTo->subDays($days - 1);

        $current = $this->approvedOrders($from, $to);
        $previous = $this->approvedOrders($prevFrom, $prevTo);

        // Ventas por día (incluye días sin ventas, para la gráfica).
        $byDay = [];
        for ($d = $from; $d->lessThanOrEqualTo($to); $d = $d->addDay()) {
            $byDay[$d->toDateString()] = ['date' => $d->toDateString(), 'sales' => 0, 'orders' => 0];
        }
        foreach ($current as $order) {
            $key = $order['date'];
            if (isset($byDay[$key])) { $byDay[$key]['sales'] += $order['total']; $byDay[$key]['orders']++; }
        }

        $ids = array_column($current, 'id');
        $topProducts = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $rows = DB::connection('mysql')->table('order_items')
                ->whereIn('order_id', $chunk)
                ->selectRaw('product_id, MAX(product_name) as product_name, SUM(quantity) as units, SUM(subtotal_cop) as sales')
                ->groupBy('product_id')->get();
            foreach ($rows as $row) {
                $id = (int) $row->product_id;
                $topProducts[$id] ??= ['product_id' => $id, 'name' => (string) $row->product_name, 'units' => 0, 'sales' => 0];
                $topProducts[$id]['units'] += (int) $row->units;
                $topProducts[$id]['sales'] += (int) $row->sales;
            }
        }
        usort($topProducts, static fn (array $a, array $b): int => [$b['units'], $b['sales']] <=> [$a['units'], $a['sales']]);

        $cities = [];
        foreach ($current as $order) {
            $city = $order['city'] !== '' ? $order['city'] : 'Sin ciudad';
            $cities[$city] ??= ['city' => $city, 'orders' => 0, 'sales' => 0];
            $cities[$city]['orders']++;
            $cities[$city]['sales'] += $order['total'];
        }
        usort($cities, static fn (array $a, array $b): int => $b['orders'] <=> $a['orders']);

        $range = [$this->utcStart($from), $this->utcEnd($to)];
        $statusRows = DB::connection('mysql')->table('orders')
            ->whereBetween('created_at', $range)
            ->selectRaw('status, payment_status, COUNT(*) as n')->groupBy('status', 'payment_status')->get();
        $byStatus = [];
        foreach ($statusRows as $row) {
            $byStatus[] = ['status' => (string) $row->status, 'payment_status' => (string) $row->payment_status, 'count' => (int) $row->n];
        }

        // Pendientes de atender: pagados que aún no se han empezado a preparar (de cualquier fecha).
        $toAttend = DB::connection('mysql')->table('orders')->where('payment_status', 'APPROVED')->where('status', 'PENDING')->count();
        $notCompleted = DB::connection('mysql')->table('orders')
            ->whereBetween('created_at', $range)->where('status', 'PENDING')
            ->where('payment_status', '!=', 'APPROVED')->where('reservation_status', 'RELEASED')->count();

        return [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'previous_from' => $prevFrom->toDateString(), 'previous_to' => $prevTo->toDateString()],
            'current' => $this->summary($current),
            'previous' => $this->summary($previous),
            'by_day' => array_values($byDay),
            'top_products' => array_slice($topProducts, 0, 8),
            'cities' => array_slice($cities, 0, 6),
            'by_status' => $byStatus,
            'to_attend' => $toAttend,
            'payment_not_completed' => $notCompleted,
        ];
    }

    /** Clientes = compradores con al menos un pago aprobado, agrupados por correo. @return list<array<string,mixed>> */
    public function customers(): array
    {
        $rows = DB::connection('mysql')->table('orders')
            ->where('payment_status', 'APPROVED')
            ->orderBy('id')->limit(self::MAX_ORDERS)
            ->get(['customer_name', 'customer_email', 'customer_phone', 'city', 'total_cop', 'created_at']);

        $customers = [];
        foreach ($rows as $row) {
            $email = mb_strtolower(trim((string) $row->customer_email));
            if ($email === '') continue;
            $date = $this->localDate($row->created_at);
            $customers[$email] ??= ['email' => $email, 'name' => '', 'phone' => '', 'city' => '', 'orders' => 0, 'spent' => 0, 'first_purchase' => $date, 'last_purchase' => $date];
            $c = &$customers[$email];
            $c['name'] = (string) $row->customer_name;      // el más reciente queda (orden por id)
            $c['phone'] = (string) $row->customer_phone;
            $c['city'] = (string) $row->city;
            $c['orders']++;
            $c['spent'] += (int) $row->total_cop;
            if ($date > $c['last_purchase']) $c['last_purchase'] = $date;
            if ($date < $c['first_purchase']) $c['first_purchase'] = $date;
            unset($c);
        }

        $today = CarbonImmutable::now(self::TZ)->startOfDay();
        $list = array_map(static function (array $c) use ($today): array {
            $c['days_since_last'] = (int) CarbonImmutable::parse($c['last_purchase'], self::TZ)->diffInDays($today);
            return $c;
        }, array_values($customers));
        usort($list, static fn (array $a, array $b): int => $b['spent'] <=> $a['spent']);

        return $list;
    }

    /** @return list<array{id:int,total:int,city:string,date:string}> */
    private function approvedOrders(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return DB::connection('mysql')->table('orders')
            ->where('payment_status', 'APPROVED')
            ->whereBetween('created_at', [$this->utcStart($from), $this->utcEnd($to)])
            ->orderBy('id')->limit(self::MAX_ORDERS)
            ->get(['id', 'total_cop', 'city', 'created_at'])
            ->map(fn ($o): array => ['id' => (int) $o->id, 'total' => (int) $o->total_cop, 'city' => trim((string) $o->city), 'date' => $this->localDate($o->created_at)])
            ->all();
    }

    /** @param list<array{total:int}> $orders @return array<string,int> */
    private function summary(array $orders): array
    {
        $sales = array_sum(array_column($orders, 'total'));
        $count = count($orders);

        return ['sales' => $sales, 'orders' => $count, 'average_ticket' => $count ? intdiv($sales, $count) : 0];
    }

    private function utcStart(CarbonImmutable $day): string { return $day->setTimezone(self::TZ)->startOfDay()->utc()->format('Y-m-d H:i:s.v'); }
    private function utcEnd(CarbonImmutable $day): string { return $day->setTimezone(self::TZ)->endOfDay()->utc()->format('Y-m-d H:i:s.v'); }
    private function localDate(mixed $createdAt): string { return CarbonImmutable::parse((string) $createdAt, 'UTC')->setTimezone(self::TZ)->toDateString(); }
}
