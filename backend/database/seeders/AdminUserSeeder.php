<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    // Crea de forma segura el administrador inicial del sistema.
    public function run(): void
    {
        $name = trim((string) config('admin.name'));
        $email = strtolower(trim((string) config('admin.email')));
        $password = (string) config('admin.password');

        if ($name === '' || $email === '' || $password === '') {
            throw new RuntimeException(
                'Faltan ADMIN_NAME, ADMIN_EMAIL o ADMIN_PASSWORD en la configuración.'
            );
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException(
                'ADMIN_EMAIL no contiene un correo electrónico válido.'
            );
        }

        if (strlen($password) < 12) {
            throw new RuntimeException(
                'ADMIN_PASSWORD debe tener mínimo 12 caracteres.'
            );
        }

        $existingUser = User::where('email', $email)->first();

        // Evita convertir automáticamente un usuario común en administrador.
        if ($existingUser && $existingUser->role !== 'admin') {
            throw new RuntimeException(
                'Ya existe un usuario con ese correo y no es administrador.'
            );
        }

        if ($existingUser) {
            $this->command?->warn(
                'El administrador ya existe. No se realizaron cambios.'
            );

            return;
        }

        $user = new User();
        $user->name = $name;
        $user->email = $email;

        // La contraseña se almacena únicamente como hash.
        $user->password = Hash::make($password);

        // El rol administrativo se asigna exclusivamente desde el backend.
        $user->role = 'admin';

        $user->email_verified_at = now();
        $user->save();

        $this->command?->info(
            'Administrador creado correctamente.'
        );
    }
}