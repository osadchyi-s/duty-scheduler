<?php

return [
    /*
     * Granularity used in the interface for event dates, shift durations and
     * rest periods: "days" or "hours". Values are always stored in hours, so
     * switching back to "hours" requires no data migration.
     */
    'time_unit' => env('SCHEDULE_TIME_UNIT', 'days'),
];
