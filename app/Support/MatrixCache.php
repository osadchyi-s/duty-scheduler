<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Cache keys of the duty matrix and staffing coverage, per month and scenario
 * (the approved schedule or a draft).
 */
final class MatrixCache
{
    public static function matrixKey(string $month, ?int $draftId): string
    {
        return "wfm:matrix:{$month}".self::suffix($draftId);
    }

    public static function coverageKey(string $month, ?int $draftId): string
    {
        return "wfm:coverage:{$month}".self::suffix($draftId);
    }

    public static function forgetRange(Carbon $from, Carbon $to, ?int $draftId): void
    {
        $current = $from->copy()->startOfMonth();
        $last = $to->copy()->startOfMonth();

        while ($current->lte($last)) {
            Cache::forget(self::matrixKey($current->format('Y-m'), $draftId));
            Cache::forget(self::coverageKey($current->format('Y-m'), $draftId));
            $current->addMonth();
        }
    }

    private static function suffix(?int $draftId): string
    {
        return $draftId === null ? '' : ":draft:{$draftId}";
    }
}
