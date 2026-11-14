<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Spatie role with activity logging (configured in config/permission.php).
 */
class Role extends SpatieRole
{
    use RecordsActivity;

    protected function activityLogAttributes(): array
    {
        return ['name', 'guard_name'];
    }
}
