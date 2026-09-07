<?php

use App\Models\EventType;
use App\Models\User;

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
