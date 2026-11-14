<?php

namespace App\Services;

use App\Models\EventType;
use App\Models\ScheduleDraft;
use App\Models\Synergy;
use App\Models\User;
use App\Models\UserEvent;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Unwanted pairings: two employees with synergy -1 must not both have working
 * events on the same day. Auto-generation never creates such a pairing; manual
 * assignments are allowed with a warning, and the matrix marks existing ones.
 *
 * A synergy applies to an event type when it has no event types (all types) or
 * lists that type; a pairing counts only if it applies to the events of both employees.
 */
class SynergyChecker
{
    /** @var Collection<int, Synergy>|null Pairs with a negative score, loaded once per instance. */
    protected ?Collection $unwantedPairs = null;

    /**
     * Reasons why the prospective event pairs the employee with someone unwanted.
     *
     * @return array<int, string>
     */
    public function conflicts(
        User $user,
        EventType $eventType,
        Carbon $startsAt,
        Carbon $endsAt,
        ?int $ignoreEventId = null,
        ?ScheduleDraft $draft = null,
    ): array {
        if (! $eventType->is_work_time) {
            return [];
        }

        $partners = $this->unwantedPairs()
            ->filter(fn (Synergy $pair): bool => in_array((int) $user->id, [(int) $pair->user_a_id, (int) $pair->user_b_id], true)
                && $this->appliesTo($pair, $eventType))
            ->mapWithKeys(fn (Synergy $pair): array => [
                ((int) $pair->user_a_id === (int) $user->id ? (int) $pair->user_b_id : (int) $pair->user_a_id) => $pair,
            ]);

        if ($partners->isEmpty()) {
            return [];
        }

        return UserEvent::query()
            ->inScenario($draft)
            ->whereIn('user_id', $partners->keys())
            ->where('status', '!=', 'cancelled')
            ->when($ignoreEventId, fn ($query) => $query->whereKeyNot($ignoreEventId))
            ->overlappingPeriod($startsAt, $endsAt)
            ->with(['eventType', 'user'])
            ->get()
            ->filter(fn (UserEvent $other): bool => $other->eventType->is_work_time
                && $this->appliesTo($partners[$other->user_id], $other->eventType))
            ->map(fn (UserEvent $other): string => $this->reason($other->user->name, $other->eventType->name))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Existing events that form an unwanted pairing, for highlighting in the matrix.
     *
     * @param  Collection<int, UserEvent>  $events  events of one scenario with eventType loaded
     * @return array<int, array<int, string>> event id => reasons
     */
    public function violations(Collection $events): array
    {
        $pairs = $this->unwantedPairs();
        $byUser = $events
            ->filter(fn (UserEvent $event): bool => $event->status !== 'cancelled' && $event->eventType->is_work_time)
            ->groupBy('user_id');

        if ($pairs->isEmpty() || $byUser->count() < 2) {
            return [];
        }

        $names = User::query()->whereIn('id', $byUser->keys())->pluck('name', 'id');
        $violations = [];

        foreach ($pairs as $pair) {
            foreach ($byUser->get($pair->user_a_id, collect()) as $a) {
                foreach ($byUser->get($pair->user_b_id, collect()) as $b) {
                    $overlap = $a->starts_at->lt($b->ends_at) && $b->starts_at->lt($a->ends_at);
                    if (! $overlap || ! $this->appliesTo($pair, $a->eventType) || ! $this->appliesTo($pair, $b->eventType)) {
                        continue;
                    }

                    $violations[$a->id][] = $this->reason($names[$b->user_id] ?? '', $b->eventType->name);
                    $violations[$b->id][] = $this->reason($names[$a->user_id] ?? '', $a->eventType->name);
                }
            }
        }

        return array_map(fn (array $reasons): array => array_values(array_unique($reasons)), $violations);
    }

    /**
     * @return Collection<int, Synergy>
     */
    protected function unwantedPairs(): Collection
    {
        return $this->unwantedPairs ??= Synergy::query()
            ->where('score', '<', 0)
            ->with('eventTypes')
            ->get();
    }

    protected function appliesTo(Synergy $pair, EventType $eventType): bool
    {
        return $pair->eventTypes->isEmpty() || $pair->eventTypes->contains('id', $eventType->id);
    }

    protected function reason(string $partnerName, string $eventTypeName): string
    {
        return __('Unwanted pairing with :name (“:event”) on this day (synergy −1).', [
            'name' => $partnerName,
            'event' => $eventTypeName,
        ]);
    }
}
