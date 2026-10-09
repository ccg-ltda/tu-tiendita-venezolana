<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Repositories\MySqlAdminRepository;
use App\Services\AdminActivityReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class AdminActivityController extends Controller
{
    public function administrators(MySqlAdminRepository $accounts): JsonResponse
    {
        return response()->json(['administrators' => $accounts->activityFilterOptions()]);
    }

    public function index(Request $request, AdminActivityReadService $activity): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1'],
            'before_id' => ['nullable', 'integer', 'min:1'],
            'admin_id' => ['nullable', 'integer', 'min:1'],
            'resource_type' => ['nullable', Rule::in(['PRODUCT', 'PROMOTION', 'COUPON', 'ORDER', 'CATEGORY', 'SUBCATEGORY', 'ADMIN', 'AUTH'])],
            'action' => ['nullable', Rule::in(['CREATE', 'UPDATE', 'STATUS_CHANGE', 'ACTIVATE', 'DEACTIVATE', 'LOGIN', 'LOGOUT'])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'include_auth' => ['nullable', 'boolean'],
        ]);

        try {
            return response()->json($activity->page(
                min((int) ($validated['limit'] ?? 50), 100),
                isset($validated['before_id']) ? (int) $validated['before_id'] : null,
                array_filter([
                    'admin_id' => isset($validated['admin_id']) ? (int) $validated['admin_id'] : null,
                    'resource_type' => $validated['resource_type'] ?? null,
                    'action' => $validated['action'] ?? null,
                    'date_from' => $validated['date_from'] ?? null,
                    'date_to' => $validated['date_to'] ?? null,
                ], static fn (mixed $value): bool => $value !== null) + [
                    'include_auth' => filter_var($validated['include_auth'] ?? false, FILTER_VALIDATE_BOOLEAN),
                ],
            ));
        } catch (\Throwable) { return response()->json(['message' => 'No fue posible consultar el historial de actividad.'], 503); }
    }
}
