<?php

namespace App\Policies;

use App\Models\EmployeeGroup;
use App\Models\User;

class EmployeeGroupPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(['Admin', 'Manager']);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(['Admin', 'Manager']);
    }

    public function update(User $user, EmployeeGroup $employeeGroup): bool
    {
        return $user->hasRole(['Admin', 'Manager']);
    }

    /**
     * A group used by a duty limit cannot be deleted: a limit left without
     * groups would silently start to apply to all employees.
     */
    public function delete(User $user, EmployeeGroup $employeeGroup): bool
    {
        return $user->hasRole(['Admin', 'Manager']) && ! $employeeGroup->dutyLimits()->exists();
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasRole(['Admin', 'Manager']);
    }
}
