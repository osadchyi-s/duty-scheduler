<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserEvent;

class UserEventPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // усі ролі бачать матрицю (User — лише свій графік/публічну матрицю)
    }

    public function view(User $user, UserEvent $event): bool
    {
        return $user->hasRole(['Admin', 'Manager']) || $event->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->hasRole(['Admin', 'Manager']);
    }

    public function update(User $user, UserEvent $event): bool
    {
        return $user->hasRole(['Admin', 'Manager']);
    }

    public function delete(User $user, UserEvent $event): bool
    {
        return $user->hasRole(['Admin', 'Manager']);
    }
}
