<?php

namespace Database\Seeders;

use App\Models\EventType;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin@system.local'],
            [
                'name' => 'Adam Walker',
                'password' => Hash::make('password'),
                'position' => 'System administrator',
                'is_active' => true,
            ]
        );
        $admin->syncRoles(['Admin']);

        $manager = User::firstOrCreate(
            ['email' => 'manager@system.local'],
            [
                'name' => 'Emily Carter',
                'password' => Hash::make('password'),
                'position' => 'Planning manager',
                'is_active' => true,
            ]
        );
        $manager->syncRoles(['Manager']);

        $employeeNames = [
            'James Miller',
            'Peter Wilson',
            'Samuel Reed',
            'Mary Collins',
            'Thomas Grant',
            'Olivia Bennett',
        ];

        $allEventTypes = EventType::all();
        $allEventTypeIds = $allEventTypes->pluck('id')->toArray();
        $duty24hId = EventType::where('code', 'duty_24h')->value('id');

        foreach ($employeeNames as $i => $name) {
            $email = 'employee'.($i + 1).'@system.local';
            $employee = User::firstOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => Hash::make('password'),
                    'position' => 'Engineer',
                    'is_active' => true,
                ]
            );
            $employee->syncRoles(['User']);

            // Give every employee access to duties plus vacation/sick leave by default.
            $employee->allowedEventTypes()->syncWithoutDetaching($allEventTypeIds);
        }

        $fullAccessWorkers = [
            'worker01@system.local' => 'Alex Turner',
            'worker02@system.local' => 'Natalie Brooks',
            'worker03@system.local' => 'Daniel Hughes',
            'worker04@system.local' => 'Irene Foster',
            'worker05@system.local' => 'Victor Hayes',
            'worker06@system.local' => 'Anna Parker',
            'worker07@system.local' => 'Brandon Cole',
            'worker08@system.local' => 'Julia Morris',
            'worker09@system.local' => 'Max Fisher',
            'worker10@system.local' => 'Sophia King',
            'worker11@system.local' => 'Arthur Price',
            'worker12@system.local' => 'Valerie Ward',
            'worker13@system.local' => 'Roman Ellis',
            'worker14@system.local' => 'Oksana Gray',
            'worker15@system.local' => 'Dennis Lane',
        ];

        $restrictedWorkers = [
            'worker16@system.local' => 'Marina Shaw',
            'worker17@system.local' => 'Igor Black',
            'worker18@system.local' => 'Kate Lawson',
            'worker19@system.local' => 'Eugene Palmer',
            'worker20@system.local' => 'Lucy Tanner',
        ];

        foreach ($fullAccessWorkers as $email => $name) {
            $worker = User::firstOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => Hash::make('password'),
                    'position' => 'Operator',
                    'is_active' => true,
                ]
            );
            $worker->syncRoles(['User']);
            $worker->allowedEventTypes()->syncWithoutDetaching($allEventTypeIds);
        }

        foreach ($restrictedWorkers as $email => $name) {
            $worker = User::firstOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => Hash::make('password'),
                    'position' => 'Operator',
                    'is_active' => true,
                ]
            );
            $worker->syncRoles(['User']);
            $worker->allowedEventTypes()->syncWithoutDetaching(
                collect($allEventTypeIds)->reject(fn ($id) => $id === $duty24hId)->values()->all()
            );
        }
    }
}
