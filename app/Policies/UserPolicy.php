<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(['Admin', 'Manager']);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(['Admin', 'Manager']);
    }

    public function update(User $user, User $model): bool
    {
        return $user->hasRole(['Admin', 'Manager']);
    }

    public function delete(User $user, User $model): bool
    {
        // Лише Admin і не сам себе.
        return $user->hasRole('Admin') && $user->id !== $model->id;
    }
}
