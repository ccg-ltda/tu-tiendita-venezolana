<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminAuthController extends Controller
{
    // Autentica únicamente usuarios con rol de administrador.
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $admin = $this->adminIdentity();
        if ($admin === null) {
            return response()->json([
                'message' => 'El servicio de autenticación no está disponible.',
            ], 503);
        }

        $email = strtolower(trim($credentials['email']));

        try {
            $passwordMatches = Hash::check($credentials['password'], $admin['password_hash']);
        } catch (\Throwable) {
            return response()->json([
                'message' => 'El servicio de autenticación no está disponible.',
            ], 503);
        }

        if (! hash_equals($admin['email'], $email) || ! $passwordMatches) {
            return response()->json([
                'message' => 'Credenciales incorrectas.',
            ], 401);
        }

        // Regenera el identificador de sesión para prevenir fijación de sesión.
        $request->session()->regenerate();

        $request->session()->put('admin_authenticated', true);

        return response()->json([
            'message' => 'Inicio de sesión exitoso.',
            'user' => $this->publicAdmin($admin),
        ]);
    }

    // Devuelve la identidad del administrador autenticado.
    public function me(Request $request): JsonResponse
    {
        $admin = $this->adminIdentity();
        if ($admin === null) {
            return response()->json([
                'message' => 'El servicio de autenticación no está disponible.',
            ], 503);
        }

        return response()->json([
            'user' => $this->publicAdmin($admin),
        ]);
    }

    // Cierra la sesión actual y elimina sus credenciales de sesión.
    public function logout(Request $request): JsonResponse
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'Sesión cerrada correctamente.',
        ]);
    }

    /** @return array{name: string, email: string, password_hash: string}|null */
    private function adminIdentity(): ?array
    {
        $name = config('admin.name');
        $email = config('admin.email');
        $passwordHash = config('admin.password_hash');

        if (! is_string($name) || trim($name) === '' || ! is_string($email) || ! is_string($passwordHash)) {
            return null;
        }

        $normalizedEmail = strtolower(trim($email));
        $passwordHash = trim($passwordHash);
        if (! filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)
            || $passwordHash === ''
            || (Hash::info($passwordHash)['algoName'] ?? 'unknown') === 'unknown'
        ) {
            return null;
        }

        return [
            'name' => trim($name),
            'email' => $normalizedEmail,
            'password_hash' => $passwordHash,
        ];
    }

    /** @param array{name: string, email: string, password_hash: string} $admin */
    private function publicAdmin(array $admin): array
    {
        return [
            'id' => 1,
            'name' => $admin['name'],
            'email' => $admin['email'],
            'role' => 'admin',
        ];
    }
}
