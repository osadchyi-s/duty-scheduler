<?php

namespace App\Providers;

use App\Policies\RolePolicy;
use App\Services\ConstraintsEngine;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped, not singleton: the engine caches duty limits, and scoped instances are
        // reset between requests and queue jobs, so long-running workers see limit changes.
        $this->app->scoped(ConstraintsEngine::class);
    }

    public function boot(): void
    {
        // App\Models\* policies (UserEventPolicy, EventTypePolicy, UserPolicy)
        // are auto-discovered by Laravel convention. Role comes from the Spatie
        // package, so it is registered explicitly.
        Gate::policy(Role::class, RolePolicy::class);
    }
}
