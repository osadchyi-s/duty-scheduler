<?php

namespace App\Policies;

use App\Models\EventType;
use App\Models\User;

class EventTypePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->hasRole(['Admin', 'Manager']);
    }

    public function update(User $user, EventType $eventType): bool
    {
        return $user->hasRole(['Admin', 'Manager']);
    }

    /**
     * Event types with events keep their history and are deactivated instead.
     */
    public function delete(User $user, EventType $eventType): bool
    {
        return $user->hasRole('Admin') && ! $eventType->events()->exists();
    }
}
