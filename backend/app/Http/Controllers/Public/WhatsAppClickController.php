<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Admin\WhatsAppRequestController;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** POST /api/whatsapp-clicks {topic}: cuenta un clic del menú de WhatsApp. No guarda datos personales. */
class WhatsAppClickController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $topic = $request->input('topic');
        if (! in_array($topic, WhatsAppRequestController::TOPICS, true)) {
            return response()->json(['message' => 'Tema inválido.'], 422);
        }
        DB::connection('mysql')->table('whatsapp_clicks')->insert(['topic' => $topic, 'created_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.v')]);

        return response()->json(['ok' => true], 201);
    }
}
