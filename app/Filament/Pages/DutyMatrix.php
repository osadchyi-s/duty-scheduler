<?php

namespace App\Filament\Pages;

use App\Models\AppSetting;
use App\Models\EventType;
use App\Models\User;
use App\Models\UserEvent;
use App\Services\AutoScheduler;
use App\Services\ConstraintCheckResult;
use App\Services\ConstraintsEngine;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * "Duty Matrix" module — the main workspace for the dispatcher/manager (spec 5).
 */
class DutyMatrix extends Page implements HasForms, HasActions
{
    use InteractsWithActions;
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static string $view = 'filament.pages.duty-matrix';

    protected static ?int $navigationSort = 0;

    /** Canonical selected period in "Y-m" format. */
    public string $month;

    /**
     * Month/year are also exposed as separate integer properties so the view can
     * use plain <select> controls. Firefox does not support <input type="month">,
     * so a native month picker would show neither an icon nor a dropdown there.
     */
    public int $selectedMonth;

    public int $selectedYear;

    /**
     * Temporarily hides the "Total / Statistics" column. The column markup is kept
     * in the view and simply not rendered while this is false; flip to true to bring it back.
     */
    public bool $showStatsColumn = false;

    /**
     * Use the full page width (instead of the default 80rem container) so the
     * monthly duty matrix can stretch across the whole screen on wide monitors.
     */
    public function getMaxContentWidth(): MaxWidth
    {
        return MaxWidth::Full;
    }

    public static function getNavigationLabel(): string
    {
        return __('Duty matrix');
    }

    public function getTitle(): string
    {
        return __('Duty matrix');
    }

    public function displayEventNote(?string $note): ?string
    {
        return in_array($note, ['system:auto-generated', 'Автогенерація графіка'], true)
            ? __('Schedule auto-generation')
            : $note;
    }

    protected function persistEventNote(?string $note): ?string
    {
        return in_array($note, ['system:auto-generated', 'Автогенерація графіка', 'Schedule auto-generation'], true)
            ? 'system:auto-generated'
            : $note;
    }

    public array $showHighlights = [
        'rest_zones' => true,
        'warnings' => true,
        'capacity' => true,
        'weekends' => true,
    ];

    /**
     * Apply the configured locale on every Livewire request. The ApplyConfiguredLocale
     * middleware only runs on full page loads, so AJAX requests (wire:navigate,
     * reactive selects) would otherwise fall back to the default locale and, e.g.,
     * render month names in the wrong language.
     */
    public function boot(): void
    {
        $locale = AppSetting::currentLocale();
        app()->setLocale($locale);
        Carbon::setLocale($locale);
    }

