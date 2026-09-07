<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class AdminAuthController extends Controller
{
    // Autentica únicamente usuarios con rol de administrador.
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $authenticated = Auth::attempt([
            'email' => strtolower(trim($credentials['email'])),
            'password' => $credentials['password'],
            'role' => 'admin',
        ]);

        if (! $authenticated) {
            return response()->json([
                'message' => 'Credenciales incorrectas.',
            ], 401);
        }

        // Regenera el identificador de sesión para prevenir fijación de sesión.
        $request->session()->regenerate();

        $user = Auth::user();

        return response()->json([
            'message' => 'Inicio de sesión exitoso.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
        ]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        Password::sendResetLink([
            'email' => strtolower(trim($validated['email'])),
            'role' => 'admin',
        ]);

        return response()->json([
            'message' => 'Si existe una cuenta administrativa asociada a ese correo, recibirás un enlace para restablecer tu contraseña.',
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', 'min:12'],
        ]);

        $status = Password::reset([
            'email' => strtolower(trim($validated['email'])),
            'token' => $validated['token'],
            'password' => $validated['password'],
            'password_confirmation' => $request->input('password_confirmation'),
            'role' => 'admin',
        ], function ($user, string $password): void {
            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
            ])->save();

            DB::table((string) config('session.table', 'sessions'))
                ->where('user_id', $user->getKey())
                ->delete();

            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'El enlace de recuperación no es válido o ha expirado.',
            ], 422);
        }

        return response()->json([
            'message' => 'Contraseña actualizada correctamente.',
        ]);
    }

    // Devuelve la identidad del administrador autenticado.
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
        ]);
    }

    // Cierra la sesión actual y elimina sus credenciales de sesión.
    public function logout(Request $request): JsonResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'Sesión cerrada correctamente.',
        ]);
    }
}
