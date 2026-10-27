<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * php artisan admin:create
 * The first admin account on a server, where the development accounts are never created.
 * Reads ADMIN_EMAIL, ADMIN_PASSWORD and ADMIN_PHONE from the environment (Railway variables),
 * so it can run on every deploy: an existing account is left alone, and the password is never
 * printed or logged.
 */
class CreateAdmin extends Command
{
    protected $signature = 'admin:create';

    protected $description = 'Create the admin account from ADMIN_EMAIL / ADMIN_PASSWORD / ADMIN_PHONE (skipped if it exists)';

    public function handle(): int
    {
        $email = trim($this->variable('ADMIN_EMAIL'));
        $password = $this->variable('ADMIN_PASSWORD');
        $phone = trim($this->variable('ADMIN_PHONE'));

        if ($email === '' || $password === '' || $phone === '') {
            $this->warn('ADMIN_EMAIL, ADMIN_PASSWORD and ADMIN_PHONE are not all set: no admin created.');

            return self::SUCCESS; // not an error: a deploy without them still starts
        }

        if (User::where('email', $email)->exists()) {
            $this->info("The admin {$email} already exists: nothing changed.");

            return self::SUCCESS;
        }

        if (strlen($password) < 8) {
            $this->error('ADMIN_PASSWORD must be at least 8 characters: no admin created.');

            return self::FAILURE;
        }

        $admin = new User([
            'first_name' => $this->variable('ADMIN_FIRST_NAME') ?: 'Papaya',
            'last_name' => $this->variable('ADMIN_LAST_NAME') ?: 'Admin',
            'email' => $email,
            'phone' => $phone,
            'password' => $password, // hashed by the model's cast
        ]);
        $admin->forceFill(['role' => UserRole::Admin, 'email_verified_at' => now()])->save();

        $this->info("Admin {$email} created.");

        return self::SUCCESS;
    }

    /**
     * Straight from the server's environment, not env(): on Railway the config is cached at build
     * time (php artisan config:cache), and after that env() outside config files returns null.
     */
    private function variable(string $key): string
    {
        $value = getenv($key);

        return (string) ($value !== false ? $value : ($_ENV[$key] ?? $_SERVER[$key] ?? ''));
    }
}
