<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use InvalidArgumentException;

class Synergy extends Model
{
    use HasFactory, RecordsActivity;

    protected static function booted(): void
    {
        static::saving(function (Synergy $synergy): void {
            if (! $synergy->user_a_id || ! $synergy->user_b_id) {
                return;
            }

            if ((int) $synergy->user_a_id === (int) $synergy->user_b_id) {
                throw new InvalidArgumentException('A synergy must connect two different employees.');
            }

            if ((int) $synergy->user_a_id > (int) $synergy->user_b_id) {
                $userAId = $synergy->user_a_id;
                $synergy->user_a_id = $synergy->user_b_id;
                $synergy->user_b_id = $userAId;
            }
        });
    }

    protected $fillable = [
        'user_a_id',
        'user_b_id',
        'score',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
        ];
    }

    public function userA(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_a_id');
    }

    public function userB(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_b_id');
    }

    public function eventTypes(): BelongsToMany
    {
        return $this->belongsToMany(EventType::class, 'synergy_event_type')
            ->withTimestamps();
    }
}
