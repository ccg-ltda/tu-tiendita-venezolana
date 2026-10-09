<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CouponRequest;
use App\Services\AdminAuditService;
use App\Services\CouponAdminService;
use App\Services\PersistenceException;
use Illuminate\Http\JsonResponse;

final class CouponController extends Controller
{
    public function index(CouponAdminService $service): JsonResponse { return $this->call(fn () => ['coupons' => $service->list()]); }
    public function show(int $couponId, CouponAdminService $service): JsonResponse { return $this->call(fn () => $service->show($couponId)); }
    public function store(CouponRequest $request, CouponAdminService $service, AdminAuditService $audit): JsonResponse
    {
        return $this->call(function () use ($request, $service, $audit) { $saved = $service->create($request->validated()); $audit->record($request, 'CREATE', 'COUPON', $saved['coupon_id'], [], $saved); return $saved; }, 201);
    }
    public function update(int $couponId, CouponRequest $request, CouponAdminService $service, AdminAuditService $audit): JsonResponse
    {
        return $this->call(function () use ($couponId, $request, $service, $audit) {
            $before = $service->show($couponId); $saved = $service->update($couponId, $request->validated());
            $action = $before['active'] === $saved['active'] ? 'UPDATE' : ($saved['active'] ? 'ACTIVATE' : 'DEACTIVATE');
            $audit->record($request, $action, 'COUPON', $couponId, $before, $saved); return $saved;
        });
    }
    private function call(callable $action, int $status = 200): JsonResponse { try { return response()->json($action(), $status); } catch (PersistenceException $e) { return response()->json(['message' => $e->getMessage()], $e->status()); } }
}
