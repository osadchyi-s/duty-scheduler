<?php

namespace App\Services;

/**
 * Підсумок роботи AutoScheduler за один запуск — для показу менеджеру перед затвердженням.
 */
class AutoScheduleResult
{
    /** @var array<int, array{date:string,event_type:string,user:string,status:string}> */
    public array $created = [];

    /** @var array<int, array{date:string,event_type:string,needed:int,filled:int,reason:string}> */
    public array $deficits = [];

    public int $warningCount = 0;

    public function addCreated(string $date, string $eventType, string $user, string $status): void
    {
        $this->created[] = compact('date', 'eventType', 'user', 'status');
        if ($status === ConstraintCheckResult::STATUS_WARNING) {
            $this->warningCount++;
        }
    }

    public function addDeficit(string $date, string $eventType, int $needed, int $filled, string $reason): void
    {
        $this->deficits[] = compact('date', 'eventType', 'needed', 'filled', 'reason');
    }

    public function createdCount(): int
    {
        return count($this->created);
    }

    public function deficitCount(): int
    {
        return count($this->deficits);
    }

    /**
     * Livewire не вміє серіалізувати довільні об'єкти в публічних властивостях
     * компонента — тому сторінка зберігає результат як масив, отриманий звідси.
     */
    public function toArray(): array
    {
        return [
            'created' => $this->created,
            'deficits' => $this->deficits,
            'warningCount' => $this->warningCount,
            'createdCount' => $this->createdCount(),
            'deficitCount' => $this->deficitCount(),
        ];
    }
}
