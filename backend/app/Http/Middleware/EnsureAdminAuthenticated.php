<?php

namespace App\Http\Middleware;

use App\Repositories\MySqlAdminRepository;
use App\Services\PersistenceException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureAdminAuthenticated
{
    public function __construct(private readonly MySqlAdminRepository $accounts) {}

    public function handle(Request $request, Closure $next): Response
    {
        $adminId = $request->session()->get('admin_id');
        if ($request->session()->get('admin_authenticated') !== true || (! is_int($adminId) && (! is_string($adminId) || ! ctype_digit($adminId)))) {
            return $this->unauthenticated();
        }

        try {
            $admin = $this->accounts->findByAdminId((int) $adminId);
        } catch (PersistenceException) {
            return response()->json(['message' => 'El servicio de autenticación no está disponible.'], 503);
        } catch (\Throwable) {
            return response()->json(['message' => 'El servicio de autenticación no está disponible.'], 503);
        }

        if ($admin === null || ! $admin['active'] || $admin['role'] !== 'ADMIN') {
            return $this->invalidateSession($request);
        }

        $sessionRevision = $request->session()->get('admin_revision');
        if ((! is_int($sessionRevision) && (! is_string($sessionRevision) || ! ctype_digit($sessionRevision)))
            || (int) $sessionRevision !== (int) $admin['revision']) {
            return $this->invalidateSession($request);
        }

        // This value comes only from the just-completed MySQL account validation.
        // Downstream handlers may reuse it during this request without a second read.
        $request->attributes->set('admin_validated_account', $admin);

        return $next($request);
    }

    private function unauthenticated(): Response
    {
        return response()->json(['message' => 'No autenticado.'], 401);
    }

    private function invalidateSession(Request $request): Response
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->unauthenticated();
    }
}
