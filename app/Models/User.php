<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use App\Support\MatrixCache;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    use HasFactory, Notifiable, HasRoles, RecordsActivity;

    protected static function booted(): void
    {
        // New employees are appended to the end of the manual order.
        static::creating(function (User $user): void {
            if (! $user->sort_order) {
                $user->sort_order = (int) static::query()->max('sort_order') + 1;
            }
        });

        static::saved(function (User $user): void {
            if ($user->wasChanged(['is_active', 'name', 'position', 'sort_order']) || $user->wasRecentlyCreated) {
                MatrixCache::invalidateEmployees();
            }
        });
    }

    /** Mirrors the database default, so a newly created model is active before it is refreshed. */
    protected $attributes = [
        'is_active' => true,
    ];

    protected $fillable = [
        'name',
        'email',
        'password',
        'position',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active;
    }

    public function events(): HasMany
    {
        return $this->hasMany(UserEvent::class);
    }

    /**
     * Synergy pairs are unordered: the employee is either user A or user B.
     * HasMany always adds "user_a_id = ?"; a plain orWhere() after it would let
     * later conditions bind only to the OR branch ("... or user_b_id = ? and id = ?"),
     * so looking up one record (Filament edit/delete) returned the first pair of
     * user A. The constraint is therefore replaced by one parenthesized group.
     */
    public function synergies(): HasMany
    {
        $relation = $this->hasMany(Synergy::class, 'user_a_id');

        $base = $relation->getQuery()->getQuery();
        $base->wheres = [];
        $base->bindings['where'] = [];

        $relation->where(fn ($query) => $query
            ->where('user_a_id', $this->getKey())
            ->orWhere('user_b_id', $this->getKey()));

        return $relation;
    }

    public function employeeGroups(): BelongsToMany
    {
        return $this->belongsToMany(EmployeeGroup::class)->withTimestamps();
    }

    public function allowedEventTypes(): BelongsToMany
    {
        return $this->belongsToMany(EventType::class, 'user_event_type_permissions');
    }

    public function isAllowedEventType(int $eventTypeId): bool
    {
        return $this->allowedEventTypes()->where('event_types.id', $eventTypeId)->exists();
    }

    /**
     * Never log credentials; only profile attributes.
     */
    protected function activityLogAttributes(): array
    {
        return ['name', 'email', 'position', 'is_active'];
    }
}
