<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use App\Periods\PeriodType;
use App\Periods\PeriodTypes;
use App\Periods\PeriodWindow;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Period extends Model
{
    use RecordsActivity;

    protected $fillable = [
        'name',
        'type',
        'params',
    ];

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'is_system' => 'boolean',
        ];
    }

    public function dutyLimits(): HasMany
    {
        return $this->hasMany(DutyLimit::class);
    }

    public function periodType(): PeriodType
    {
        return PeriodTypes::get($this->type);
    }

    /**
     * System periods are shown under their localized type name.
     */
    public function displayName(): string
    {
        return $this->is_system ? $this->periodType()->label() : $this->name;
    }

    /**
     * Unique windows that contain at least one of the given days.
     *
     * @param  iterable<Carbon>  $days
     * @return array<string, PeriodWindow>
     */
    public function windowsForDays(iterable $days): array
    {
        $windows = [];

        foreach ($days as $day) {
            foreach ($this->periodType()->windowsContaining($this->params ?? [], $day) as $window) {
                $windows[$window->key()] = $window;
            }
        }

        return $windows;
    }
}
