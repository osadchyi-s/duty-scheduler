# Duty Matrix

A duty scheduling, absence tracking, and workforce planning application.
Built with Laravel 11, Filament v3 (TALL), PostgreSQL 16, and Redis 7, and
configured to run with Docker.

## Getting started

Docker and Docker Compose must be installed.

```bash
cp .env.example .env
make init
```

`make init` builds the images, starts the containers, installs Composer
dependencies, generates the `APP_KEY`, runs database migrations and seeders,
publishes Filament assets, and creates the storage link.

When initialization is complete, open **http://localhost:8080/admin**.

### Demo accounts

The `UserSeeder` creates these accounts:

| Role | Email | Password |
|---|---|---|
| Admin | admin@system.local | password |
| Manager | manager@system.local | password |
| User (6 employees) | employee1@system.local ... employee6@system.local | password |

## Project structure

```text
app/
  Models/            User, EventType, UserEvent, UserEventTypePermission,
                     Synergy, AppSetting, EmployeeGroup, Period, DutyLimit
  Services/
    ConstraintsEngine.php      Core assignment validation logic
    ConstraintCheckResult.php  Validation result (optimal/warning/forbidden)
    AutoScheduler.php          Automatic schedule generation
    DutyLimitChecker.php       Duty limit checks and violations
  Periods/                     Period types (calendar week/month, fixed, recurring)
  Support/
    TimeUnit.php               Days/hours conversion for the interface
  Policies/          Filament authorization policies
  Filament/
    Resources/       Event types, employees (including synergies), roles,
                     employee groups, periods, duty limits
    Pages/
      DutyMatrix.php            Duty matrix
      AutoSchedule.php          Automatic schedule generation
      PeriodStatistics.php      Period reports and statistics
      AppSettings.php           Administrator settings, including locale
  Exports/
    ScheduleExport.php          Styled .xlsx export
config/
  scheduling.php     Interface time unit (days or hours)
database/
  migrations/        Events, permissions, synergies, and application settings
  seeders/           Roles, users, and event types
lang/
  en.json            English source strings
  uk.json            Ukrainian translations
resources/views/filament/pages/
  duty-matrix.blade.php
  auto-schedule.blade.php
  period-statistics.blade.php
tests/Unit/          Constraints Engine and AutoScheduler tests
docker-compose.yml, Dockerfile, Makefile, docker/
```

## Assignment constraints

`app/Services/ConstraintsEngine.php::validate()` checks an assignment and
returns a `ConstraintCheckResult` with one of three statuses:

1. **Permission** — the employee is allowed to work the selected event type
   (`user_event_type_permissions`).
2. **Blocking-event exclusivity** — the assignment does not conflict with an
   `is_blocking` event. Blocking conflicts are checked in both directions.
3. **Overlapping work events** — an employee cannot be assigned overlapping
   work-time events, even when neither event type is marked as blocking.
