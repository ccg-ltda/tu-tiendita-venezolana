<?php

namespace App\Console\Commands;

use App\Admin\AdminAccountNormalizer;
use App\Repositories\MySqlAdminRepository;
use App\Services\PersistenceException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

final class CreateAdminAccount extends Command
{
    protected $signature = 'admin:create';

    protected $description = 'Creates an initial administrative account securely.';

    public function handle(MySqlAdminRepository $accounts): int
    {
        $name = $this->ask('Nombre');
        $username = $this->ask('Usuario');
        $password = $this->secret('Contraseña');
        $confirmation = $this->secret('Confirmación de contraseña');

        if (! is_string($name) || trim($name) === '') {
            $this->error('El nombre es obligatorio.');
            return self::FAILURE;
        }

        if (! is_string($username) || trim($username) === '') {
            $this->error('El usuario es obligatorio.');
            return self::FAILURE;
        }

        $username = AdminAccountNormalizer::normalizeUsername($username);
        if ($username === null) {
            $this->error('El usuario debe tener entre 3 y 40 caracteres y solo puede incluir letras, números, punto, guion bajo o guion.');
            return self::FAILURE;
        }

        if (! is_string($password) || $password === '') {
            $this->error('La contraseña es obligatoria.');
            return self::FAILURE;
        }

        if (mb_strlen($password) < 10) {
            $this->error('La contraseña debe tener al menos 10 caracteres.');
            return self::FAILURE;
        }

        if (! is_string($confirmation) || ! hash_equals($password, $confirmation)) {
            $this->error('La confirmación de contraseña no coincide.');
            return self::FAILURE;
        }

        try {
            $account = $accounts->create([
                'name' => trim($name),
                'username' => $username,
                'password_hash' => Hash::make($password),
                'role' => 'ADMIN',
                'active' => true,
                'password_changed_at' => now('UTC')->format('Y-m-d\\TH:i:s.v\\Z'),
            ]);
        } catch (PersistenceException $exception) {
            if ($exception->remoteCode() === 'DUPLICATE_ADMIN_USERNAME') {
                $this->error('Ya existe una cuenta administrativa con este usuario.');
            } else {
                Log::warning('Administrative account provisioning failed.', [
                    'reason' => $exception->remoteCode(),
                    'status' => $exception->status(),
                ]);
                $this->error('No fue posible crear la cuenta administrativa. Intenta nuevamente.');
            }

            return self::FAILURE;
        } catch (\Throwable $exception) {
            Log::error('Administrative account provisioning failed unexpectedly.', [
                'exception' => $exception::class,
            ]);
            $this->error('No fue posible crear la cuenta administrativa. Intenta nuevamente.');

            return self::FAILURE;
        }

        $this->info('Administrador creado correctamente: '.$account['username']);

        return self::SUCCESS;
    }
}
