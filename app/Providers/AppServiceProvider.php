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
        $this->app->singleton(ConstraintsEngine::class);
    }

    public function boot(): void
    {
        // App\Models\* policies (UserEventPolicy, EventTypePolicy, UserPolicy)
        // авто-виявляються за конвенцією Laravel. Role — з пакету Spatie,
        // тому реєструємо явно.
        Gate::policy(Role::class, RolePolicy::class);
    }
}
