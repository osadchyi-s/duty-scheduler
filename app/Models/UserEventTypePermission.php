<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserEventTypePermission extends Model
{
    protected $fillable = ['user_id', 'event_type_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function eventType(): BelongsTo
    {
        return $this->belongsTo(EventType::class);
    }
}
