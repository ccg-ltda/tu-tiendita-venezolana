<?php

namespace App\Http\Controllers\Admin;

use App\Admin\AdminAccountNormalizer;
use App\Http\Controllers\Controller;
use App\Repositories\MySqlAdminRepository;
use App\Services\AdminAuditService;
use App\Services\PersistenceException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/** Usuarios del panel (ADMIN, DESPACHO, ENTREGA). Solo un ADMIN entra aquí; nunca se devuelve ni se registra una contraseña. */
class UserController extends Controller
{
    public function index(MySqlAdminRepository $accounts): JsonResponse
    {
        return response()->json(['users' => array_map(fn (array $a): array => $this->view($a), $accounts->all())]);
    }

    public function store(Request $request, MySqlAdminRepository $accounts, AdminAuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'username' => ['required', 'string', 'max:40'],
            'role' => ['required', Rule::in(AdminAccountNormalizer::ROLES)],
            'password' => ['required', 'string', 'min:10', 'max:200'],
        ], [], ['name' => 'nombre', 'username' => 'usuario', 'password' => 'contraseña']);

        $username = AdminAccountNormalizer::normalizeUsername($data['username']);
        if ($username === null) {
            return response()->json(['message' => 'El usuario debe tener entre 3 y 40 caracteres: letras, números, punto, guion o guion bajo.'], 422);
        }
        try {
            $created = $accounts->create([
                'name' => trim($data['name']), 'username' => $username, 'role' => $data['role'], 'active' => true,
                'password_hash' => Hash::make($data['password']), 'password_changed_at' => now('UTC')->format('Y-m-d\TH:i:s.v\Z'),
            ]);
        } catch (PersistenceException $exception) {
            return response()->json(['message' => $exception->remoteCode() === 'DUPLICATE_ADMIN_USERNAME' ? 'Ya existe un usuario con ese nombre de usuario.' : 'No fue posible crear el usuario.'], $exception->status());
        }
        $view = $this->view($created);
        $audit->record($request, 'CREATE', 'ADMIN', $created['admin_id'], [], $view);

        return response()->json(['user' => $view], 201);
    }

    public function update(Request $request, string $user, MySqlAdminRepository $accounts, AdminAuditService $audit): JsonResponse
    {
        if (! ctype_digit($user) || (int) $user < 1) abort(404);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'role' => ['sometimes', Rule::in(AdminAccountNormalizer::ROLES)],
            'active' => ['sometimes', 'boolean'],
            'password' => ['sometimes', 'nullable', 'string', 'min:10', 'max:200'],
        ], [], ['name' => 'nombre', 'password' => 'contraseña']);

        $id = (int) $user;
        $current = $accounts->findByAdminId($id);
        if ($current === null) abort(404);
        $myId = (int) $request->session()->get('admin_id');
        $role = $data['role'] ?? $current['role'];
        $active = array_key_exists('active', $data) ? (bool) $data['active'] : $current['active'];

        // No quedarse sin administradores ni bloquearse a sí mismo.
        if ($id === $myId && ($role !== 'ADMIN' || ! $active)) {
            return response()->json(['message' => 'No puedes quitarte el rol de administrador ni desactivar tu propia cuenta.'], 422);
        }
        $wasAdmin = $current['role'] === 'ADMIN' && $current['active'];
        $stillAdmin = $role === 'ADMIN' && $active;
        if ($wasAdmin && ! $stillAdmin && count(array_filter($accounts->all(), static fn (array $a): bool => $a['role'] === 'ADMIN' && $a['active'])) <= 1) {
            return response()->json(['message' => 'Debe quedar al menos un administrador activo.'], 422);
        }

        $changePassword = isset($data['password']) && $data['password'] !== '';
        try {
            $updated = $accounts->update($id, $current['revision'], [
                'name' => isset($data['name']) ? trim($data['name']) : $current['name'], 'username' => $current['username'], 'role' => $role, 'active' => $active,
                'password_hash' => $changePassword ? Hash::make($data['password']) : $current['password_hash'],
                'password_changed_at' => $changePassword ? now('UTC')->format('Y-m-d H:i:s.v') : ($current['password_changed_at'] === null ? null : \Illuminate\Support\Carbon::parse($current['password_changed_at'])->utc()->format('Y-m-d H:i:s.v')),
            ]);
        } catch (PersistenceException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->status());
        }
        $audit->record($request, 'UPDATE', 'ADMIN', $id, $this->view($current), $this->view($updated) + ['password_changed' => $changePassword]);

        return response()->json(['user' => $this->view($updated)]);
    }

    /** @param array<string,mixed> $a @return array<string,mixed> */
    private function view(array $a): array
    {
        return ['id' => $a['admin_id'], 'name' => $a['name'], 'username' => $a['username'], 'role' => $a['role'], 'active' => $a['active']];
    }
}
