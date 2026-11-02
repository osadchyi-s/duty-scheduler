---
name: scheduling-domain
description: Business rules of duty scheduling in duty-scheduler - event time boundaries (days mode), rest periods, duty limits and period types, schedule drafts and approval, auto-generation, inactive employees and past dates. Use before changing ConstraintsEngine, DutyLimitChecker, AutoScheduler, ScheduleDraftService, the duty matrix or anything that reads or writes user_events.
---

# Scheduling domain rules

These rules were agreed with the product owner; keep them unless asked to change them.

## Time unit and event boundaries

- `config('scheduling.time_unit')` is `days` (default) or `hours`. Values are
  **always stored in hours** (`min_rest_hours`, `default_duration_hours`, ...);
  convert only for display with `App\Support\TimeUnit`.
- In days mode every event is `[D 00:00, D+N 00:00)`: the **end is exclusive**.
  An event ending exactly at 00:00 does not cover that day — use
  `UserEvent::lastCoveredDay()` / `lastCoveredDayFor()`, never `ends_at->startOfDay()`.
- Days an event counts for (duty limits): `UserEvent::countedDaysFor()` =
  `ceil(duration / 24 h)` days from the start day. A 24 h shift from Sunday 08:00
  (hours mode) counts for Sunday only.
- DST (Europe/Kyiv): in days mode use calendar days (`addDays`,
  `TimeUnit::hoursBetween()` uses wall-clock time), not `addHours(24)` or
  `diffInMinutes`, or a day becomes 23/25 hours.

## Assignment checks (`ConstraintsEngine::validate()`)

Order: employee is active → permission for the event type → blocking
exclusivity (`is_blocking`, both directions) → overlapping work events →
capacity (`max_capacity`) → duty limits → rest. A forbidden result stops the
chain; a soft duty-limit warning is merged with the rest result.

- **Rest belongs to the period after an event.** The gap between two events is
  checked only against the rules of the **earlier** event's type. If type 82
  requires one day of rest, the next day must be free; the day before an 82
  event may be busy. Below `min_rest_hours` → forbidden; below
  `preferred_rest_hours` → warning.
- Warnings are stored at save time (`has_warning`, `warning_reason`, text).
  Duty-limit violations of existing events are computed live for the matrix
  (`DutyLimitChecker::violations()`), so they also show for events created
  before a limit.
- Always pass the scenario: `validate($user, $type, $start, $end, $ignoreEventId, $draft)`.

## Duty limits

- A limit = period + max **days** per employee in each window + severity
  (`forbidden` / `warning`) + optional groups + optional event types.
- **No groups = all employees. No event types = all working types**
  (`is_work_time`). All applicable limits are checked, so the strictest wins.
- Period types (`app/Periods/`, registry `PeriodTypes`):
  calendar week (Monday–Sunday) and calendar month (built-in, read-only),
  fixed range, **recurring** (fixed slots, e.g. 7 days every 7 days from an
  anchor — a weekly *grid*), **rolling** (any N consecutive days). "No more than
  2 duties in any 7 days" needs **rolling**, not recurring.
- New period type = class implementing `PeriodType` (may return several
  windows per day) + one line in `PeriodTypes`; no migration.
- A period or group used by a limit cannot be deleted (a limit left without
  groups would silently apply to everyone).

## Scenarios: approved schedule and drafts

- `user_events.schedule_draft_id IS NULL` = approved schedule; otherwise the
  event belongs to a draft. `status` is only `approved` or `cancelled`.
- Query events through `UserEvent::inScenario($draft)`: a draft sees its own
  events plus approved events **outside** its month (rules across the month
  boundary still apply). Never mix scenarios in one query by hand.
- Matrix and coverage caches are per month and scenario: `MatrixCache`.
- Creating a draft copies the approved events overlapping the month
  (`source_event_id` points to the original).
- **Approval** replaces approved events of **schedulable** types
  (`is_schedulable`) that **start** in the month; vacations etc. always stay.
  The user may keep approved events missing from the draft. All new events are
  inserted first, then each is validated against the result; any forbidden
  event rolls the whole approval back. Other drafts of the month become stale
  (kept, can be approved later). Approvals of one month are serialized with
  `lockForUpdate`.

## Auto-generation (`AutoScheduler`)

- From the UI it always generates **into a draft** (new or existing), for a
  period within one month (for the current month, from today by default).
- Candidates: only active employees with permission; among valid candidates
  only those with the **lowest workload in hours**, then a weighted random pick
  (synergy raises the weight up to 100×, more shifts of this type lower it).
- Modes: `fill_missing` and `delete_all` (deletes only events of the scenario itself).

## Employees, dates, deletion

- Inactive employees get no new events (any path, including draft approval).
  The matrix shows them only while they have events in the period, last,
  struck through and marked "Inactive".
- Past dates are dimmed; assigning or generating there is allowed but warns.
- Event types and employees with events cannot be deleted (FK `RESTRICT`);
  deactivate them instead.
- Synergy is three-state: -1, 0, 1.
