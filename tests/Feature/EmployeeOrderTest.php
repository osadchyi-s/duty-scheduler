<?php

use App\Exports\ScheduleExport;
use App\Filament\Pages\DutyMatrix;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    foreach (['Admin', 'User'] as $role) {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    }
    $this->admin = User::create(['name' => 'Адмін', 'email' => 'admin@test.local', 'password' => 'secret-password']);
    $this->admin->assignRole('Admin');

    $this->employees = collect([
        ['Яковенко Ярослав', 'Черговий'],
        ['Андрієнко Анна', null],
        ['Мельник Марко', 'Старший черговий'],
    ])->map(function (array $data, int $i) {
        $user = User::create(['name' => $data[0], 'position' => $data[1], 'email' => "e{$i}@test.local", 'password' => 'secret-password']);
        $user->assignRole('User');

        return $user;
    });

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($this->admin);
});

/**
 * Employee names in the order the matrix renders them.
 */
function matrixOrder(string $html, array $names): array
{
    $positions = collect($names)->mapWithKeys(fn (string $name) => [$name => mb_strpos($html, $name)])->filter();

    return $positions->sort()->keys()->all();
}

it('appends new employees to the end of the manual order', function () {
    expect($this->employees->pluck('sort_order')->all())->toBe([2, 3, 4]); // the admin is 1
});

it('saves the order set by drag and drop and shows the matrix in that order', function () {
    [$yakovenko, $andriienko, $melnyk] = $this->employees->all();
    $names = $this->employees->pluck('name')->all();

    // Fill the matrix cache with the initial order first.
    $before = Livewire::test(DutyMatrix::class)->set('month', '2026-11')->html();
    expect(matrixOrder($before, $names))->toBe(['Яковенко Ярослав', 'Андрієнко Анна', 'Мельник Марко']);

    Livewire::test(ListUsers::class)
        ->call('reorderTable', [(string) $melnyk->id, (string) $this->admin->id, (string) $yakovenko->id, (string) $andriienko->id]);

    expect($melnyk->fresh()->sort_order)->toBe(1)
        ->and($andriienko->fresh()->sort_order)->toBe(4)
        ->and(Activity::query()->where('event', 'employees_reordered')->sole()->properties->get('order'))
        ->toBe([$melnyk->id, $this->admin->id, $yakovenko->id, $andriienko->id]);

    $after = Livewire::test(DutyMatrix::class)->set('month', '2026-11')->html();
    expect(matrixOrder($after, $names))->toBe(['Мельник Марко', 'Яковенко Ярослав', 'Андрієнко Анна']);
});

it('shows the row number and the position after the name in one line', function () {
    $html = Livewire::test(DutyMatrix::class)->set('month', '2026-11')->html();

    // Livewire puts <!--[if BLOCK]> markers between conditional elements.
    expect($html)->toContain('dm-col-num')
        ->and($html)->toMatch('/Яковенко Ярослав<\/span>(?:<!--.*?-->)*<span[^>]*> \(Черговий\)<\/span>/')
        // No brackets for an employee without a position.
        ->and($html)->not->toMatch('/Андрієнко Анна<\/span>(?:<!--.*?-->)*<span[^>]*> \(/')
        ->and(preg_match_all('/<td class="dm-vline-head dm-col-num[^"]*">\s*(\d+)\s*<\/td>/', $html, $numbers))->toBeGreaterThan(0)
        ->and($numbers[1])->toBe(array_map('strval', range(1, count($numbers[1]))));
});

it('exports employees in the same order', function () {
    // sort_order is set by drag and drop, not mass assignment.
    $this->employees[2]->forceFill(['sort_order' => 0])->save();

    $names = collect((new ScheduleExport('2026-11'))->array())->skip(1)->pluck(0)->values()->all();

    expect($names[0])->toBe('Мельник Марко');
});
