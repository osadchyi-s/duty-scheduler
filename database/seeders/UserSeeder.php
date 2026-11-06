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
                'name' => 'Головний Адміністратор',
                'password' => Hash::make('password'),
                'position' => 'Системний адміністратор',
                'is_active' => true,
            ]
        );
        $admin->syncRoles(['Admin']);

        $manager = User::firstOrCreate(
            ['email' => 'manager@system.local'],
            [
                'name' => 'Олена Менеджерова',
                'password' => Hash::make('password'),
                'position' => 'Менеджер з планування',
                'is_active' => true,
            ]
        );
        $manager->syncRoles(['Manager']);

        $employeeNames = [
            'Іванов Іван Володимирович',
            'Петров Петро Сергійович',
            'Сидоренко Сидір Олексійович',
            'Коваленко Марія Петрівна',
            'Шевченко Тарас Григорович',
            'Бондаренко Ольга Іванівна',
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
                    'position' => 'Співробітник',
                    'is_active' => true,
                ]
            );
            $employee->syncRoles(['User']);

            // Give every employee access to duties plus vacation/sick leave by default.
            $employee->allowedEventTypes()->syncWithoutDetaching($allEventTypeIds);
        }

        $fullAccessWorkers = [
            'worker01@system.local' => 'Андрійчук Олексій Миколайович',
            'worker02@system.local' => 'Гриценко Наталія Сергіївна',
            'worker03@system.local' => 'Лисенко Дмитро Володимирович',
            'worker04@system.local' => 'Морозенко Ірина Олегівна',
            'worker05@system.local' => 'Савченко Віталій Іванович',
            'worker06@system.local' => 'Деревянко Анна Павлівна',
            'worker07@system.local' => 'Тищенко Богдан Олександрович',
            'worker08@system.local' => 'Клименко Юлія Василівна',
            'worker09@system.local' => 'Федоренко Максим Андрійович',
            'worker10@system.local' => 'Кравчук Софія Дмитрівна',
            'worker11@system.local' => 'Панченко Артем Сергійович',
            'worker12@system.local' => 'Зубенко Валерія Віталіївна',
            'worker13@system.local' => 'Нечипоренко Роман Олександрович',
            'worker14@system.local' => 'Романчук Оксана Михайлівна',
            'worker15@system.local' => 'Гордієнко Денис Ігорович',
        ];

        $restrictedWorkers = [
            'worker16@system.local' => 'Білоус Марина Юріївна',
            'worker17@system.local' => 'Костенко Ігор Петрович',
            'worker18@system.local' => 'Лавренко Катерина Андріївна',
            'worker19@system.local' => 'Павленко Євгеній Михайлович',
            'worker20@system.local' => 'Тарасенко Людмила Вікторівна',
        ];

        foreach ($fullAccessWorkers as $email => $name) {
            $worker = User::firstOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => Hash::make('password'),
                    'position' => 'Робітник',
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
                    'position' => 'Робітник',
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
