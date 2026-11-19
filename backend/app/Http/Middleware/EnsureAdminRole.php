<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restringe una ruta a ciertos roles del panel (ADMIN, DESPACHO, ENTREGA).
 * Debe ir después de EnsureAdminAuthenticated, que deja la cuenta validada en la petición.
 * Uso: ->middleware(EnsureAdminRole::class.':ADMIN,DESPACHO')
 */
final class EnsureAdminRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $admin = $request->attributes->get('admin_validated_account');
        $role = is_array($admin) ? ($admin['role'] ?? null) : null;

        if (! is_string($role) || ! in_array($role, $roles, true)) {
            return response()->json(['message' => 'No tienes permiso para esta acción.'], 403);
        }

        return $next($request);
    }
}
