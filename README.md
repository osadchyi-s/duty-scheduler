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
                     Synergy, AppSetting
  Services/
    ConstraintsEngine.php      Core assignment validation logic
    ConstraintCheckResult.php  Validation result (optimal/warning/forbidden)
    AutoScheduler.php          Automatic schedule generation
  Policies/          Filament authorization policies
  Filament/
    Resources/       Event types, employees (including synergies), roles
    Pages/
      DutyMatrix.php            Duty matrix
      AutoSchedule.php          Automatic schedule generation
      PeriodStatistics.php      Period reports and statistics
      AppSettings.php           Administrator settings, including locale
  Exports/
    ScheduleExport.php          Styled .xlsx export
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
5. **Rest period** — rest before and after the assignment is checked against
   the neighboring work events:
   `optimal` (at least preferred rest), `warning` (between minimum and
   preferred rest), or `forbidden` (below the minimum).

The duty matrix uses this validation when an event is assigned or edited.
Assignments that produce a rest warning are still allowed and are saved with
`has_rest_warning = true`, which displays a warning indicator in the matrix.

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
  selected. The slider starts with `-1`, `0`, and `1`; the numeric input and
  increment/decrement controls accept any integer. Lower scores are red and
  higher scores are green.

## Employee synergy

Each synergy record represents an **unordered employee pair**. The score is
stored once, shown on both employees' profiles, and duplicate pair records are
not created. A score of `0` is neutral; positive scores increase the chance
that the employees will be assigned together, while negative scores decrease
it.

Select the event types for which the score should apply. If no event types are
selected, it applies to all event types. During schedule generation, the
scheduler calculates the candidate's average applicable synergy with
employees already assigned on that date. Better synergy can increase the
candidate's selection weight by up to 100 times. Workload in hours and shift
count also affect the weight to maintain a fair distribution. Selection is
randomized to avoid repeatedly generating the same pairs, while permission,
rest, capacity, and exclusivity checks still apply.

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
