<?php

namespace App\Models;

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
    use HasFactory, Notifiable, HasRoles;

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

    public function synergies(): HasMany
    {
        return $this->hasMany(Synergy::class, 'user_a_id')
            ->orWhere('user_b_id', $this->getKey());
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
}
