<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use App\Periods\PeriodType;
use App\Periods\PeriodTypes;
use App\Periods\PeriodWindow;
use App\Periods\Weekdays;
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

    protected static function booted(): void
    {
        static::saving(function (Period $period): void {
            // The form also submits the empty fields of the other period types.
            $params = array_filter($period->params ?? [], fn ($value): bool => $value !== null);
            $weekdays = Weekdays::normalize($params['weekdays'] ?? []);

            if ($weekdays === []) {
                unset($params['weekdays']);
            } else {
                $params['weekdays'] = $weekdays;
            }

            $period->params = $params === [] ? null : $params;
        });
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
     * Days of the week the period counts (ISO numbers); empty for every day.
     *
     * @return array<int, int>
     */
    public function weekdays(): array
    {
        return Weekdays::normalize($this->params['weekdays'] ?? []);
    }

    /**
     * Settings of the period type plus the days-of-week filter, for tables.
     */
    public function describe(): string
    {
        $description = $this->periodType()->describe($this->params ?? []);
        $weekdays = $this->weekdays();

        if ($weekdays === []) {
            return $description;
        }

        return trim($description.'; '.__('only :days', ['days' => Weekdays::shortNames($weekdays)]), '; ');
    }

    /**
     * Unique windows that contain at least one of the given days. The days-of-week
     * filter applies to every period type: other days belong to no window, and the
     * windows count only the selected days of the week.
     *
     * @param  iterable<Carbon>  $days
     * @return array<string, PeriodWindow>
     */
    public function windowsForDays(iterable $days): array
    {
        $weekdays = $this->weekdays();
        $windows = [];

        foreach ($days as $day) {
            if ($weekdays !== [] && ! in_array($day->dayOfWeekIso, $weekdays, true)) {
                continue;
            }

            foreach ($this->periodType()->windowsContaining($this->params ?? [], $day) as $window) {
                $window = $window->withWeekdays($weekdays);
                $windows[$window->key()] = $window;
            }
        }

        return $windows;
    }
}
