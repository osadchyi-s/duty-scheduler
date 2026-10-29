<?php

namespace App\Filament\Pages;

use App\Models\EventType;
use App\Services\AutoScheduler;
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
 * Автоматичний розподіл чергувань наперед на заданий період (за замовчуванням — місяць).
 * Доступно Admin / Manager (створює лише `draft`-події — фінально затверджує менеджер у Матриці).
 */
class AutoSchedule extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static string $view = 'filament.pages.auto-schedule';

    protected static ?int $navigationSort = 1;

    public ?array $data = [];

    /**
     * Зберігаємо як звичайний масив (не об'єкт AutoScheduleResult) — Livewire
     * серіалізує лише прості типи (масиви/скаляри) в публічних властивостях.
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
        $start = Carbon::createFromFormat('Y-m', $month ?? now()->format('Y-m'))->startOfMonth();

        return [
            'from' => $start->format('Y-m-d'),
            'to' => $start->copy()->endOfMonth()->format('Y-m-d'),
            'event_type_ids' => EventType::where('min_capacity', '>', 0)
                ->where('is_active', true)
                ->pluck('id')
                ->toArray(),
            'fill_to_max' => false,
            'existing_events' => AutoScheduler::EXISTING_FILL_MISSING,
        ];
    }

    public static function formSchema(): array
    {
        return [
            Forms\Components\Section::make(__('Period'))
                ->columns(2)
                ->schema([
                    Forms\Components\DatePicker::make('from')
                        ->label(__('From date'))
                        ->required(),
                    Forms\Components\DatePicker::make('to')
                        ->label(__('To date'))
                        ->required()
                        ->afterOrEqual('from'),
                ]),

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
                            AutoScheduler::EXISTING_DELETE_DRAFTS => __('Delete drafts only and reassign'),
                            AutoScheduler::EXISTING_DELETE_ALL => __('Delete all assignments and reassign'),
                        ])
                        ->descriptions([
                            AutoScheduler::EXISTING_FILL_MISSING => __('Keep existing events and fill empty slots up to the selected capacity.'),
                            AutoScheduler::EXISTING_DELETE_DRAFTS => __('Only drafts of the selected types are deleted. Approved events remain.'),
                            AutoScheduler::EXISTING_DELETE_ALL => __('Draft and approved events of the selected types are deleted. Cancelled events are unchanged.'),
                        ])
                        ->default(AutoScheduler::EXISTING_FILL_MISSING)
                        ->helperText(__('Warning: delete modes permanently delete events of the selected types that overlap this period.'))
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

    public function generate(AutoScheduler $scheduler): void
    {
        $this->result = self::generateResult($this->form->getState(), $scheduler);
        self::sendResultNotification($this->result);
    }

    public static function generateResult(array $data, AutoScheduler $scheduler): array
    {
        if (empty($data['event_type_ids'])) {
            throw ValidationException::withMessages(['event_type_ids' => __('Select at least one event type.')]);
        }

        $from = Carbon::parse($data['from'])->startOfDay();
        $to = Carbon::parse($data['to'])->endOfDay();

        if ($from->diffInDays($to) > 366) {
            throw ValidationException::withMessages(['to' => __('The period cannot exceed 366 days.')]);
        }

        return $scheduler->generate(
            $from,
            $to,
            array_map('intval', $data['event_type_ids']),
            (bool) ($data['fill_to_max'] ?? false),
            $data['existing_events'] ?? AutoScheduler::EXISTING_FILL_MISSING,
        )->toArray();
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
