<?php

namespace Database\Seeders;

use App\Models\EventType;
use Illuminate\Database\Seeder;

class EventTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            [
                'name' => 'Добове чергування MAIN',
                'code' => 'duty_24h',
                'color_code' => '#3B82F6',
                'is_blocking' => false,
                'min_capacity' => 1,
                'max_capacity' => 1,
                'min_rest_hours' => 24,
                'preferred_rest_hours' => 48,
                'default_start_time' => '00:00:00',
                'default_duration_hours' => 24,
                'is_schedulable' => true,
                'is_work_time' => true,
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'name' => 'Добове чергування COPILOT',
                'code' => 'duty_24h_copilot',
                'color_code' => '#e05f9f',
                'is_blocking' => false,
                'min_capacity' => 1,
                'max_capacity' => 1,
                'min_rest_hours' => 24,
                'preferred_rest_hours' => 48,
                'default_start_time' => '00:00:00',
                'default_duration_hours' => 24,
                'is_schedulable' => true,
                'is_work_time' => true,
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'name' => 'Денна зміна SECOND',
                'code' => 'day_shift_second',
                'color_code' => '#10B981',
                'is_blocking' => false,
                'min_capacity' => 1,
                'max_capacity' => 1,
                'min_rest_hours' => 0,
                'preferred_rest_hours' => 24,
                'default_start_time' => '00:00:00',
                'default_duration_hours' => 24,
                'is_schedulable' => true,
                'is_work_time' => true,
                'is_active' => true,
                'sort_order' => 2,
            ],
            [
                'name' => 'Денна зміна FIRST',
                'code' => 'day_shift',
                'color_code' => '#8936a8',
                'is_blocking' => false,
                'min_capacity' => 1,
                'max_capacity' => 1,
                'min_rest_hours' => 0,
                'preferred_rest_hours' => 24,
                'default_start_time' => '00:00:00',
                'default_duration_hours' => 24,
                'is_schedulable' => true,
                'is_work_time' => true,
                'is_active' => true,
                'sort_order' => 2,
            ],
            [
                'name' => 'Відпустка',
                'code' => 'vacation',
                'color_code' => '#F59E0B',
                'is_blocking' => true,
                'min_capacity' => 0,
                'max_capacity' => null,
                'min_rest_hours' => 0,
                'preferred_rest_hours' => 0,
                'default_start_time' => null,
                'default_duration_hours' => null,
                'is_schedulable' => false,
                'is_work_time' => false,
                'is_active' => true,
                'sort_order' => 3,
            ],
            [
                'name' => 'Лікарняне',
                'code' => 'sick_leave',
                'color_code' => '#EF4444',
                'is_blocking' => true,
                'min_capacity' => 0,
                'max_capacity' => null,
                'min_rest_hours' => 0,
                'preferred_rest_hours' => 0,
                'default_start_time' => null,
                'default_duration_hours' => null,
                'is_schedulable' => false,
                'is_work_time' => false,
                'is_active' => true,
                'sort_order' => 4,
            ],
            [
                'name' => 'Відрядження',
                'code' => 'business_trip',
                'color_code' => '#8B5CF6',
                'is_blocking' => true,
                'min_capacity' => 0,
                'max_capacity' => null,
                'min_rest_hours' => 0,
                'preferred_rest_hours' => 0,
                'default_start_time' => null,
                'default_duration_hours' => null,
                'is_schedulable' => false,
                'is_work_time' => true,
                'is_active' => true,
                'sort_order' => 5,
            ],
        ];

        foreach ($types as $type) {
            EventType::updateOrCreate(['code' => $type['code']], $type);
        }
    }
}