    public function mount(): void
    {
        $this->month = request('month', now()->format('Y-m'));
        $parsed = Carbon::createFromFormat('Y-m', $this->month);
        $this->selectedMonth = (int) $parsed->month;
        $this->selectedYear = (int) $parsed->year;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('auto_schedule')
                ->label(__('Schedule auto-generation'))
                ->icon('heroicon-o-sparkles')
                ->color('gray')
                ->visible(fn () => auth()->user()?->hasRole(['Admin', 'Manager']) ?? false)
                ->form(AutoSchedule::formSchema())
                ->fillForm(fn () => AutoSchedule::defaultFormData($this->month))
                ->requiresConfirmation()
                ->modalHeading(__('Schedule auto-generation'))
                ->modalDescription(__('Creates drafts. Reassignment mode may delete existing events of the selected types in this period.'))
                ->modalSubmitActionLabel(__('Generate schedule'))
                ->action(function (array $data, AutoScheduler $scheduler): void {
                    $result = AutoSchedule::generateResult($data, $scheduler);
                    AutoSchedule::sendResultNotification($result);
                    $this->flushMatrixCachesForRange(
                        Carbon::parse($data['from'])->startOfDay(),
                        Carbon::parse($data['to'])->endOfDay(),
                    );
                }),
            Action::make('approve_month')
                ->label(__('Approve month\'s drafts'))
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn () => auth()->user()?->hasRole(['Admin', 'Manager']) ?? false)
                ->requiresConfirmation()
                ->modalHeading(__('Approve all drafts for the month?'))
                ->modalDescription(__('All drafts shown in the current month will be changed to “Approved”.'))
                ->modalSubmitActionLabel(__('Approve'))
                ->action(fn () => $this->approveCurrentMonthDrafts()),
            Action::make('clear_month')
                ->label(__('Clear month'))
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->visible(fn () => auth()->user()?->hasRole('Admin') ?? false)
                ->requiresConfirmation()
                ->modalHeading(__('Clear the current month?'))
                ->modalDescription(__('This permanently deletes all active events in the current month. This changes the schedule and cannot be undone. Continue?'))
                ->action(function (): void {
                    $this->clearCurrentMonth();
                }),
            Action::make('export')
                ->label(__('Export to Excel'))
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->action(fn () => \Maatwebsite\Excel\Facades\Excel::download(
                    new \App\Exports\ScheduleExport($this->month),
                    "duty-matrix-{$this->month}.xlsx"
                )),
        ];
    }

    public function updatedMonth(): void
    {
        // no-op: the grid rebuilds reactively via getEmployeesWithEvents().
    }

    /** Recompose the canonical "Y-m" value whenever the month/year selects change. */
    public function updatedSelectedMonth(): void
    {
        $this->syncMonthFromParts();
    }

    public function updatedSelectedYear(): void
    {
        $this->syncMonthFromParts();
    }

    protected function syncMonthFromParts(): void
    {
        $this->month = sprintf('%04d-%02d', $this->selectedYear, $this->selectedMonth);
    }

    /** Localized month options (1 => January, ...) for the month <select>. */
    public function getMonthOptionsProperty(): array
    {
        $locale = app()->getLocale();
        $options = [];
        for ($m = 1; $m <= 12; $m++) {
            $options[$m] = ucfirst(Carbon::create(null, $m, 1)->locale($locale)->translatedFormat('F'));
        }

        return $options;
    }

    /** Year options spanning a few years around the current one (plus the selected year). */
    public function getYearOptionsProperty(): array
    {
        $current = (int) now()->year;
        $years = range($current - 2, $current + 2);

        if (! in_array($this->selectedYear, $years, true)) {
            $years[] = $this->selectedYear;
        }

        sort($years);

        return $years;
    }

    protected function periodBounds(): array
    {
        $start = Carbon::createFromFormat('Y-m', $this->month)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        return [$start, $end];
    }

    public function getDaysProperty(): Collection
    {
        [$start, $end] = $this->periodBounds();
        $days = collect();
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $days->push($d->copy());
        }

        return $days;
    }

    /**
     * Single query with eager loading (spec 9.1: N+1 problem) + computed cache in Redis (spec 9.2).
     */
    public function getEmployeesProperty(): Collection
    {
        [$start, $end] = $this->periodBounds();
        $cacheKey = "wfm:matrix:{$this->month}";

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($start, $end) {
            return User::query()
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['User', 'Manager', 'Admin']))
                ->where('is_active', true)
                ->with(['events' => function ($q) use ($start, $end) {
                    $q->where('status', '!=', 'cancelled')
                        ->overlappingPeriod($start, $end)
                        ->with('eventType');
                }])
                ->orderBy('name')
                ->get();
        });
    }

    public function getEventTypesProperty(): Collection
    {
        return Cache::remember('wfm:event_types:active', now()->addHour(), function () {
            return EventType::where('is_active', true)->orderBy('sort_order')->get();
        });
    }

    /**
     * Map of user_id => date('Y-m-d') => UserEvent, for fast cell rendering.
     */
    public function eventsIndexFor(User $user): array
    {
        $index = [];

        foreach ($user->events as $event) {
            $currentDay = $event->starts_at->copy()->startOfDay();
            $lastDay = $event->ends_at->copy()->startOfDay();

            while ($currentDay->lte($lastDay)) {
                $index[$currentDay->format('Y-m-d')][] = $event;
                $currentDay->addDay();
            }
        }

        return $index;
    }

    /**
     * Coverage summary per date (for the bottom "Unassigned ⚠️" row).
     */
    public function getCoverageProperty(): array
    {
        [$start, $end] = $this->periodBounds();
        $engine = app(ConstraintsEngine::class);

        return $engine->monthlyCoverage($start, $end);
    }

    public function isUnderStaffed(Carbon $date, EventType $eventType): bool
    {
        $coverage = $this->coverage;
        $assigned = $coverage[$date->format('Y-m-d')][$eventType->id] ?? 0;

        return $eventType->min_capacity > 0 && $assigned < $eventType->min_capacity;
    }

    /**
     * "Quick Assign" action — modal window (spec 5.2.1).
     */
    public function assignAction(): Action
    {
        return Action::make('assign')
            ->label(fn (array $arguments) => ($arguments['eventId'] ?? null) ? __('Edit event') : __('Assign event'))
            ->modalHeading(fn (array $arguments) => ($arguments['eventId'] ?? null) ? __('Edit event') : __('Quick event assignment'))
            ->form([
                Forms\Components\Hidden::make('event_id')->dehydrated(),
                Forms\Components\Select::make('user_id')
                    ->label(__('Employee'))
                    ->options(fn () => User::query()->orderBy('name')->pluck('name', 'id'))
                    ->required()
                    ->disabled(fn (Forms\Get $get) => filled($get('user_id')))
                    ->dehydrated(),
                Forms\Components\Select::make('event_type_id')
                    ->label(__('Event type'))
                    ->options(function (Forms\Get $get) {
                        $userId = $get('user_id');
                        $eventId = $get('event_id');
                        $eventTypeId = $eventId ? UserEvent::find($eventId)?->event_type_id : null;

                        $query = EventType::query()->where('is_active', true);

                        if ($userId) {
                            $user = User::find($userId);
                            if ($user) {
                                $query->whereHas('allowedUsers', fn ($q) => $q->where('users.id', $user->id));
                            }
                        }

                        if ($eventTypeId) {
                            $query->orWhere('id', $eventTypeId);
                        }

                        return $query->orderBy('sort_order')->pluck('name', 'id');
                    })
                    ->required()
                    ->helperText(__('Only event types available to this employee are shown.'))
                    ->live(),
                Forms\Components\DateTimePicker::make('starts_at')
                    ->label(__('Start'))
                    ->required()
                    ->seconds(false),
                Forms\Components\DateTimePicker::make('ends_at')
                    ->label(__('End'))
                    ->required()
                    ->seconds(false)
                    ->after('starts_at'),
                Forms\Components\Textarea::make('note')
                    ->label(__('Note'))
                    ->rows(2),
                Forms\Components\Actions::make([
                    Forms\Components\Actions\Action::make('approve_event')
                        ->label(__('Approve draft'))
                        ->color('success')
                        ->icon('heroicon-o-check-circle')
                        ->visible(fn (Forms\Get $get): bool => filled($get('event_id'))
                            && UserEvent::whereKey($get('event_id'))->where('status', 'draft')->exists()
                            && (auth()->user()?->hasRole(['Admin', 'Manager']) ?? false))
                        ->requiresConfirmation()
                        ->action(function (Forms\Get $get): void {
                            $this->approveEvent((int) $get('event_id'));
                        }),
                    Forms\Components\Actions\Action::make('delete_event')
                        ->label(__('Delete'))
                        ->color('danger')
                        ->icon('heroicon-o-trash')
                        ->visible(fn (Forms\Get $get) => ! empty($get('event_id')))
                        ->requiresConfirmation()
                        ->action(function (Forms\Get $get): void {
                            $eventId = (int) ($get('event_id') ?? 0);

                            if ($eventId > 0) {
                                $this->deleteEvent($eventId);
                            }
                        }),
                ])
                    ->alignment('left'),
            ])
            ->fillForm(function (array $arguments) {
                $eventId = $arguments['eventId'] ?? null;
                $date = $arguments['date'] ?? null;
                $event = $eventId ? UserEvent::with('eventType')->find($eventId) : null;

                $startsAt = $event ? $event->starts_at : ($date ? Carbon::parse($date)->setTime(8, 0, 0) : now());
                $defaultEndsAt = $event ? $event->ends_at : $startsAt->copy()->addHours($this->resolveDefaultShiftHours($event?->eventType?->id ?? null, $date));

                return [
                    'event_id' => $eventId,
                    'user_id' => $event?->user_id ?? ($arguments['userId'] ?? null),
                    'event_type_id' => $event?->event_type_id ?? null,
                    'starts_at' => $startsAt->format('Y-m-d H:i:s'),
                    'ends_at' => $defaultEndsAt->format('Y-m-d H:i:s'),
                    'note' => $this->displayEventNote($event?->note),
                ];
            })
            ->action(function (array $data): void {
                if (! empty($data['event_id'])) {
                    $this->updateEvent($data);

                    return;
                }

                $this->createEvent($data);
            })
            ->extraModalFooterActions([]);
    }

    protected function resolveDefaultShiftHours(?int $eventTypeId, ?string $date): int
    {
        if ($eventTypeId) {
            $eventType = EventType::find($eventTypeId);
            if ($eventType && $eventType->default_duration_hours) {
                return (int) $eventType->default_duration_hours;
            }
        }

        $fallback = EventType::where('is_active', true)->orderBy('default_duration_hours')->value('default_duration_hours');

        return $fallback ?: 8;
    }

    protected function createEvent(array $data): void
    {
        $user = User::findOrFail($data['user_id']);
        $eventType = EventType::findOrFail($data['event_type_id']);
        $startsAt = Carbon::parse($data['starts_at']);
        $endsAt = Carbon::parse($data['ends_at'] ?? $startsAt->copy()->addHours($eventType->default_duration_hours ?? 8));

        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            $endsAt = $startsAt->copy()->addHours($eventType->default_duration_hours ?? 8);
        }

        $engine = app(ConstraintsEngine::class);
        $result = $engine->validate($user, $eventType, $startsAt, $endsAt);

        if (! $result->isAllowed) {
            Notification::make()
                ->title(__('Assignment forbidden'))
                ->body($result->reason)
                ->danger()
                ->send();

            return;
        }

        UserEvent::create([
            'user_id' => $user->id,
            'event_type_id' => $eventType->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => 'draft',
            'has_rest_warning' => $result->status === ConstraintCheckResult::STATUS_WARNING,
            'warning_reason' => $result->reason,
            'note' => $this->persistEventNote($data['note'] ?? null),
            'created_by' => auth()->id(),
        ]);

        $this->flushMatrixCachesForRange($startsAt, $endsAt);

        if ($result->status === ConstraintCheckResult::STATUS_WARNING) {
            Notification::make()
                ->title(__('Event created with a warning ⚠️'))
                ->body($result->reason)
                ->warning()
                ->send();
        } else {
            Notification::make()->title(__('Event assigned successfully'))->success()->send();
        }
    }

    protected function updateEvent(array $data): void
    {
        $event = UserEvent::findOrFail($data['event_id']);
        $user = User::findOrFail($data['user_id']);
        $eventType = EventType::findOrFail($data['event_type_id']);
        $startsAt = Carbon::parse($data['starts_at']);
        $endsAt = Carbon::parse($data['ends_at'] ?? $startsAt->copy()->addHours($eventType->default_duration_hours ?? 8));

        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            $endsAt = $startsAt->copy()->addHours($eventType->default_duration_hours ?? 8);
        }

        $engine = app(ConstraintsEngine::class);
        $result = $engine->validate($user, $eventType, $startsAt, $endsAt, $event->id);

        if (! $result->isAllowed) {
            Notification::make()
                ->title(__('Editing forbidden'))
                ->body($result->reason)
                ->danger()
                ->send();

            return;
        }

        $oldStart = $event->starts_at->copy();
        $oldEnd = $event->ends_at->copy();

        $event->update([
            'user_id' => $user->id,
            'event_type_id' => $eventType->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'has_rest_warning' => $result->status === ConstraintCheckResult::STATUS_WARNING,
            'warning_reason' => $result->reason,
            'note' => $this->persistEventNote($data['note'] ?? null),
        ]);

        $this->flushMatrixCachesForRange($oldStart, $oldEnd);
        $this->flushMatrixCachesForRange($startsAt, $endsAt);

        Notification::make()
            ->title(__('Event updated'))
            ->success()
            ->send();
    }

    public function deleteEvent(int $eventId): void
    {
        $event = UserEvent::findOrFail($eventId);
        abort_unless(auth()->user()?->can('delete', $event), 403);

        $this->flushMatrixCachesForRange($event->starts_at, $event->ends_at);
        $event->delete();

        Notification::make()->title(__('Event deleted'))->success()->send();
    }

    public function approveEvent(int $eventId): void
    {
        abort_unless(auth()->user()?->hasRole(['Admin', 'Manager']), 403);

        $event = UserEvent::findOrFail($eventId);
        if ($event->status !== 'draft') {
            Notification::make()->title(__('Event is no longer a draft'))->warning()->send();

            return;
        }

        $event->update(['status' => 'approved']);
        $this->flushMatrixCachesForRange($event->starts_at, $event->ends_at);

        Notification::make()->title(__('Draft approved'))->success()->send();
    }

    public function approveCurrentMonthDrafts(): void
    {
        abort_unless(auth()->user()?->hasRole(['Admin', 'Manager']), 403);

        [$start, $end] = $this->periodBounds();
        $approved = UserEvent::query()
            ->where('status', 'draft')
            ->overlappingPeriod($start, $end)
            ->update(['status' => 'approved', 'updated_at' => now()]);

        $this->flushMatrixCachesForRange($start, $end);

        Notification::make()
            ->title(__('Monthly drafts approved'))
            ->body(__('Events approved: :count.', ['count' => $approved]))
            ->success()
            ->send();
    }

    public function clearCurrentMonth(): void
    {
        abort_unless(auth()->user()?->hasRole('Admin'), 403);

        [$start, $end] = $this->periodBounds();

        $deleted = UserEvent::query()
            ->where('status', '!=', 'cancelled')
            ->where('starts_at', '>=', $start)
            ->where('starts_at', '<=', $end)
            ->delete();

        $this->flushMatrixCachesForRange($start, $end);

        Notification::make()
            ->title(__('Month cleared'))
            ->body(__('Deleted :count active events for the period :from–:to.', [
                'count' => $deleted,
                'from' => $start->format('d.m.Y'),
                'to' => $end->format('d.m.Y'),
            ]))
            ->success()
            ->send();
    }

    protected function flushMatrixCachesForRange(Carbon $startsAt, Carbon $endsAt): void
    {
        $current = $startsAt->copy()->startOfMonth();
        $last = $endsAt->copy()->startOfMonth();

        while ($current->lte($last)) {
            Cache::forget("wfm:matrix:{$current->format('Y-m')}");
            Cache::forget('wfm:coverage:'.$current->format('Y-m'));
            $current->addMonth();
        }
    }
}
