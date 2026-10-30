<?php

use App\Filament\Pages\DutyMatrix;
use App\Models\EventType;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

function countClass(string $html, string $class): int
{
    return preg_match_all('/class="[^"]*\\b'.preg_quote($class, '/').'\\b[^"]*"/', $html);
}

beforeEach(function () {
    Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web']);
    $manager = User::create(['name' => 'Менеджер', 'email' => 'manager@test.local', 'password' => bcrypt('secret')]);
    $manager->assignRole('Manager');

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($manager);
});

it('highlights Saturdays and Sundays while the weekend toggle is on', function () {
    // November 2026 has 9 weekend days: 1, 7, 8, 14, 15, 21, 22, 28, 29.
    $page = Livewire::test(DutyMatrix::class)->set('month', '2026-11');

    expect(countClass($page->html(), 'dm-weekend-head'))->toBe(9);

    $page->set('showHighlights.weekends', false);
    expect(countClass($page->html(), 'dm-weekend-head'))->toBe(0);
});

it('highlights dates with a staffing deficit', function () {
    EventType::create(['name' => 'Чергування', 'code' => 'duty', 'color_code' => '#3B82F6', 'min_capacity' => 1, 'is_active' => true]);

    $html = Livewire::test(DutyMatrix::class)->set('month', '2026-11')->html();

    expect(countClass($html, 'dm-deficit'))->toBe(30);
});

it('uses only color utilities that exist in the precompiled Filament CSS', function () {
    // The project has no Tailwind build: a utility missing from Filament's CSS silently does nothing.
    $css = file_get_contents(public_path('css/filament/filament/app.css'));
    $view = file_get_contents(resource_path('views/filament/pages/duty-matrix.blade.php'));
    preg_match_all('#(?:dark:|hover:)*(?:bg|text|border)-[a-z]+-\d+(?:/\d+)?#', $view, $matches);

    // Covered by the custom rules in the view (dark cell borders) or purely cosmetic.
    $allowed = ['dark:border-gray-800', 'dark:hover:bg-gray-800/50'];
    $missing = collect($matches[0])->unique()
        ->reject(fn (string $class): bool => in_array($class, $allowed, true))
        ->reject(fn (string $class): bool => str_contains($css, '.'.str_replace([':', '/'], ['\\:', '\\/'], $class)))
        ->values()
        ->all();

    expect($missing)->toBe([]);
});
