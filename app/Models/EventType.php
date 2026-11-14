<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventType extends Model
{
    use HasFactory, RecordsActivity;

    protected $fillable = [
        'name',
        'code',
        'color_code',
        'is_blocking',
        'min_capacity',
        'max_capacity',
        'min_rest_hours',
        'preferred_rest_hours',
        'default_start_time',
        'default_duration_hours',
        'is_schedulable',
        'is_work_time',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_blocking' => 'boolean',
            'is_work_time' => 'boolean',
            'is_active' => 'boolean',
            'is_schedulable' => 'boolean',
            'min_capacity' => 'integer',
            'max_capacity' => 'integer',
            'min_rest_hours' => 'integer',
            'preferred_rest_hours' => 'integer',
            'default_duration_hours' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(UserEvent::class);
    }

    public function allowedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_event_type_permissions');
    }

    public function isAllowedForUser(User $user): bool
    {
        return $this->allowedUsers()->where('users.id', $user->id)->exists();
    }
}
