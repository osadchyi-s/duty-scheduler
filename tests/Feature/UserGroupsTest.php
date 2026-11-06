<?php

use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\EmployeeGroup;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
    $admin = User::create(['name' => 'Адмін', 'email' => 'admin@test.local', 'password' => bcrypt('secret'), 'is_active' => true]);
    $admin->assignRole('Admin');

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($admin);
});

it('adds and removes the employee from groups on the user edit page', function () {
    $employee = User::create(['name' => 'Працівник', 'email' => 'employee@test.local', 'password' => bcrypt('secret'), 'is_active' => true]);
    Role::firstOrCreate(['name' => 'User', 'guard_name' => 'web']);
    $employee->assignRole('User');
    $kept = EmployeeGroup::create(['name' => 'Чергові']);
    $removed = EmployeeGroup::create(['name' => 'Резерв']);
    $added = EmployeeGroup::create(['name' => 'Нічні']);
    $employee->employeeGroups()->attach([$kept->id, $removed->id]);

    Livewire::test(EditUser::class, ['record' => $employee->getRouteKey()])
        ->assertFormSet(['employeeGroups' => [$kept->id, $removed->id]])
        ->fillForm(['employeeGroups' => [$kept->id, $added->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($employee->employeeGroups()->pluck('employee_groups.id')->sort()->values()->all())->toBe([$kept->id, $added->id]);
});
