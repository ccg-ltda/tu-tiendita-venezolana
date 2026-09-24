<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminAuthenticated
{
    // Permite continuar únicamente a administradores autenticados.
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->session()->get('admin_authenticated') !== true) {
            return response()->json([
                'message' => 'No autenticado.',
            ], 401);
        }

        return $next($request);
    }
}
