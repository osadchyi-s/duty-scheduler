<?php

namespace App\Providers;

use App\Jobs\PruneActivityLog;
use App\Models\Role;
use App\Policies\ActivityPolicy;
use App\Policies\RolePolicy;
use App\Services\ConstraintsEngine;
use App\Support\AuditLog;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Activitylog\Models\Activity;

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
        Gate::policy(Activity::class, ActivityPolicy::class);

        $this->registerActivityLogging();
    }

    /**
     * Authentication entries and the daily pruning of old activity log entries.
     */
    protected function registerActivityLogging(): void
    {
        Event::listen(Login::class, fn (Login $event) => AuditLog::record(
            'login', $event->user, ['ip' => request()->ip(), 'remember' => $event->remember], AuditLog::LOG_AUTH, $event->user,
        ));
        Event::listen(Logout::class, function (Logout $event): void {
            if ($event->user) {
                AuditLog::record('logout', $event->user, ['ip' => request()->ip()], AuditLog::LOG_AUTH, $event->user);
            }
        });
        // Only the email is logged for a failed attempt, never the password.
        Event::listen(Failed::class, fn (Failed $event) => AuditLog::record(
            'login_failed', $event->user, ['email' => $event->credentials['email'] ?? null, 'ip' => request()->ip()], AuditLog::LOG_AUTH,
        ));

        // New entries push the old ones out: the first entry of a day queues the pruning job.
        Activity::created(function (): void {
            if (Cache::add('activity-log:pruning-dispatched', true, now()->addDay())) {
                PruneActivityLog::dispatch()->afterCommit();
            }
        });
    }
}