4. **Capacity** — the event type's `max_capacity` is not exceeded.
5. **Duty limits** — the employee's duty days within each period window do
   not exceed the limits of their employee groups (see
   [Duty limits](#duty-limits)). A hard limit forbids the assignment; a soft
   limit adds a warning.
6. **Rest period** — rest before and after the assignment is checked against
   the neighboring work events:
   `optimal` (at least preferred rest), `warning` (between minimum and
   preferred rest), or `forbidden` (below the minimum). Rest rules apply to
   the period after an event: the gap between two events is checked only
   against the rules of the earlier event's type. For example, if an event
   type requires one day of rest, the next day must be free, while the day
   before it may be occupied.

The duty matrix uses this validation when an event is assigned or edited.
Assignments that produce a warning (rest or soft duty limit) are still allowed
and are saved with `has_warning = true`, which displays a warning indicator in
the matrix.

## Features

- Role-based access control with Admin, Manager, and User roles, plus
  administrator-managed custom roles and permissions.
- Event types and employee permissions, including event capacity, blocking,
  work-time, and rest-period settings.
- A monthly duty matrix with event-type colors, weekend highlighting,
  interactive assignment, rest-period highlighting, staffing deficit
  indicators, and draft events marked with stripes and a `D`.
- Period reports for workload distribution, staffing deficits, and rest
  warnings.
- Excel export with event colors and summary columns.
- Redis caching for the matrix, event types, and staffing coverage, with cache
  invalidation when assignments change.
- Automatic schedule generation for a selected date range and event types.
  The default period is a month; when launched from the matrix, its displayed
  month is preselected. The scheduler balances workload, validates candidates
  through the Constraints Engine, and creates assignments as `draft` events.
  Any unfilled slots are reported for manual review.
- Three modes for handling existing assignments during auto-generation:
  fill missing slots while preserving existing assignments; delete and
  regenerate selected drafts only; or delete and regenerate all active
  assignments of the selected event types in the period. Delete modes require
  confirmation and do not delete cancelled events.
- Managers and administrators can approve an individual draft or approve all
  drafts displayed in the current matrix month.
- Employee synergy scores that apply to a pair in both employees' profiles.
  A synergy can apply to selected event types or to all types when none are
  selected. The score is set with a three-state switch: negative (`-1`, red),
  neutral (`0`, gray), or positive (`1`, green).
- Duty limits per employee group: a maximum number of duty days per employee
  within a calendar week, a calendar month, a fixed period, or a recurring
  period, as a hard or soft limit.

## Employee synergy

Each synergy record represents an **unordered employee pair**. The score is
stored once, shown on both employees' profiles, and duplicate pair records are
not created. The score has three states: `1` (positive) increases the chance
that the employees will be assigned together, `-1` (negative) decreases it, and
`0` (neutral) has no effect. Records created before the switch was introduced
may hold other integers; the interface shows them by their sign.

Select the event types for which the score should apply. If no event types are
selected, it applies to all event types. During schedule generation, the
scheduler first keeps only the eligible employees with the lowest workload in
hours, so workload stays balanced. Among them, it calculates each candidate's
average applicable synergy with employees already assigned on that date.
Better synergy can increase the candidate's selection weight by up to 100
times, and a higher shift count for the event type lowers it. Selection is
randomized to avoid repeatedly generating the same pairs, while permission,
rest, capacity, and exclusivity checks still apply.

## Duty limits

Duty limits cap how many duty days each employee may have within a period.
They are built from three entities, each managed on its own page by
administrators and managers:

- **Employee groups** (**People and access → Employee groups**) — named sets
  of employees. An employee can belong to several groups.
- **Periods** (**Scheduling rules → Periods**) — the built-in *calendar week*
  (Monday to Sunday) and *calendar month* are always available and read-only.
  You can add a *fixed period* (a date range) or a *recurring period* (for
  example, 7 days every 14 days from a start date). A period used by a limit
  cannot be deleted.
- **Duty limits** (**Scheduling rules → Duty limits**) — a period, employee
  groups, the maximum number of duty days per employee, and what happens when
  it is exceeded: forbid the assignment or allow it with a warning. If no
  event types are selected, all working event types are counted. Groups and
  periods can also be created directly from the limit form.

The limit applies to each employee of the selected groups separately, in each
window of the period. Every applicable limit is checked, so the strictest one
wins. An event counts as many days as it lasts, starting from its start day; a
multi-day event is split between windows. In hours mode, a 24-hour shift
counts for the day it starts.

Limits are checked by the Constraints Engine, so they apply both to manual
assignment and to auto-generation. The matrix also marks existing events that
exceed a limit with ⚠️, including events created before the limit; the reason
is shown in the tooltip.

New period types are added by implementing `App\Periods\PeriodType` and
registering the class in `App\Periods\PeriodTypes`; no migration is needed.

## Time unit: days or hours

By default, the interface works in whole days: events are assigned by date,
and rest periods and shift durations are entered in days. Every event starts
at 00:00 and ends at 00:00 of the day after its last day, so the end is
exclusive. A one-day duty on September 1 is stored as
`2026-09-01 00:00 – 2026-09-02 00:00`.

Values are still stored in hours (`min_rest_hours`, `preferred_rest_hours`,
`default_duration_hours`, `starts_at`/`ends_at`), and `App\Support\TimeUnit`
converts them for the interface. To switch the interface back to hours, set
the following in `.env` and clear the configuration cache:

```bash
SCHEDULE_TIME_UNIT=hours
php artisan config:clear
```

The `2026_10_29_000000_align_scheduling_data_to_days` migration aligns
existing data to whole days: rest below 24 hours becomes 0, other rest values
and shift durations are rounded up to whole days, and events are moved to
start at 00:00 and end at the next midnight. This change is lossy and is not
reverted by a rollback.

## Interface language

The application supports English and Ukrainian. The selected locale is stored
globally in the `app_settings` table and is applied to the admin panel,
including its login page. Only users with the `Admin` role can change it:
open **Settings → Application settings**, select a language, and save. The
selection applies to subsequent requests for all users.

English source strings are in `lang/en.json`, and Ukrainian translations are
in `lang/uk.json`. After updating an existing installation, apply migrations:

```bash
php artisan migrate
```

For a new environment, `make init` runs the migrations as part of setup.

## Docker services and commands

Docker Compose runs the application, nginx web server, PostgreSQL 16, Redis,
and a queue worker. The Makefile provides project setup and maintenance
commands.

## Troubleshooting

```bash
make logs-app
make shell
docker compose exec app php artisan about
docker compose exec app composer show filament/filament
```

If the application fails during setup, check the installed `filament/filament`
version. If Composer installs a newer major version with an incompatible API,
constrain `filament/filament` in `composer.json` to a compatible 3.x release.
