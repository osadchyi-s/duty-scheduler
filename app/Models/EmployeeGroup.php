<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class EmployeeGroup extends Model
{
    protected $fillable = [
        'name',
        'description',
    ];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function dutyLimits(): BelongsToMany
    {
        return $this->belongsToMany(DutyLimit::class)->withTimestamps();
    }
}
