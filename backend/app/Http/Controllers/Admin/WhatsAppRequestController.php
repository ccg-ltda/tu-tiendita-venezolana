<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminAuditService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Módulo de WhatsApp del panel: solicitudes (mayorista, soporte, ayuda para comprar) que el equipo
 * registra, asigna y atiende, más el conteo de clics del menú de WhatsApp de la tienda.
 */
class WhatsAppRequestController extends Controller
{
    public const TOPICS = ['mayorista', 'soporte', 'ayuda_compra'];
    private const STATUSES = ['NEW', 'ATTENDING', 'RESOLVED'];

    public function index(): JsonResponse
    {
        $requests = DB::connection('mysql')->table('whatsapp_requests as w')
            ->leftJoin('admins as a', 'a.id', '=', 'w.assigned_admin_id')
            ->orderByRaw("CASE w.status WHEN 'NEW' THEN 0 WHEN 'ATTENDING' THEN 1 ELSE 2 END")
            ->orderByDesc('w.id')->limit(300)
            ->get(['w.id', 'w.name', 'w.phone', 'w.topic', 'w.message', 'w.status', 'w.assigned_admin_id', 'a.name as assigned_name', 'w.created_at', 'w.updated_at'])
            ->map(static fn ($r): array => [
                'id' => (int) $r->id, 'name' => $r->name, 'phone' => $r->phone, 'topic' => $r->topic, 'message' => $r->message,
                'status' => $r->status, 'assigned_admin_id' => $r->assigned_admin_id !== null ? (int) $r->assigned_admin_id : null,
                'assigned_name' => $r->assigned_name, 'created_at' => self::iso($r->created_at), 'updated_at' => self::iso($r->updated_at),
            ])->all();

        $since = CarbonImmutable::now('UTC')->subDays(30)->format('Y-m-d H:i:s.v');
        $clicks = array_fill_keys(self::TOPICS, 0);
        foreach (DB::connection('mysql')->table('whatsapp_clicks')->where('created_at', '>=', $since)->selectRaw('topic, COUNT(*) as n')->groupBy('topic')->get() as $row) {
            if (array_key_exists($row->topic, $clicks)) $clicks[$row->topic] = (int) $row->n;
        }

        $admins = DB::connection('mysql')->table('admins')->where('active', true)->orderBy('name')->get(['id', 'name'])
            ->map(static fn ($a): array => ['id' => (int) $a->id, 'name' => $a->name])->all();

        return response()->json(['requests' => $requests, 'clicks_30_days' => $clicks, 'admins' => $admins]);
    }

    public function store(Request $request, AdminAuditService $audit): JsonResponse
    {
        $name = $this->text($request->input('name'), 120);
        $phone = preg_replace('/[^\d+]/', '', (string) $request->input('phone', ''));
        $topic = $request->input('topic');
        $message = $this->text($request->input('message'), 1000);
        if ($name === '' || strlen((string) $phone) < 7 || strlen((string) $phone) > 20 || ! in_array($topic, self::TOPICS, true)) {
            return response()->json(['message' => 'Escribe nombre, teléfono válido y tema (mayorista, soporte o ayuda para comprar).'], 422);
        }

        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.v');
        $adminId = (int) $request->session()->get('admin_id');
        $id = DB::connection('mysql')->table('whatsapp_requests')->insertGetId([
            'name' => $name, 'phone' => $phone, 'topic' => $topic, 'message' => $message !== '' ? $message : null,
            'status' => 'NEW', 'created_by_admin_id' => $adminId ?: null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $audit->record($request, 'CREATE', 'WHATSAPP_REQUEST', $id, [], ['name' => $name, 'topic' => $topic], $name);

        return response()->json(['request' => ['id' => (int) $id]], 201);
    }

    public function update(Request $request, string $whatsappRequest, AdminAuditService $audit): JsonResponse
    {
        if (! ctype_digit($whatsappRequest) || (int) $whatsappRequest < 1) abort(404);
        $id = (int) $whatsappRequest;
        $current = DB::connection('mysql')->table('whatsapp_requests')->where('id', $id)->first();
        if ($current === null) abort(404);

        $changes = [];
        if ($request->has('status')) {
            $status = $request->input('status');
            if (! in_array($status, self::STATUSES, true)) return response()->json(['message' => 'Estado inválido.'], 422);
            $changes['status'] = $status;
        }
        if ($request->has('assigned_admin_id')) {
            $assigned = $request->input('assigned_admin_id');
            if ($assigned === null || $assigned === '') {
                $changes['assigned_admin_id'] = null;
            } else {
                if (! is_numeric($assigned) || ! DB::connection('mysql')->table('admins')->where('id', (int) $assigned)->where('active', true)->exists()) {
                    return response()->json(['message' => 'Administrador inválido.'], 422);
                }
                $changes['assigned_admin_id'] = (int) $assigned;
                // Asignar una solicitud nueva la pasa a "en atención".
                if ($current->status === 'NEW' && ! isset($changes['status'])) $changes['status'] = 'ATTENDING';
            }
        }
        if ($changes === []) return response()->json(['message' => 'No hay cambios.'], 422);

        $changes['updated_at'] = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.v');
        DB::connection('mysql')->table('whatsapp_requests')->where('id', $id)->update($changes);
        $audit->record($request, 'UPDATE', 'WHATSAPP_REQUEST', $id,
            ['status' => $current->status, 'assigned_admin_id' => $current->assigned_admin_id],
            array_intersect_key($changes, ['status' => 1, 'assigned_admin_id' => 1]), (string) $current->name);

        return response()->json(['request' => ['id' => $id] + $changes]);
    }

    private function text(mixed $value, int $max): string
    {
        return is_string($value) ? mb_substr(trim(strip_tags($value)), 0, $max) : '';
    }

    private static function iso(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value, 'UTC')->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}
