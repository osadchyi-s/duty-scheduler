<?php

namespace App\Filament\Pages;

use App\Models\EventType;
use App\Models\ScheduleDraft;
use App\Services\AutoScheduler;
use App\Services\ScheduleDraftService;
use App\Support\TimeUnit;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Validation\ValidationException;

/**
 * Automatic duty assignment for a period within one month. Always generates
 * into a schedule draft (a new or an existing one); a manager reviews the
 * draft in the duty matrix and approves it. Available to Admin / Manager.
 */
class AutoSchedule extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static string $view = 'filament.pages.auto-schedule';

    protected static ?int $navigationSort = 1;

    public ?array $data = [];

    /**
     * Stored as a plain array (not an AutoScheduleResult object): Livewire
     * serializes only simple types (arrays/scalars) in public properties.
     */
    public ?array $result = null;

    public static function getNavigationLabel(): string
    {
        return __('Schedule auto-generation');
    }

    public function getTitle(): string
    {
        return __('Duty schedule auto-generation');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole(['Admin', 'Manager']) ?? false;
    }

    public function mount(): void
    {
        $this->form->fill(self::defaultFormData());
    }

    public static function defaultFormData(?string $month = null): array
    {
        $start = Carbon::createFromFormat('!Y-m', $month ?? now()->format('Y-m'))->startOfMonth();

        return [
            'from' => $start->format('Y-m-d'),
            'to' => $start->copy()->endOfMonth()->format('Y-m-d'),
            'event_type_ids' => EventType::where('min_capacity', '>', 0)
                ->where('is_active', true)
                ->pluck('id')
                ->toArray(),
            'fill_to_max' => false,
            'existing_events' => AutoScheduler::EXISTING_FILL_MISSING,
            'draft_id' => null,
        ];
    }

    /**
     * @param  bool  $withDraftSelect  false when the target draft is defined by the caller (the matrix tab)
     */
    public static function formSchema(bool $withDraftSelect = true): array
    {
        return [
            Forms\Components\Section::make(__('Period'))
                ->description(__('The period must lie within one month.'))
                ->columns($withDraftSelect ? 3 : 2)
                ->schema(array_filter([
                    Forms\Components\DatePicker::make('from')
                        ->label(__('From date'))
                        ->required()
                        ->live(),
                    Forms\Components\DatePicker::make('to')
                        ->label(__('To date'))
                        ->required()
                        ->afterOrEqual('from'),
                    $withDraftSelect
                        ? Forms\Components\Select::make('draft_id')
                            ->label(__('Draft'))
                            ->placeholder(__('New draft'))
                            ->options(fn (Forms\Get $get): array => filled($get('from'))
                                ? ScheduleDraft::query()->forMonth(Carbon::parse($get('from')))->orderBy('id')->pluck('name', 'id')->all()
                                : [])
                        : null,
                ])),

            Forms\Components\Section::make(__('Event types to assign'))
                ->description(__('By default, event types requiring at least one person per day (min_capacity > 0) are selected'))
                ->schema([
                    Forms\Components\CheckboxList::make('event_type_ids')
                        ->label('')
                        ->options(fn () => EventType::where('is_active', true)
                            ->orderBy('sort_order')
                            ->get()
                            ->mapWithKeys(fn (EventType $t) => [
                                $t->id => $t->name.($t->default_duration_hours ? __(' (:duration, min. :capacity people)', ['duration' => TimeUnit::format(TimeUnit::normalizeDurationHours($t->default_duration_hours)), 'capacity' => $t->min_capacity]) : __(' — duration is not set; this event type will be skipped')),
                            ]))
                        ->columns(2)
                        ->required(),
                ]),

            Forms\Components\Section::make(__('Strategy'))
                ->schema([
                    Forms\Components\Radio::make('existing_events')
                        ->label(__('What to do with existing events of the selected types in this period'))
                        ->options([
                            AutoScheduler::EXISTING_FILL_MISSING => __('Fill missing assignments only'),
                            AutoScheduler::EXISTING_DELETE_ALL => __('Delete all assignments and reassign'),
                        ])
                        ->descriptions([
                            AutoScheduler::EXISTING_FILL_MISSING => __('Keep existing events and fill empty slots up to the selected capacity.'),
                            AutoScheduler::EXISTING_DELETE_ALL => __('Events of the selected types in the draft and period are deleted and assigned again.'),
                        ])
                        ->default(AutoScheduler::EXISTING_FILL_MISSING)
                        ->helperText(__('Only the draft changes. The approved schedule changes when the draft is approved.'))
                        ->required(),
                    Forms\Components\Toggle::make('fill_to_max')
                        ->label(__('Fill to maximum capacity (max_capacity)'))
                        ->helperText(__('Off = fill only to the required minimum capacity (min_capacity)'))
                        ->default(false),
                ]),
        ];
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema(self::formSchema())
            ->statePath('data');
    }

    public function generate(AutoScheduler $scheduler, ScheduleDraftService $drafts): void
    {
        $this->result = self::generateResult($this->form->getState(), $scheduler, $drafts);
        self::sendResultNotification($this->result);
    }

    /**
     * Generates into the given draft, or into the draft selected in the form,
     * or into a newly created draft of the period's month.
     */
    public static function generateResult(array $data, AutoScheduler $scheduler, ScheduleDraftService $drafts, ?ScheduleDraft $draft = null): array
    {
        if (empty($data['event_type_ids'])) {
            throw ValidationException::withMessages(['event_type_ids' => __('Select at least one event type.')]);
        }

        $from = Carbon::parse($data['from'])->startOfDay();
        $to = Carbon::parse($data['to'])->endOfDay();

        if ($from->format('Y-m') !== $to->format('Y-m')) {
            throw ValidationException::withMessages(['to' => __('The period must lie within one month.')]);
        }

        if ($draft === null && filled($data['draft_id'] ?? null)) {
            $draft = ScheduleDraft::query()->forMonth($from)->findOrFail($data['draft_id']);
        }

        if ($draft !== null && $draft->month->format('Y-m') !== $from->format('Y-m')) {
            throw ValidationException::withMessages(['from' => __('The period must lie within the draft month.')]);
        }

        $draft ??= $drafts->create($from, auth()->user());

        return $scheduler->generate(
            $from,
            $to,
            array_map('intval', $data['event_type_ids']),
            (bool) ($data['fill_to_max'] ?? false),
            $data['existing_events'] ?? AutoScheduler::EXISTING_FILL_MISSING,
            $draft,
        )->toArray() + [
            'draftId' => $draft->id,
            'draftName' => $draft->name,
            'matrixUrl' => DutyMatrix::getUrl(['month' => $from->format('Y-m'), 'draft' => $draft->id]),
        ];
    }

    public static function sendResultNotification(array $result): void
    {
        $details = __('Assignments created: :created (with warnings: :warnings). Unfilled slots: :deficits.', [
            'created' => $result['createdCount'],
            'warnings' => $result['warningCount'],
            'deficits' => $result['deficitCount'],
        ]);
        if ($result['deletedCount'] > 0) {
            $details .= ' '.__('Previous assignments deleted: :count.', ['count' => $result['deletedCount']]);
        }
        if (isset($result['draftName'])) {
            $details = __('Draft: :name.', ['name' => $result['draftName']]).' '.$details;
        }

        if ($result['createdCount'] === 0 && $result['deficitCount'] === 0 && $result['deletedCount'] === 0) {
            Notification::make()
                ->title(__('No changes made'))
                ->body(__('The selected slots are already filled or event types have no duration set.'))
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('Auto-generation complete'))
            ->body($details)
            ->success()
            ->send();
    }
}
