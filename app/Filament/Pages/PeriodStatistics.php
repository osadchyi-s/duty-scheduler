<?php

namespace App\Filament\Pages;

use App\Models\EventType;
use App\Models\User;
use App\Models\UserEvent;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;

/**
 * Analytics report for an arbitrary period (spec 6.2).
 */
class PeriodStatistics extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static string $view = 'filament.pages.period-statistics';

    protected static ?int $navigationSort = 2;

    public ?array $filters = [
        'from' => null,
        'to' => null,
    ];

    public static function getNavigationLabel(): string
    {
        return __('Reports and statistics');
    }

    public function getTitle(): string
    {
        return __('Period analytics');
    }

    public function mount(): void
    {
        $this->filters = [
            'from' => now()->startOfMonth()->format('Y-m-d'),
            'to' => now()->endOfMonth()->format('Y-m-d'),
        ];
    }

    protected function getFormSchema(): array
    {
        return [
            Forms\Components\DatePicker::make('from')->label(__('From')),
            Forms\Components\DatePicker::make('to')->label(__('To')),
        ];
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema($this->getFormSchema())
            ->statePath('filters');
    }

    /**
     * Workload distribution table: number of events of each type per employee.
     */
    public function getWorkloadTableProperty(): array
    {
        [$from, $to] = $this->bounds();

        $events = UserEvent::query()
            ->where('status', '!=', 'cancelled')
            ->whereBetween('starts_at', [$from, $to])
            ->with(['user', 'eventType'])
            ->get();

        $table = [];
        foreach ($events as $event) {
            $table[$event->user->name][$event->eventType->name] =
                ($table[$event->user->name][$event->eventType->name] ?? 0) + 1;
        }

        return $table;
    }

    /**
     * Staffing deficit analysis: dates when coverage was below min_capacity.
     */
    public function getDeficitDatesProperty(): array
    {
        [$from, $to] = $this->bounds();
        $eventTypes = EventType::where('min_capacity', '>', 0)->get();

        $deficits = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            foreach ($eventTypes as $type) {
                $count = UserEvent::query()
                    ->where('event_type_id', $type->id)
                    ->where('status', '!=', 'cancelled')
                    ->overlappingPeriod($d->copy()->startOfDay(), $d->copy()->endOfDay())
                    ->count();

                if ($count < $type->min_capacity) {
                    $deficits[] = [
                        'date' => $d->format('d.m.Y'),
                        'event_type' => $type->name,
                        'assigned' => $count,
                        'required' => $type->min_capacity,
                    ];
                }
            }
        }

        return $deficits;
    }

    /**
     * Exception rate: percentage of shifts with has_warning = true.
     */
    public function getExceptionRateProperty(): array
    {
        [$from, $to] = $this->bounds();

        $total = UserEvent::whereBetween('starts_at', [$from, $to])
            ->where('status', '!=', 'cancelled')->count();
        $withWarning = UserEvent::whereBetween('starts_at', [$from, $to])
            ->where('status', '!=', 'cancelled')->where('has_warning', true)->count();

        return [
            'total' => $total,
            'with_warning' => $withWarning,
            'rate' => $total > 0 ? round($withWarning / $total * 100, 1) : 0,
        ];
    }

    protected function bounds(): array
    {
        return [
            Carbon::parse($this->filters['from'] ?? now()->startOfMonth()),
            Carbon::parse($this->filters['to'] ?? now()->endOfMonth()),
        ];
    }
}
