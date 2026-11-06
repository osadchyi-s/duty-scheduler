<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use App\Models\Role;

/**
 * Creates the first administrator of a fresh installation (production/staging),
 * where the demo UserSeeder with its well-known passwords must not be used.
 */
class CreateAdminUser extends Command
{
    protected $signature = 'wfm:create-admin
        {--name= : Full name}
        {--email= : Login email}
        {--password= : Password; asked interactively when omitted}';

    protected $description = 'Create an administrator account (on a fresh installation, also roles and permissions)';

    public function handle(): int
    {
        // Only on a fresh installation: the seeder syncs role permissions and would
        // reset permissions customized on the roles page.
        if (! Role::query()->where('name', 'Admin')->exists()) {
            $this->callSilently('db:seed', ['--class' => RoleAndPermissionSeeder::class, '--force' => true]);
        }

        $data = [
            'name' => $this->option('name') ?: $this->ask('Full name'),
            'email' => $this->option('email') ?: $this->ask('Email'),
            'password' => $this->option('password') ?: $this->secret('Password (at least 8 characters)'),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create($data + ['is_active' => true]);
        $user->assignRole('Admin');

        $this->info("Administrator {$user->email} created.");

        return self::SUCCESS;
    }
}
