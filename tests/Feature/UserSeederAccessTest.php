<?php

use App\Models\DutyLimit;
use App\Models\EventType;
use App\Models\Period;
use App\Models\Synergy;
use App\Models\User;

it('seeds English demo data with the scheduling rules of the demo, once', function () {
    $this->artisan('db:seed')->assertSuccessful();
    $this->artisan('db:seed')->assertSuccessful();

    expect(User::where('email', 'admin@system.local')->value('name'))->toBe('Adam Walker')
        ->and(User::where('email', 'worker20@system.local')->value('position'))->toBe('Operator')
        ->and(EventType::where('code', 'duty_24h')->value('name'))->toBe('24h duty MAIN')
        // No Cyrillic left in the seeded names.
        ->and(User::pluck('name')->merge(EventType::pluck('name'))->merge(Period::pluck('name'))
            ->filter(fn (string $name): bool => (bool) preg_match('/\p{Cyrillic}/u', $name))->all())->toBe([]);

    $weekends = Period::where('name', 'Monthly weekends')->sole();
    $limit = DutyLimit::where('name', 'Two duties per weekend')->sole();

    expect($weekends->weekdays())->toBe([6, 7])
        ->and(Period::where('name', 'Long weekends')->sole()->weekdays())->toBe([4, 7])
        ->and($limit->period_id)->toBe($weekends->id)
        ->and($limit->eventTypes()->pluck('code')->sort()->values()->all())->toBe(['day_shift', 'day_shift_second', 'duty_24h', 'duty_24h_copilot'])
        ->and(DutyLimit::count())->toBe(2)
        ->and(Synergy::count())->toBe(1)
        ->and(Synergy::sole()->score)->toBe(-1);
});

it('creates 20 additional workers with correct event type access', function () {
    $this->artisan('db:seed')->assertSuccessful();

    $allEventTypeIds = EventType::pluck('id')->all();
    $duty24hId = EventType::where('code', 'duty_24h')->value('id');
    $restrictedIds = EventType::where('code', '!=', 'duty_24h')->pluck('id')->all();

    $workerUsers = User::whereIn('email', [
        'worker01@system.local',
        'worker02@system.local',
        'worker03@system.local',
        'worker04@system.local',
        'worker05@system.local',
        'worker06@system.local',
        'worker07@system.local',
        'worker08@system.local',
        'worker09@system.local',
        'worker10@system.local',
        'worker11@system.local',
        'worker12@system.local',
        'worker13@system.local',
        'worker14@system.local',
        'worker15@system.local',
        'worker16@system.local',
        'worker17@system.local',
        'worker18@system.local',
        'worker19@system.local',
        'worker20@system.local',
    ])->get();

    expect($workerUsers)->toHaveCount(20);

    foreach (['worker01@system.local', 'worker02@system.local', 'worker03@system.local', 'worker04@system.local', 'worker05@system.local', 'worker06@system.local', 'worker07@system.local', 'worker08@system.local', 'worker09@system.local', 'worker10@system.local', 'worker11@system.local', 'worker12@system.local', 'worker13@system.local', 'worker14@system.local', 'worker15@system.local'] as $email) {
        $user = User::where('email', $email)->first();
        expect($user)->not->toBeNull();
        expect($user->allowedEventTypes()->pluck('event_types.id')->all())->toEqualCanonicalizing($allEventTypeIds);
    }

    foreach (['worker16@system.local', 'worker17@system.local', 'worker18@system.local', 'worker19@system.local', 'worker20@system.local'] as $email) {
        $user = User::where('email', $email)->first();
        expect($user)->not->toBeNull();
        expect($user->allowedEventTypes()->pluck('event_types.id')->all())->toEqualCanonicalizing($restrictedIds);
        expect($user->isAllowedEventType((int) $duty24hId))->toBeFalse();
    }
});
