<?php

namespace App\Services;

/**
 * Result of the constraint check (Constraints Engine) before a user_event is saved.
 */
class ConstraintCheckResult
{
    public const STATUS_OPTIMAL = 'optimal';
    public const STATUS_WARNING = 'warning';   // Acceptable with warning
    public const STATUS_FORBIDDEN = 'forbidden';

    public function __construct(
        public readonly bool $isAllowed,
        public readonly string $status,
        public readonly ?string $reason = null,
        public readonly ?float $restHours = null,
    ) {
    }

    public static function ok(?float $restHours = null): self
    {
        return new self(true, self::STATUS_OPTIMAL, null, $restHours);
    }

    public static function warning(string $reason, ?float $restHours = null): self
    {
        return new self(true, self::STATUS_WARNING, $reason, $restHours);
    }

    public static function forbidden(string $reason, ?float $restHours = null): self
    {
        return new self(false, self::STATUS_FORBIDDEN, $reason, $restHours);
    }
}
