<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleAndPermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Atomic system permissions.
        $permissions = [
            // Duty matrix
            'matrix.view',
            'matrix.assign',          // create/edit assignments
            'matrix.approve',         // approve draft -> approved
            'matrix.delete',
            // Event types
            'event_types.view',
            'event_types.manage',
            // Users
            'users.view',
            'users.create',
            'users.update',
            'users.delete',
            // Roles and permissions (Admin only)
            'roles.view',
            'roles.manage',
            // Reports and export
            'reports.view',
            'reports.export',
            // System pages (Admin only)
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
