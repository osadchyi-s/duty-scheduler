<?php

namespace App\Periods;

use Carbon\Carbon;

/**
 * A kind of period (calendar week, fixed range, ...). New kinds are added by
 * implementing this interface and registering the class in PeriodTypes.
 */
interface PeriodType
{
    public function label(): string;

    /**
     * Built-in types exist as system periods and are not offered when creating a period.
     */
    public function isSystem(): bool;

    /**
     * All windows of this period that contain the given day. Calendar and
     * fixed periods return at most one window; sliding windows may return several.
     *
     * @return array<int, PeriodWindow>
     */
    public function windowsContaining(array $params, Carbon $day): array;

    /**
     * Filament fields for the type-specific settings, relative to the "params" state path.
     */
    public function formSchema(): array;

    /**
     * Short human-readable description of the settings for tables.
     */
    public function describe(array $params): string;
}
