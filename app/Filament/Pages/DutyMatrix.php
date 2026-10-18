<?php

namespace App\Filament\Pages;

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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Модуль «Матриця чергувань» — головне робоче місце диспетчера/менеджера (ТЗ п.5).
 */
class DutyMatrix extends Page implements HasForms, HasActions
{
    use InteractsWithActions;
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Матриця чергувань';

    protected static ?string $title = 'Матриця чергувань';

    protected static string $view = 'filament.pages.duty-matrix';

    protected static ?int $navigationSort = 0;

    public string $month;

    public array $showHighlights = [
        'rest_zones' => true,
        'warnings' => true,
        'capacity' => true,
        'weekends' => true,
    ];

    public function mount(): void
    {
        $this->month = request('month', now()->format('Y-m'));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('auto_schedule')
                ->label('Автогенерація графіка')
                ->icon('heroicon-o-sparkles')
                ->color('gray')
                ->visible(fn () => auth()->user()?->hasRole(['Admin', 'Manager']) ?? false)
                ->form(AutoSchedule::formSchema())
                ->fillForm(fn () => AutoSchedule::defaultFormData($this->month))
                ->requiresConfirmation()
                ->modalHeading('Автогенерація графіка')
                ->modalDescription('Створює чернетки. Режим перепризначення може видалити наявні події вибраних типів у періоді.')
                ->modalSubmitActionLabel('Згенерувати графік')
                ->action(function (array $data, AutoScheduler $scheduler): void {
                    $result = AutoSchedule::generateResult($data, $scheduler);
                    AutoSchedule::sendResultNotification($result);
                    $this->flushMatrixCachesForRange(
                        Carbon::parse($data['from'])->startOfDay(),
                        Carbon::parse($data['to'])->endOfDay(),
                    );
                }),
            Action::make('approve_month')
                ->label('Затвердити чернетки місяця')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn () => auth()->user()?->hasRole(['Admin', 'Manager']) ?? false)
                ->requiresConfirmation()
                ->modalHeading('Затвердити всі чернетки за місяць?')
                ->modalDescription('Усі чернетки, що відображаються в поточному місяці, буде переведено у статус «Затверджено».')
                ->modalSubmitActionLabel('Затвердити')
                ->action(fn () => $this->approveCurrentMonthDrafts()),
            Action::make('clear_month')
                ->label('Очистити місяць')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->visible(fn () => auth()->user()?->hasRole('Admin') ?? false)
                ->requiresConfirmation()
                ->modalHeading('Очистити поточний місяць?')
                ->modalDescription('Ця дія видалить усі активні події поточного місяця. Це реально вплине на розклад і не є відміною. Продовжити?')
                ->action(function (): void {
                    $this->clearCurrentMonth();
                }),
            Action::make('export')
                ->label('Експорт в Excel')
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
        // no-op, реактивно перебудує сітку через getEmployeesWithEvents()
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
     * Один запит з eager loading (п.9.1: N+1 problem) + кеш обчислень у Redis (п.9.2).
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
     * Мапа user_id => date('Y-m-d') => UserEvent, для швидкого рендеру клітинок.
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
     * Підсумок покриття по датах (для нижнього рядка "Непризначено ⚠️").
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
     * Дії "Швидке призначення" — модальне вікно (ТЗ п.5.2.1).
     */
    public function assignAction(): Action
    {
        return Action::make('assign')
            ->label(fn (array $arguments) => ($arguments['eventId'] ?? null) ? 'Редагувати подію' : 'Призначити подію')
            ->modalHeading(fn (array $arguments) => ($arguments['eventId'] ?? null) ? 'Редагувати подію' : 'Швидке призначення події')
            ->form([
                Forms\Components\Hidden::make('event_id')->dehydrated(),
                Forms\Components\Select::make('user_id')
                    ->label('Співробітник')
                    ->options(fn () => User::query()->orderBy('name')->pluck('name', 'id'))
                    ->required()
                    ->disabled(fn (Forms\Get $get) => filled($get('user_id')))
                    ->dehydrated(),
                Forms\Components\Select::make('event_type_id')
                    ->label('Тип події')
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
                    ->helperText('Показані лише типи, до яких співробітник має доступ.')
                    ->live(),
                Forms\Components\DateTimePicker::make('starts_at')
                    ->label('Початок')
                    ->required()
                    ->seconds(false),
                Forms\Components\DateTimePicker::make('ends_at')
                    ->label('Закінчення')
                    ->required()
                    ->seconds(false)
                    ->after('starts_at'),
                Forms\Components\Textarea::make('note')
                    ->label('Примітка')
                    ->rows(2),
                Forms\Components\Actions::make([
                    Forms\Components\Actions\Action::make('approve_event')
                        ->label('Затвердити чернетку')
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
                        ->label('Видалити')
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
                    'note' => $event?->note ?? null,
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
                ->title('Призначення заборонено')
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
            'note' => $data['note'] ?? null,
            'created_by' => auth()->id(),
        ]);

        $this->flushMatrixCachesForRange($startsAt, $endsAt);

        if ($result->status === ConstraintCheckResult::STATUS_WARNING) {
            Notification::make()
                ->title('Подію створено з попередженням ⚠️')
                ->body($result->reason)
                ->warning()
                ->send();
        } else {
            Notification::make()->title('Подію успішно призначено')->success()->send();
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
                ->title('Редагування заборонено')
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
            'note' => $data['note'] ?? null,
        ]);

        $this->flushMatrixCachesForRange($oldStart, $oldEnd);
        $this->flushMatrixCachesForRange($startsAt, $endsAt);

        Notification::make()
            ->title('Подію оновлено')
            ->success()
            ->send();
    }

    public function deleteEvent(int $eventId): void
    {
        $event = UserEvent::findOrFail($eventId);
        abort_unless(auth()->user()?->can('delete', $event), 403);

        $this->flushMatrixCachesForRange($event->starts_at, $event->ends_at);
        $event->delete();

        Notification::make()->title('Подію видалено')->success()->send();
    }

    public function approveEvent(int $eventId): void
    {
        abort_unless(auth()->user()?->hasRole(['Admin', 'Manager']), 403);

        $event = UserEvent::findOrFail($eventId);
        if ($event->status !== 'draft') {
            Notification::make()->title('Подія вже не є чернеткою')->warning()->send();

            return;
        }

        $event->update(['status' => 'approved']);
        $this->flushMatrixCachesForRange($event->starts_at, $event->ends_at);

        Notification::make()->title('Чернетку затверджено')->success()->send();
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
            ->title('Чернетки місяця затверджено')
            ->body("Затверджено подій: {$approved}.")
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
            ->title('Місяць очищено')
            ->body("Видалено {$deleted} активних подій за період {$start->format('d.m.Y')}–{$end->format('d.m.Y')}.")
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
