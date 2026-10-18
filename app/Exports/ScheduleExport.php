<?php

namespace App\Exports;

use App\Models\EventType;
use App\Models\User;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Експорт матриці чергувань за місяць у .xlsx зі збереженням візуального стилю (ТЗ п.7).
 */
class ScheduleExport implements FromArray, WithStyles, WithEvents
{
    protected Carbon $monthStart;

    protected Carbon $monthEnd;

    protected array $eventCellColors = [];

    public function __construct(protected string $month)
    {
        $this->monthStart = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $this->monthEnd = $this->monthStart->copy()->endOfMonth();
    }

    public function array(): array
    {
        $rows = [];

        $header = [__('Employee')];
        for ($d = $this->monthStart->copy(); $d->lte($this->monthEnd); $d->addDay()) {
            $header[] = $d->format('d.m');
        }
        $header[] = __('Total hours');
        $header[] = __('Shifts');
        $rows[] = $header;

        $employees = User::whereHas('roles', fn ($q) => $q->whereIn('name', ['User', 'Manager', 'Admin']))
            ->where('is_active', true)
            ->with(['events' => function ($q) {
                $q->where('status', '!=', 'cancelled')
                    ->overlappingPeriod($this->monthStart, $this->monthEnd)
                    ->with('eventType');
            }])
            ->orderBy('name')
            ->get();

        foreach ($employees as $rowIndex => $employee) {
            $row = [$employee->name];
            $totalHours = 0;
            $shiftCount = 0;

            $eventsByDate = [];
            foreach ($employee->events as $event) {
                $eventStartDate = $event->starts_at->copy()->startOfDay();
                $eventEndDate = $event->ends_at->copy()->startOfDay();

                for ($date = $eventStartDate->copy(); $date->lte($eventEndDate); $date->addDay()) {
                    $eventsByDate[$date->format('Y-m-d')][] = $event;
                }
            }

            $colIndex = 1;
            for ($d = $this->monthStart->copy(); $d->lte($this->monthEnd); $d->addDay()) {
                $dateKey = $d->format('Y-m-d');
                $dayEvents = $eventsByDate[$dateKey] ?? [];

                if (count($dayEvents)) {
                    $names = [];
                    foreach ($dayEvents as $ev) {
                        $names[] = $ev->eventType->code;
                        if ($ev->eventType->is_work_time) {
                            $totalHours += $ev->durationHours();
                        }
                        $shiftCount++;
                        // Excel-координати заповнюємо пізніше в AfterSheet (rowIndex+2, colIndex+2)
                        $this->eventCellColors[($rowIndex + 2).'_'.($colIndex + 1)] = ltrim($ev->eventType->color_code, '#');
                    }
                    $row[] = implode(',', $names);
                } else {
                    $row[] = '';
                }

                if ($d->isWeekend()) {
                    $this->eventCellColors[($rowIndex + 2).'_'.($colIndex + 1).'_weekend'] = 'FFECEC';
                }

                $colIndex++;
            }

            $row[] = round($totalHours, 1);
            $row[] = $shiftCount;
            $rows[] = $row;
        }

        return $rows;
    }

    public function styles($sheet): array
    {
        $sheet->getStyle('A1:ZZ1')->getFont()->setBold(true);
        $sheet->getStyle('A1:ZZ1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->freezePane('B2');

        return [];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                foreach ($this->eventCellColors as $key => $color) {
                    [$row, $col] = array_pad(explode('_', $key), 2, null);
                    if (str_ends_with($key, '_weekend')) {
                        continue; // спрощено: основний колір типу події має пріоритет
                    }
                    $cellCoordinate = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col).$row;
                    $sheet->getStyle($cellCoordinate)->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()->setARGB('FF'.$color);
                }

                foreach ($sheet->getColumnIterator() as $column) {
                    $sheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
                }
            },
        ];
    }
}
