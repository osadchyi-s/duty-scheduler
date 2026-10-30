<?php

namespace App\Policies;

use App\Models\DutyLimit;
use App\Models\User;

class DutyLimitPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(['Admin', 'Manager']);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(['Admin', 'Manager']);
    }

    public function update(User $user, DutyLimit $dutyLimit): bool
    {
        return $user->hasRole(['Admin', 'Manager']);
    }

    public function delete(User $user, DutyLimit $dutyLimit): bool
    {
        return $user->hasRole(['Admin', 'Manager']);
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasRole(['Admin', 'Manager']);
    }
}
