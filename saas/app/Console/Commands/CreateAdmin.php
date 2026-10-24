<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class CreateAdmin extends Command
{
    protected $signature = 'gama:admin-create {email} {--name=Admin}';

    protected $description = 'Create a dashboard admin, or set a new password for an existing one';

    public const MIN_PASSWORD_LENGTH = 12;

    public function handle(): int
    {
        $email = strtolower((string) $this->argument('email'));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('The email address is not valid.');

            return self::FAILURE;
        }

        // Asked for here, never taken as an argument, so it stays out of the shell history.
        $password = (string) $this->secret('Password (at least '.self::MIN_PASSWORD_LENGTH.' characters)');
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $this->error('The password is too short.');

            return self::FAILURE;
        }
        if ($password !== (string) $this->secret('Type it again')) {
            $this->error('The passwords do not match.');

            return self::FAILURE;
        }

        $user = User::firstOrNew(['email' => $email]);
        $exists = $user->exists;
        $user->name = $exists ? $user->name : (string) $this->option('name');
        $user->password = $password;
        $user->role = User::ROLE_ADMIN;
        $user->save();

        $this->info($exists ? "Admin {$email} updated." : "Admin {$email} created.");

        return self::SUCCESS;
    }
}
