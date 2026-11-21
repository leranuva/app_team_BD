<?php

namespace App\Console\Commands;

use App\Actions\ProvisionWorker;
use App\Enums\LogisticsRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateWorker extends Command
{
    protected $signature = 'logistics:create-worker';

    protected $description = 'Crea una cuenta interna y le asigna un rol logístico';

    public function handle(ProvisionWorker $provisionWorker): int
    {
        $role = LogisticsRole::from($this->choice('Rol', array_column(LogisticsRole::cases(), 'value')));
        $validator = Validator::make([
            'name' => $this->ask('Nombre'),
            'email' => strtolower(trim((string) $this->ask('Correo'))),
            'password' => $this->secret('Contraseña'),
            'password_confirmation' => $this->secret('Repite la contraseña'),
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $provisionWorker->execute($validator->validated(), $role);
        $this->info('Cuenta creada.');

        return self::SUCCESS;
    }
}
