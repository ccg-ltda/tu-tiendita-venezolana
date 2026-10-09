<?php

namespace App\Http\Controllers\Auth;

use App\Admin\AdminAccountNormalizer;
use App\Http\Controllers\Controller;
use App\Repositories\MySqlAdminRepository;
use App\Services\AdminAuditService;
use App\Services\PersistenceException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminAuthController extends Controller
{
    public function login(Request $request, MySqlAdminRepository $accounts, AdminAuditService $audit): JsonResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $username = AdminAccountNormalizer::normalizeUsername($credentials['username']);
        if ($username === null) return $this->invalidCredentials();

        try {
            $admin = $accounts->findByUsername($username);
            $passwordMatches = $admin !== null && $accounts->passwordMatches($admin, $credentials['password']);
        } catch (PersistenceException) {
            return $this->authenticationUnavailable();
        } catch (\Throwable) {
            return $this->authenticationUnavailable();
        }

        if ($admin === null || ! $admin['active'] || $admin['role'] !== 'ADMIN' || ! $passwordMatches) {
            return $this->invalidCredentials();
        }

        $request->session()->regenerate();
        $request->session()->put([
            'admin_authenticated' => true,
            'admin_id' => $admin['admin_id'],
            'admin_name' => $admin['name'],
            'admin_username' => $admin['username'],
            'admin_role' => $admin['role'],
            'admin_revision' => $admin['revision'],
        ]);
        $audit->record($request, 'LOGIN', 'AUTH', $admin['admin_id']);

        return response()->json([
            'message' => 'Inicio de sesión exitoso.',
            'user' => $this->publicAdmin($admin),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $admin = $request->attributes->get('admin_validated_account');
        if (! is_array($admin)) return response()->json(['message' => 'No autenticado.'], 401);

        return response()->json(['user' => $this->publicAdmin($admin)]);
    }

    public function logout(Request $request, AdminAuditService $audit): JsonResponse
    {
        $adminId = $request->session()->get('admin_id');
        if (is_int($adminId) || (is_string($adminId) && ctype_digit($adminId))) $audit->record($request, 'LOGOUT', 'AUTH', (int) $adminId);
        $this->invalidateSession($request);

        return response()->json(['message' => 'Sesión cerrada correctamente.']);
    }

    /** @param array{admin_id:int,name:string,username:string,role:'ADMIN'} $admin */
    private function publicAdmin(array $admin): array
    {
        return [
            'id' => $admin['admin_id'],
            'name' => $admin['name'],
            'username' => $admin['username'],
            'role' => $admin['role'],
        ];
    }

    private function invalidCredentials(): JsonResponse
    {
        return response()->json(['message' => 'Credenciales incorrectas.'], 401);
    }

    private function authenticationUnavailable(): JsonResponse
    {
        return response()->json(['message' => 'El servicio de autenticación no está disponible.'], 503);
    }

    private function invalidateSession(Request $request): void
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
