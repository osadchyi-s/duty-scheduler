<?php

namespace App\Filament\Pages;

use App\Models\EventType;
use App\Services\AutoScheduler;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Автоматичний розподіл чергувань наперед на заданий період (за замовчуванням — місяць).
 * Доступно Admin / Manager (створює лише `draft`-події — фінально затверджує менеджер у Матриці).
 */
class AutoSchedule extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationLabel = 'Автогенерація графіка';

    protected static ?string $title = 'Автогенерація графіка чергувань';

    protected static string $view = 'filament.pages.auto-schedule';

    protected static ?int $navigationSort = 1;

    public ?array $data = [];

    /**
     * Зберігаємо як звичайний масив (не об'єкт AutoScheduleResult) — Livewire
     * серіалізує лише прості типи (масиви/скаляри) в публічних властивостях.
     */
    public ?array $result = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole(['Admin', 'Manager']) ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'from' => now()->startOfMonth()->format('Y-m-d'),
            'to' => now()->endOfMonth()->format('Y-m-d'),
            // За замовчуванням обрані типи, де мінімальна кількість людей на день > 0.
            'event_type_ids' => EventType::where('min_capacity', '>', 0)
                ->where('is_active', true)
                ->pluck('id')
                ->toArray(),
            'fill_to_max' => false,
        ]);
    }

    protected function getFormSchema(): array
    {
        return [
            Forms\Components\Section::make('Період')
                ->columns(2)
                ->schema([
                    Forms\Components\DatePicker::make('from')
                        ->label('Від дати')
                        ->required(),
                    Forms\Components\DatePicker::make('to')
                        ->label('До дати')
                        ->required()
                        ->afterOrEqual('from'),
                ]),

            Forms\Components\Section::make('Типи подій для розподілу')
                ->description('Типово обрані ті, у яких мінімальна кількість людей на день (min_capacity) більша за нуль')
                ->schema([
                    Forms\Components\CheckboxList::make('event_type_ids')
                        ->label('')
                        ->options(fn () => EventType::where('is_active', true)
                            ->orderBy('sort_order')
                            ->get()
                            ->mapWithKeys(fn (EventType $t) => [
                                $t->id => $t->name.($t->default_duration_hours ? " ({$t->default_duration_hours}г, min {$t->min_capacity} люд.)" : ' — не вказано тривалість, буде пропущено'),
                            ]))
                        ->columns(2)
                        ->required(),
                ]),

            Forms\Components\Section::make('Стратегія')
                ->schema([
                    Forms\Components\Toggle::make('fill_to_max')
                        ->label('Заповнювати до максимальної місткості (max_capacity)')
                        ->helperText('Вимкнено = заповнювати лише до мінімально обов’язкової кількості (min_capacity)')
                        ->default(false),
                ]),
        ];
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema($this->getFormSchema())
            ->statePath('data');
    }

    public function generate(AutoScheduler $scheduler): void
    {
        $data = $this->form->getState();

        if (empty($data['event_type_ids'])) {
            Notification::make()->title('Оберіть хоча б один тип події')->danger()->send();

            return;
        }

        $from = Carbon::parse($data['from'])->startOfDay();
        $to = Carbon::parse($data['to'])->endOfDay();

        if ($from->diffInDays($to) > 366) {
            Notification::make()->title('Період занадто великий (макс. 366 днів)')->danger()->send();

            return;
        }

        $scheduleResult = $scheduler->generate($from, $to, $data['event_type_ids'], (bool) $data['fill_to_max']);
        $this->result = $scheduleResult->toArray();

        if ($this->result['createdCount'] === 0 && $this->result['deficitCount'] === 0) {
            Notification::make()
                ->title('Нічого не створено')
                ->body('Усі обрані слоти вже заповнені на цей період, або жоден тип події не має вказаної тривалості зміни.')
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title('Автогенерація завершена')
            ->body("Створено призначень: {$this->result['createdCount']} (з попередженням: {$this->result['warningCount']}). Незаповнених слотів: {$this->result['deficitCount']}.")
            ->success()
            ->send();
    }
}
