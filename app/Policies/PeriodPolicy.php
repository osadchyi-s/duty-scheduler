<?php

namespace App\Policies;

use App\Models\Period;
use App\Models\User;

class PeriodPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(['Admin', 'Manager']);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(['Admin', 'Manager']);
    }

    /**
     * Built-in calendar periods are read-only.
     */
    public function update(User $user, Period $period): bool
    {
        return $user->hasRole(['Admin', 'Manager']) && ! $period->is_system;
    }

    /**
     * A period used by a duty limit cannot be deleted.
     */
    public function delete(User $user, Period $period): bool
    {
        return $this->update($user, $period) && ! $period->dutyLimits()->exists();
    }
}
