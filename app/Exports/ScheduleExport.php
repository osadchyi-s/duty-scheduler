<?php

namespace App\Exports;

use App\Models\EventType;
use App\Models\ScheduleDraft;
use App\Models\User;
use App\Support\TimeUnit;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Monthly duty matrix export to .xlsx that keeps the visual style (spec 7).
 */
class ScheduleExport implements FromArray, WithStyles, WithEvents
{
    protected Carbon $monthStart;

    protected Carbon $monthEnd;

    protected array $eventCellColors = [];

    /**
     * @param  int|null  $draftId  schedule draft to export; null exports the approved schedule
     */
    public function __construct(protected string $month, protected ?int $draftId = null)
    {
        $this->monthStart = Carbon::createFromFormat('!Y-m', $month)->startOfMonth();
        $this->monthEnd = $this->monthStart->copy()->endOfMonth();
    }

    public function array(): array
    {
        $rows = [];

        $header = [__('Employee')];
        for ($d = $this->monthStart->copy(); $d->lte($this->monthEnd); $d->addDay()) {
            $header[] = $d->format('d.m');
        }
        $header[] = TimeUnit::usesDays() ? __('Total days') : __('Total hours');
        $header[] = __('Shifts');
        $rows[] = $header;

        $draft = $this->draftId ? ScheduleDraft::findOrFail($this->draftId) : null;

        $eventsInMonth = fn ($q) => $q->inScenario($draft)
            ->where('status', '!=', 'cancelled')
            ->overlappingPeriod($this->monthStart, $this->monthEnd);

        // Same rows as the matrix: inactive employees only while they have events in the month.
        $employees = User::whereHas('roles', fn ($q) => $q->whereIn('name', ['User', 'Manager', 'Admin']))
            ->where(fn ($q) => $q->where('is_active', true)->orWhereHas('events', $eventsInMonth))
            ->with(['events' => fn ($q) => $eventsInMonth($q)->with('eventType')])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        foreach ($employees as $rowIndex => $employee) {
            $row = [$employee->is_active ? $employee->name : $employee->name.' ('.__('inactive').')'];
            $totalHours = 0;
            $shiftCount = 0;
            $countedEventIds = [];

            $eventsByDate = [];
            foreach ($employee->events as $event) {
                $eventStartDate = $event->starts_at->copy()->startOfDay();
                $eventEndDate = $event->lastCoveredDay();

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
                        // A multi-day event is shown on every day but counted in the totals once.
                        if (! isset($countedEventIds[$ev->id])) {
                            $countedEventIds[$ev->id] = true;
                            if ($ev->eventType->is_work_time) {
                                $totalHours += $ev->durationHours();
                            }
                            $shiftCount++;
                        }
                        // Excel coordinates are filled in later in AfterSheet (rowIndex+2, colIndex+2)
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

            $row[] = round(TimeUnit::toDisplay($totalHours), 1);
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
                        continue; // simplified: the event type color takes precedence
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
