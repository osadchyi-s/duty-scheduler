<?php

use App\Filament\Pages\DutyMatrix;
use App\Models\EventType;
use App\Models\User;
use App\Models\UserEvent;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    config(['scheduling.time_unit' => 'days']);
    Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web']);
    $this->manager = User::create(['name' => 'Менеджер', 'email' => 'manager@test.local', 'password' => bcrypt('secret')]);
    $this->manager->assignRole('Manager');
    $this->vacation = EventType::create(['name' => 'Щорічна відпустка', 'code' => 'vac', 'color_code' => '#10B981', 'is_active' => true]);
    $this->duty = EventType::create(['name' => 'Чергування', 'code' => 'duty', 'color_code' => '#3B82F6', 'is_active' => true]);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($this->manager);
});

function mergedCellsCountClass(string $html, string $class): int
{
    return preg_match_all('/class="[^"]*(?<![\\w-])'.preg_quote($class, '/').'(?![\\w-])[^"]*"/', $html);
}

function mergedCellsEvent(User $user, EventType $type, string $from, string $to): UserEvent
{
    return UserEvent::create(['user_id' => $user->id, 'event_type_id' => $type->id, 'starts_at' => $from, 'ends_at' => $to]);
}

it('merges the days of one multi-day event into a single cell with one label', function () {
    // Vacation on 10-12.11 (end exclusive) and a one-day duty right after it.
    mergedCellsEvent($this->manager, $this->vacation, '2026-11-10 00:00:00', '2026-11-13 00:00:00');
    mergedCellsEvent($this->manager, $this->duty, '2026-11-13 00:00:00', '2026-11-14 00:00:00');

    $html = Livewire::test(DutyMatrix::class)->set('month', '2026-11')->html();

    expect($html)->toContain('colspan="3"')
        ->and(substr_count($html, '>Щорічна відпустка</span>'))->toBe(1)
        ->and(substr_count($html, '>Чергування</span>'))->toBe(1)
        // 30 days, 3 of them in one cell: 28 day cells in the employee row.
        ->and(preg_match_all('/class="dm-vline dm-cell/', $html))->toBe(28);
});

it('does not merge days that have other events too', function () {
    $page = Livewire::test(DutyMatrix::class)->set('month', '2026-11')->instance();
    mergedCellsEvent($this->manager, $this->vacation, '2026-11-10 00:00:00', '2026-11-13 00:00:00');
    mergedCellsEvent($this->manager, $this->duty, '2026-11-11 00:00:00', '2026-11-12 00:00:00');
    $this->manager->load('events.eventType');

    $cells = collect($page->rowCells($page->days, $page->eventsIndexFor($this->manager)))
        ->filter(fn (array $cell) => $cell['events'] !== [])
        ->map(fn (array $cell) => [$cell['days'][0]->format('d'), count($cell['days']), count($cell['events'])])
        ->values()
        ->all();

    expect($cells)->toBe([['10', 1, 1], ['11', 1, 2], ['12', 1, 1]]);
});

it('separates weekends with lines before Saturday and after Sunday', function () {
    $page = Livewire::test(DutyMatrix::class)->set('month', '2026-11');

    // November 2026: Sunday 1 (start of month after Saturday 31.10), then 4 full weekends.
    $html = $page->html();
    $rows = 3; // header, employee row, "Unassigned" row
    expect(mergedCellsCountClass($html, 'dm-weekend-start'))->toBe(4 * $rows)
        ->and(mergedCellsCountClass($html, 'dm-weekend-end'))->toBe(5 * $rows);

    $page->set('showHighlights.weekends', false);
    expect(mergedCellsCountClass($page->html(), 'dm-weekend-start'))->toBe(0);
});

it('keeps the weekend lines on the edges of a merged cell', function () {
    // Saturday 7.11 to Monday 9.11 starts a weekend; the line after Sunday falls inside the strip.
    $page = Livewire::test(DutyMatrix::class)->set('month', '2026-11')->instance();

    expect($page->weekendEdgeClasses(Carbon::parse('2026-11-07'), Carbon::parse('2026-11-09')))->toBe('dm-weekend-start')
        ->and($page->weekendEdgeClasses(Carbon::parse('2026-11-05'), Carbon::parse('2026-11-08')))->toBe('dm-weekend-end')
        ->and($page->weekendEdgeClasses(Carbon::parse('2026-11-04')))->toBe('');
});
