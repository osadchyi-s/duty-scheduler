<?php

use App\Models\User;
use Spatie\Permission\Models\Role;

it('creates an administrator and the roles on a fresh installation', function () {
    $this->artisan('wfm:create-admin', ['--name' => 'Адмін', '--email' => 'admin@example.com', '--password' => 'secret-password'])
        ->assertSuccessful();

    $admin = User::where('email', 'admin@example.com')->sole();

    expect($admin->hasRole('Admin'))->toBeTrue()
        ->and($admin->is_active)->toBeTrue()
        ->and(Role::whereIn('name', ['Admin', 'Manager', 'User'])->count())->toBe(3);
});

it('keeps customized role permissions on an existing installation', function () {
    $this->artisan('wfm:create-admin', ['--name' => 'Перший', '--email' => 'first@example.com', '--password' => 'secret-password']);
    Role::findByName('Manager')->syncPermissions([]);

    $this->artisan('wfm:create-admin', ['--name' => 'Другий', '--email' => 'second@example.com', '--password' => 'secret-password'])
        ->assertSuccessful();

    expect(Role::findByName('Manager')->permissions)->toBeEmpty();
});

it('rejects a short password and a duplicate email', function () {
    $this->artisan('wfm:create-admin', ['--name' => 'Адмін', '--email' => 'admin@example.com', '--password' => 'short'])
        ->assertFailed();
    $this->artisan('wfm:create-admin', ['--name' => 'Адмін', '--email' => 'admin@example.com', '--password' => 'secret-password']);
    $this->artisan('wfm:create-admin', ['--name' => 'Ще один', '--email' => 'admin@example.com', '--password' => 'secret-password'])
        ->assertFailed();

    expect(User::count())->toBe(1);
});
