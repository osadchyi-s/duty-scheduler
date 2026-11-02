---
name: running-tests
description: How to run and write tests for duty-scheduler (Pest 3 on SQLite in memory, inside Docker), including both time-unit modes and known testing pitfalls. Use whenever you change PHP code, Blade views or migrations, or need to verify behaviour.
---

# Running and writing tests

## Run

```bash
docker compose exec -T app php artisan test                                   # days mode (default)
docker compose exec -T -e SCHEDULE_TIME_UNIT=hours app php artisan test       # hours mode
docker compose exec -T app php artisan test --filter=DutyLimitCheckerTest     # one file or test name
```

Always run the **full suite in both modes** before reporting a change as done;
the time unit changes event boundaries, rest calculation and form fields.
Report the real result (number of passed/failed tests), not an assumption.

Tests use SQLite in memory (`phpunit.xml`), `RefreshDatabase` for `Unit` and
`Feature` (see `tests/Pest.php`), array cache and sync queue.

## Writing tests

- Feature tests for Filament pages use Livewire:
  `Filament::setCurrentPanel(Filament::getPanel('admin'))`, `actingAs(...)`,
  then `Livewire::test(Page::class)->mountAction(...)->setActionData(...)->callMountedAction()`.
- Fix "today" for date-dependent behaviour with `$this->travelTo(...)`.
- Set the time unit per test with `config(['scheduling.time_unit' => 'days'])`.
- The default locale in tests is `uk`: assertions on UI texts use Ukrainian strings.
- Prefer behavioural assertions (database state, rendered HTML) over
  implementation details. Count CSS classes in rendered HTML with an exact
  match, e.g. `/class="[^"]*(?<![\w-])dm-deficit(?![\w-])[^"]*"/`, because
  `dm-deficit` is also a prefix of `dm-deficit-head`.
- To prove a regression test is meaningful, run it against the old code
  (`git stash push <file>`), see it fail, then `git stash pop`.

## Known pitfalls

- **Switching users inside one test session** returns 302 instead of the
  expected status: `AuthenticateSession` signs the second user out. Use a
  separate test, or `$this->flushSession(); app('auth')->forgetGuards();`
  before `actingAs($other)`.
- **Scoped services keep state within a test:** `ConstraintsEngine` is a
  scoped binding and caches duty limits. After changing limits in the same
  test, call `app()->forgetScopedInstances()`.
- **New models get database defaults only after a refresh.** `User` declares
  `protected $attributes = ['is_active' => true]` for this reason; do the same
  for other columns whose default matters before `refresh()`.
- **SQLite vs PostgreSQL:** `->change()` rebuilds the table on SQLite, and a
  self-referencing `ON DELETE SET NULL` foreign key (`user_events.source_event_id`)
  is nulled during the rebuild. Change columns before copying data in a
  migration. Production runs PostgreSQL; verify migrations there too (see
  `database-changes`).
- A helper function named like a Laravel helper (`event()`) cannot be declared
  in a test file; give test helpers unique names.
- Weighted random selection in `AutoScheduler` makes some assertions flaky if
  they depend on chance; assert invariants (fairness bounds, limits) instead,
  and repeat a suspicious test 10–20 times before trusting it.
