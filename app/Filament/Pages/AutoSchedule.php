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
use Illuminate\Validation\ValidationException;

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
                    Forms\Components\Radio::make('existing_events')
                        ->label('Що робити з уже призначеними подіями вибраних типів у періоді')
                        ->options([
                            AutoScheduler::EXISTING_FILL_MISSING => 'Лише заповнити відсутні призначення',
                            AutoScheduler::EXISTING_DELETE_DRAFTS => 'Видалити лише чернетки та перепризначити',
                            AutoScheduler::EXISTING_DELETE_ALL => 'Видалити всі призначення та перепризначити',
                        ])
                        ->descriptions([
                            AutoScheduler::EXISTING_FILL_MISSING => 'Зберегти наявні події та заповнити незаповнені місця до обраної місткості.',
                            AutoScheduler::EXISTING_DELETE_DRAFTS => 'Видаляються лише чернетки вибраних типів. Затверджені події залишаються.',
                            AutoScheduler::EXISTING_DELETE_ALL => 'Видаляються чернетки й затверджені події вибраних типів. Скасовані події не змінюються.',
                        ])
                        ->default(AutoScheduler::EXISTING_FILL_MISSING)
                        ->helperText('Увага: режими видалення видаляють події вибраних типів, що перетинаються з періодом, без можливості відновлення.')
                        ->required(),
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
            throw ValidationException::withMessages(['event_type_ids' => 'Оберіть хоча б один тип події.']);
        }

        $from = Carbon::parse($data['from'])->startOfDay();
        $to = Carbon::parse($data['to'])->endOfDay();

        if ($from->diffInDays($to) > 366) {
            throw ValidationException::withMessages(['to' => 'Період не може перевищувати 366 днів.']);
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
        $details = "Створено призначень: {$result['createdCount']} (з попередженням: {$result['warningCount']}). Незаповнених слотів: {$result['deficitCount']}.";
        if ($result['deletedCount'] > 0) {
            $details .= " Видалено попередніх призначень: {$result['deletedCount']}.";
        }

        if ($result['createdCount'] === 0 && $result['deficitCount'] === 0 && $result['deletedCount'] === 0) {
            Notification::make()
                ->title('Нічого не змінено')
                ->body('Обрані слоти вже заповнені або типам подій не вказано тривалість.')
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title('Автогенерація завершена')
            ->body($details)
            ->success()
            ->send();
    }
}
