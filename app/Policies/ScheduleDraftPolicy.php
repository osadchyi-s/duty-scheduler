<?php

namespace App\Policies;

use App\Models\ScheduleDraft;
use App\Models\User;

/**
 * Drafts are visible to and managed by administrators and managers only.
 */
class ScheduleDraftPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(['Admin', 'Manager']);
    }

    public function view(User $user, ScheduleDraft $draft): bool
    {
        return $user->hasRole(['Admin', 'Manager']);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(['Admin', 'Manager']);
    }

    public function update(User $user, ScheduleDraft $draft): bool
    {
        return $user->hasRole(['Admin', 'Manager']);
    }

    public function delete(User $user, ScheduleDraft $draft): bool
    {
        return $user->hasRole(['Admin', 'Manager']);
    }

    public function approve(User $user, ScheduleDraft $draft): bool
    {
        return $user->hasRole(['Admin', 'Manager']);
    }
}
