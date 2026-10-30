<?php

namespace App\Filament\Pages;

use App\Models\AppSetting;
use App\Exports\ScheduleExport;
use App\Models\EventType;
use App\Models\ScheduleDraft;
use App\Models\User;
use App\Models\UserEvent;
use App\Services\AutoScheduler;
use App\Services\ConstraintCheckResult;
use App\Services\ConstraintsEngine;
use App\Services\DutyLimitChecker;
use App\Services\ScheduleDraftService;
use App\Support\MatrixCache;
use App\Support\TimeUnit;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
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
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Maatwebsite\Excel\Facades\Excel;

/**
 * "Duty Matrix" module — the main workspace for the dispatcher/manager (spec 5).
 *
 * The first tab shows the approved schedule; administrators and managers also
 * get one tab per schedule draft of the month (see ScheduleDraftService).
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

    /** Selected schedule draft; null shows the approved schedule. Kept in the URL as ?draft=. */
    #[Url(as: 'draft')]
    public ?int $draftId = null;

    protected ?ScheduleDraft $resolvedDraft = null;

    /** Draft id the cached draft was resolved for; false when not resolved yet. */
    protected int|false|null $draftResolvedFor = false;

    /**
     * Temporarily hides the "Total / Statistics" column. The column markup is kept
     * in the view and simply not rendered while this is false; flip to true to bring it back.
     */
    public bool $showStatsColumn = false;

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
        $parsed = Carbon::createFromFormat('!Y-m', $this->month);
        $this->selectedMonth = (int) $parsed->month;
        $this->selectedYear = (int) $parsed->year;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('auto_schedule')
                ->label(fn (): string => $this->draft ? __('Auto-generate in this draft') : __('Auto-generate a new draft'))
                ->icon('heroicon-o-sparkles')
                ->color('gray')
                ->visible(fn (): bool => $this->canManageDrafts())
                ->form(AutoSchedule::formSchema(withDraftSelect: false))
                ->fillForm(fn (): array => AutoSchedule::defaultFormData($this->month))
                ->modalHeading(__('Schedule auto-generation'))
                ->modalDescription(fn (): string => $this->draft
                    ? __('Assignments are generated into the draft “:name”. The approved schedule does not change.', ['name' => $this->draft->name])
                    : __('A new draft of the month is created and assignments are generated into it. The approved schedule does not change.'))
                ->modalSubmitActionLabel(__('Generate schedule'))
                ->action(fn (array $data, AutoScheduler $scheduler, ScheduleDraftService $drafts) => $this->autoGenerate($data, $scheduler, $drafts)),
            Action::make('approve_draft')
                ->label(__('Approve draft'))
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => $this->draft !== null && (auth()->user()?->can('approve', $this->draft) ?? false))
                ->modalHeading(fn (): string => __('Approve the draft “:name”?', ['name' => $this->draft?->name]))
                ->modalSubmitActionLabel(__('Approve'))
                ->form(fn (): array => $this->approvalFormSchema())
                ->action(fn (array $data, ScheduleDraftService $drafts) => $this->approveDraft($data, $drafts)),
            ActionGroup::make([
                Action::make('rename_draft')
                    ->label(__('Rename'))
                    ->icon('heroicon-o-pencil-square')
                    ->form([
                        Forms\Components\TextInput::make('name')->label(__('Name'))->required()->maxLength(255),
                    ])
                    ->fillForm(fn (): array => ['name' => $this->draft?->name])
                    ->action(function (array $data): void {
                        $this->authorizeDraft('update');
                        $this->draft->update(['name' => $data['name']]);
                        Notification::make()->title(__('Draft renamed'))->success()->send();
                    }),
                Action::make('duplicate_draft')
                    ->label(__('Duplicate'))
                    ->icon('heroicon-o-document-duplicate')
                    ->action(function (ScheduleDraftService $drafts): void {
                        $this->authorizeDraft('create');
                        $copy = $drafts->duplicate($this->draft, auth()->user());
                        $this->selectDraft($copy->id);
                        Notification::make()->title(__('Draft duplicated'))->success()->send();
                    }),
                Action::make('delete_draft')
                    ->label(__('Delete draft'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(fn (): string => __('Delete the draft “:name”?', ['name' => $this->draft?->name]))
                    ->modalDescription(__('The draft and all its events are deleted. The approved schedule does not change.'))
                    ->action(function (): void {
                        $this->authorizeDraft('delete');
                        $this->draft->delete();
                        $this->selectDraft(null);
                        Notification::make()->title(__('Draft deleted'))->success()->send();
                    }),
            ])
                ->label(__('Draft'))
                ->icon('heroicon-m-ellipsis-vertical')
                ->button()
                ->color('gray')
                ->visible(fn (): bool => $this->draft !== null),
            Action::make('clear_month')
                ->label(fn (): string => $this->draft ? __('Clear draft') : __('Clear month'))
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->visible(fn (): bool => $this->draft ? $this->canManageDrafts() : (auth()->user()?->hasRole('Admin') ?? false))
                ->requiresConfirmation()
                ->modalHeading(fn (): string => $this->draft ? __('Clear the draft?') : __('Clear the current month?'))
                ->modalDescription(fn (): string => $this->draft
                    ? __('All events of this draft are deleted. The approved schedule does not change.')
                    : __('This permanently deletes all active events in the current month. This changes the schedule and cannot be undone. Continue?'))
                ->action(function (): void {
                    $this->clearCurrentMonth();
                }),
            Action::make('export')
                ->label(__('Export to Excel'))
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->action(fn () => Excel::download(
                    new ScheduleExport($this->month, $this->draft?->id),
                    'duty-matrix-'.$this->month.($this->draft ? '-'.Str::slug($this->draft->name) : '').'.xlsx'
                )),
        ];
    }

    public function canManageDrafts(): bool
    {
        return auth()->user()?->can('viewAny', ScheduleDraft::class) ?? false;
    }

    /**
     * The selected draft of the displayed month, or null for the approved schedule.
     */
    public function getDraftProperty(): ?ScheduleDraft
    {
        if ($this->draftResolvedFor !== $this->draftId) {
            $this->resolvedDraft = $this->draftId !== null && $this->canManageDrafts()
                ? ScheduleDraft::query()->forMonth($this->periodBounds()[0])->find($this->draftId)
                : null;
            $this->draftResolvedFor = $this->draftId;
        }

        return $this->resolvedDraft;
    }

    /**
     * Drafts of the displayed month, shown as tabs.
     *
     * @return Collection<int, ScheduleDraft>
     */
    public function getDraftsProperty(): Collection
    {
        if (! $this->canManageDrafts()) {
            return collect();
        }

        return ScheduleDraft::query()->forMonth($this->periodBounds()[0])->orderBy('id')->get();
    }

    public function selectDraft(?int $draftId): void
    {
        $this->draftId = $draftId;
        $this->limitViolations = null;
    }

    public function createDraft(ScheduleDraftService $drafts): void
    {
        abort_unless(auth()->user()?->can('create', ScheduleDraft::class), 403);

        $draft = $drafts->create($this->periodBounds()[0], auth()->user());
        $this->selectDraft($draft->id);

        Notification::make()
            ->title(__('Draft created'))
            ->body(__('The approved events of the month were copied into the draft.'))
            ->success()
            ->send();
    }

    protected function authorizeDraft(string $ability): void
    {
        abort_unless($this->draft && (auth()->user()?->can($ability, $ability === 'create' ? ScheduleDraft::class : $this->draft) ?? false), 403);
    }

    protected function autoGenerate(array $data, AutoScheduler $scheduler, ScheduleDraftService $drafts): void
    {
        abort_unless($this->canManageDrafts(), 403);

        if (Carbon::parse($data['from'])->format('Y-m') !== $this->month || Carbon::parse($data['to'])->format('Y-m') !== $this->month) {
            Notification::make()
                ->title(__('Auto-generation was not started'))
                ->body(__('The period must lie within the displayed month.'))
                ->danger()
                ->send();

            return;
        }

        try {
            $draft = $this->draft ?? $drafts->create($this->periodBounds()[0], auth()->user());
            $result = AutoSchedule::generateResult($data, $scheduler, $drafts, $draft);
        } catch (ValidationException $exception) {
            Notification::make()
                ->title(__('Auto-generation was not started'))
                ->body(collect($exception->errors())->flatten()->first())
                ->danger()
                ->send();

            return;
        }

        AutoSchedule::sendResultNotification($result);
        $this->selectDraft($draft->id);
    }

    /**
     * Approval modal: summary of the changes and the approved events the draft
     * does not contain, which the user may keep.
     */
    protected function approvalFormSchema(): array
    {
        $draft = $this->draft;
        if ($draft === null) {
            return [];
        }

        $plan = app(ScheduleDraftService::class)->approvalPlan($draft);
        $summary = __('Will be added or changed: :add. Will be removed from the approved schedule: :remove. Unchanged: :unchanged.', [
            'add' => $plan['add']->count(),
            'remove' => $plan['remove']->count(),
            'unchanged' => $plan['unchanged']->count(),
        ]);

        return array_filter([
            $draft->isStale()
                ? Forms\Components\Placeholder::make('stale_notice')
                    ->label('')
                    ->content(__('⚠️ Another draft of this month was approved on :date. Check the differences below.', ['date' => $draft->stale_at->format('d.m.Y H:i')]))
                : null,
            Forms\Components\Placeholder::make('summary')
                ->label(__('Changes'))
                ->content($summary.' '.__('Only event types included in auto-generation are replaced; other events (vacations, sick leave, ...) stay as they are.')),
            $plan['remove']->isNotEmpty()
                ? Forms\Components\CheckboxList::make('keep_event_ids')
                    ->label(__('Approved events missing from the draft'))
                    ->helperText(__('Selected events stay in the approved schedule; the others are removed.'))
                    ->options($plan['remove']->mapWithKeys(fn (UserEvent $event): array => [
                        $event->id => $event->user->name.' — '.$event->eventType->name.' ('.TimeUnit::formatPeriod($event->starts_at, $event->ends_at).')',
                    ])->all())
                    ->columns(1)
                : null,
        ]);
    }

    protected function approveDraft(array $data, ScheduleDraftService $drafts): void
    {
        $this->authorizeDraft('approve');

        try {
            $drafts->approve($this->draft, $data['keep_event_ids'] ?? [], auth()->user());
        } catch (ValidationException $exception) {
            $problems = collect($exception->errors())->flatten();

            Notification::make()
                ->title(__('The draft was not approved'))
                ->body(__('Some assignments break the rules in the approved schedule. Fix them in the draft and try again.')
                    ."\n".$problems->take(10)->implode("\n")
                    .($problems->count() > 10 ? "\n…" : ''))
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        $this->selectDraft(null);

        Notification::make()
            ->title(__('Draft approved'))
            ->body(__('The approved schedule of the month was updated. Other drafts of the month are marked as stale.'))
            ->success()
            ->send();
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
        // Drafts belong to one month, so another month starts on the approved schedule.
        $this->selectDraft(null);
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
        $start = Carbon::createFromFormat('!Y-m', $this->month)->startOfMonth();
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
        $draft = $this->draft;
        $cacheKey = MatrixCache::matrixKey($this->month, $draft?->id);

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($start, $end, $draft) {
            $eventsInPeriod = fn ($q) => $q->inScenario($draft)
                ->where('status', '!=', 'cancelled')
                ->overlappingPeriod($start, $end);

            // Inactive employees are listed only while they still have events in the period (shown last).
            return User::query()
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['User', 'Manager', 'Admin']))
                ->where(fn ($q) => $q->where('is_active', true)->orWhereHas('events', $eventsInPeriod))
                ->with(['events' => fn ($q) => $eventsInPeriod($q)->with('eventType')])
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get();
        });
    }

    /**
     * Days before today are dimmed in the matrix; assigning on them shows a warning.
     */
    public function isPastDay(Carbon $day): bool
    {
        return $day->copy()->startOfDay()->lt(today());
    }

    protected function isPastDate(?string $value): bool
    {
        return filled($value) && $this->isPastDay(Carbon::parse($value));
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
            $lastDay = $event->lastCoveredDay();

            while ($currentDay->lte($lastDay)) {
                $index[$currentDay->format('Y-m-d')][] = $event;
                $currentDay->addDay();
            }
        }

        return $index;
    }

    /** @var array<int, array<int, string>>|null Duty limit violations of the displayed events, per request. */
    protected ?array $limitViolations = null;

    /**
     * Warning reasons for an event: the one stored when it was saved, plus any
     * duty limit it currently exceeds (also for events created before the limit).
     *
     * @return array<int, string>
     */
    public function eventWarnings(UserEvent $event): array
    {
        $this->limitViolations ??= app(DutyLimitChecker::class)->violations(
            $this->employees->flatMap(fn (User $user) => $user->events),
            $this->draft,
        );

        $warnings = $event->has_warning && filled($event->warning_reason) ? [$event->warning_reason] : [];
        foreach ($this->limitViolations[$event->id] ?? [] as $reason) {
            if (! str_contains($event->warning_reason ?? '', $reason)) {
                $warnings[] = $reason;
            }
        }

        return $warnings;
    }

    /**
     * Coverage summary per date (for the bottom "Unassigned ⚠️" row).
     */
    public function getCoverageProperty(): array
    {
        [$start, $end] = $this->periodBounds();
        $engine = app(ConstraintsEngine::class);

        return $engine->monthlyCoverage($start, $end, $this->draft);
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
                ...$this->eventPeriodFields(),
                Forms\Components\Placeholder::make('past_date_warning')
                    ->hiddenLabel()
                    ->content(new HtmlString('<span style="color: rgb(217 119 6); font-weight: 500;">'
                        .e(__('⚠️ The event starts on a past date. Make sure this assignment is intended.')).'</span>'))
                    ->visible(fn (Forms\Get $get): bool => $this->isPastDate($get('starts_at'))),
                Forms\Components\Textarea::make('note')
                    ->label(__('Note'))
                    ->rows(2),
                Forms\Components\Actions::make([
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
                $event = $eventId ? $this->scenarioEvents()->with('eventType')->find($eventId) : null;

                $day = $date ? Carbon::parse($date) : now();
                $startsAt = $event ? $event->starts_at : TimeUnit::shiftStart($day, '08:00:00');
                $defaultEndsAt = $event ? $event->ends_at : $startsAt->copy()->addHours($this->resolveDefaultShiftHours($event?->eventType?->id ?? null, $date));

                return [
                    'event_id' => $eventId,
                    'user_id' => $event?->user_id ?? ($arguments['userId'] ?? null),
                    'event_type_id' => $event?->event_type_id ?? null,
                    ...$this->eventPeriodFormState($startsAt, $defaultEndsAt),
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

        return TimeUnit::normalizeDurationHours($fallback ?: 8);
    }

    /**
     * Start/end fields of the assignment form: whole dates in "days" mode,
     * date and time in "hours" mode.
     */
    protected function eventPeriodFields(): array
    {
        if (TimeUnit::usesDays()) {
            return [
                Forms\Components\DatePicker::make('starts_at')
                    ->label(__('Start date'))
                    ->required()
                    ->live(),
                Forms\Components\DatePicker::make('ends_at')
                    ->label(__('End date (inclusive)'))
                    ->required()
                    ->afterOrEqual('starts_at'),
            ];
        }

        return [
            Forms\Components\DateTimePicker::make('starts_at')
                ->label(__('Start'))
                ->required()
                ->seconds(false)
                ->live(),
            Forms\Components\DateTimePicker::make('ends_at')
                ->label(__('End'))
                ->required()
                ->seconds(false)
                ->after('starts_at'),
        ];
    }

    /**
     * Stored period => form state. In "days" mode the exclusive 00:00 end is
     * shown as the last covered (inclusive) date.
     */
    protected function eventPeriodFormState(Carbon $startsAt, Carbon $endsAt): array
    {
        if (TimeUnit::usesDays()) {
            return [
                'starts_at' => $startsAt->format('Y-m-d'),
                'ends_at' => UserEvent::lastCoveredDayFor($startsAt, $endsAt)->format('Y-m-d'),
            ];
        }

        return [
            'starts_at' => $startsAt->format('Y-m-d H:i:s'),
            'ends_at' => $endsAt->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Form state => stored period [starts_at, ends_at).
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function resolveEventPeriod(array $data, EventType $eventType): array
    {
        $defaultHours = TimeUnit::normalizeDurationHours($eventType->default_duration_hours ?? 8);
        $hasEnd = filled($data['ends_at'] ?? null);

        if (TimeUnit::usesDays()) {
            $startsAt = Carbon::parse($data['starts_at'])->startOfDay();
            $endsAt = $hasEnd ? Carbon::parse($data['ends_at'])->startOfDay()->addDay() : null;
        } else {
            $startsAt = Carbon::parse($data['starts_at']);
            $endsAt = $hasEnd ? Carbon::parse($data['ends_at']) : null;
        }

        if ($endsAt === null || $endsAt->lessThanOrEqualTo($startsAt)) {
            $endsAt = $startsAt->copy()->addHours($defaultHours);
        }

        return [$startsAt, $endsAt];
    }

    protected function createEvent(array $data): void
    {
        $user = User::findOrFail($data['user_id']);
        $eventType = EventType::findOrFail($data['event_type_id']);
        [$startsAt, $endsAt] = $this->resolveEventPeriod($data, $eventType);

        $engine = app(ConstraintsEngine::class);
        $result = $engine->validate($user, $eventType, $startsAt, $endsAt, null, $this->draft);

        if (! $result->isAllowed) {
            Notification::make()
                ->title(__('Assignment forbidden'))
                ->body($result->reason)
                ->danger()
                ->send();

            return;
        }

        UserEvent::create([
            'schedule_draft_id' => $this->draft?->id,
            'user_id' => $user->id,
            'event_type_id' => $eventType->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => 'approved',
            'has_warning' => $result->status === ConstraintCheckResult::STATUS_WARNING,
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

        $this->notifyIfPast($startsAt);
    }

    protected function notifyIfPast(Carbon $startsAt): void
    {
        if ($this->isPastDay($startsAt)) {
            Notification::make()
                ->title(__('The event is on a past date'))
                ->body(__('It starts on :date, which has already passed.', ['date' => $startsAt->format('d.m.Y')]))
                ->warning()
                ->send();
        }
    }

    protected function updateEvent(array $data): void
    {
        $event = $this->scenarioEvents()->findOrFail($data['event_id']);
        $user = User::findOrFail($data['user_id']);
        $eventType = EventType::findOrFail($data['event_type_id']);
        [$startsAt, $endsAt] = $this->resolveEventPeriod($data, $eventType);

        $engine = app(ConstraintsEngine::class);
        $result = $engine->validate($user, $eventType, $startsAt, $endsAt, $event->id, $this->draft);

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
            'has_warning' => $result->status === ConstraintCheckResult::STATUS_WARNING,
            'warning_reason' => $result->reason,
            'note' => $this->persistEventNote($data['note'] ?? null),
        ]);

        $this->flushMatrixCachesForRange($oldStart, $oldEnd);
        $this->flushMatrixCachesForRange($startsAt, $endsAt);

        Notification::make()
            ->title(__('Event updated'))
            ->success()
            ->send();

        if (! $startsAt->eq($oldStart)) {
            $this->notifyIfPast($startsAt);
        }
    }

    public function deleteEvent(int $eventId): void
    {
        $event = $this->scenarioEvents()->findOrFail($eventId);
        abort_unless(auth()->user()?->can('delete', $event), 403);

        $this->flushMatrixCachesForRange($event->starts_at, $event->ends_at);
        $event->delete();

        Notification::make()->title(__('Event deleted'))->success()->send();
    }

    /**
     * Clears the approved month (Admin only) or, in a draft tab, all events of the draft.
     */
    public function clearCurrentMonth(): void
    {
        [$start, $end] = $this->periodBounds();
        $draft = $this->draft;

        if ($draft) {
            $this->authorizeDraft('update');
            $deleted = $draft->events()->delete();
        } else {
            abort_unless(auth()->user()?->hasRole('Admin'), 403);
            $deleted = UserEvent::query()
                ->inScenario(null)
                ->where('status', '!=', 'cancelled')
                ->where('starts_at', '>=', $start)
                ->where('starts_at', '<=', $end)
                ->delete();
        }

        $this->flushMatrixCachesForRange($start, $end);

        Notification::make()
            ->title($draft ? __('Draft cleared') : __('Month cleared'))
            ->body(__('Deleted :count active events for the period :from–:to.', [
                'count' => $deleted,
                'from' => $start->format('d.m.Y'),
                'to' => $end->format('d.m.Y'),
            ]))
            ->success()
            ->send();
    }

    /**
     * Events that can be edited in the current tab: the draft's own events or
     * the approved schedule.
     */
    protected function scenarioEvents()
    {
        return UserEvent::query()->where('schedule_draft_id', $this->draft?->id);
    }

    protected function flushMatrixCachesForRange(Carbon $startsAt, Carbon $endsAt): void
    {
        MatrixCache::forgetRange($startsAt, $endsAt, $this->draft?->id);
        $this->limitViolations = null;
    }
}
