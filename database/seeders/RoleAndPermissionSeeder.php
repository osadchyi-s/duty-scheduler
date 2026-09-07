<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleAndPermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Атомарні права системи.
        $permissions = [
            // Матриця чергувань
            'matrix.view',
            'matrix.assign',          // створювати/редагувати призначення
            'matrix.approve',         // затверджувати draft -> approved
            'matrix.delete',
            // Типи подій
            'event_types.view',
            'event_types.manage',
            // Користувачі
            'users.view',
            'users.create',
            'users.update',
            'users.delete',
            // Ролі та права (виключно Admin)
            'roles.view',
            'roles.manage',
            // Звіти та експорт
            'reports.view',
            'reports.export',
            // Системні сторінки (виключно Admin)
            'system.logs',
            'system.settings',
            'system.queues',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $admin = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $admin->syncPermissions(Permission::all());

        $manager = Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web']);
        $manager->syncPermissions([
            'matrix.view', 'matrix.assign', 'matrix.approve', 'matrix.delete',
            'event_types.view', 'event_types.manage',
            'users.view', 'users.create', 'users.update',
            'reports.view', 'reports.export',
        ]);

        $user = Role::firstOrCreate(['name' => 'User', 'guard_name' => 'web']);
        $user->syncPermissions([
            'matrix.view',
        ]);
    }
}
