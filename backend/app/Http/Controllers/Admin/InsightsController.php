<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminInsightsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Dashboard (ventas, pedidos, más vendidos, ciudades) y Clientes del panel. */
class InsightsController extends Controller
{
    private const TZ = 'America/Bogota';

    public function dashboard(Request $request, AdminInsightsService $insights): JsonResponse
    {
        $today = CarbonImmutable::now(self::TZ)->startOfDay();
        $to = $this->date($request->query('to')) ?? $today;
        $from = $this->date($request->query('from')) ?? $to->subDays(29);
        if ($from->greaterThan($to) || $from->diffInDays($to) > 366) {
            return response()->json(['message' => 'Rango de fechas inválido.'], 422);
        }

        return response()->json($insights->dashboard($from, $to));
    }

    public function customers(AdminInsightsService $insights): JsonResponse
    {
        return response()->json(['customers' => $insights->customers()]);
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) return null;
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, self::TZ);
        } catch (\Throwable) {
            return null;
        }

        return $date !== false && $date->toDateString() === $value ? $date : null;
    }
}
