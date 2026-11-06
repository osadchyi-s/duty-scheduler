---
name: database-changes
description: Safe procedure for migrations and data changes in duty-scheduler - backups of the dev database, verifying migrations on a PostgreSQL copy, data migrations, PostgreSQL vs SQLite differences. Use when adding or changing migrations, running migrate on the dev stack, or changing stored data.
---

# Database changes

The developer's local PostgreSQL (`duty-scheduler_wfm_pg_data` volume) holds
real working data. Treat it as valuable.

## Before touching the dev database

1. **Back up** to a directory outside the project:

   ```bash
   U=$(grep ^DB_USERNAME .env | cut -d= -f2); D=$(grep ^DB_DATABASE .env | cut -d= -f2)
   docker compose exec -T db pg_dump -U "$U" "$D" > ~/duty-scheduler-backups/before-<change>-$(date +%Y%m%d-%H%M%S).sql
   ```

2. Note counts you can compare afterwards (events, drafts, users).
3. **Ask the user** before running a migration that changes or removes data.
   Purely additive migrations (new tables/columns) can be applied after a backup.
4. Never run `docker compose down -v`, `docker volume prune`, `make migrate-fresh`
   or seeders (`make init`, `make seed`) on the dev database: seeders reset
   event types and role permissions with `updateOrCreate`/`syncPermissions`.

## Verify a migration on a copy first

```bash
docker compose exec -T db psql -U "$U" -d postgres -c 'CREATE DATABASE wfm_check'
docker compose exec -T db sh -c "pg_dump -U $U $D | psql -q -U $U wfm_check"
docker compose exec -T -e DB_DATABASE=wfm_check app php artisan migrate --force
# inspect the result with psql, then:
docker compose exec -T db psql -U "$U" -d postgres -c 'DROP DATABASE wfm_check'
```

Tests run on SQLite, so this PostgreSQL check is the only proof that a
migration works in production.

## Writing migrations

- Comments explain *why* the migration exists, in English.
- **Data migrations** may be lossy (e.g. aligning events to whole days); then
  `down()` does nothing and the class comment says so.
- PostgreSQL enum columns created by Laravel carry a check constraint
  (`user_events_status_check`); drop and recreate it explicitly around `->change()`.
- On SQLite, `->change()` rebuilds the table and nulls self-referencing
  `ON DELETE SET NULL` keys; change columns **before** copying data.
- Use the query builder (`DB::table`) in data migrations, not models: models
  change over time, and model events would write activity log entries.
- Keep foreign keys explicit about deletion: history tables use `RESTRICT`
  (`user_events.user_id`, `event_type_id`), draft events `CASCADE` with the draft.
- Add indexes for columns used in filters and sorting (e.g. `activity_log.created_at`).
- Files published by `vendor:publish` inside the container are root-owned;
  `chown` them to the host user before editing.

## After migrating

Clear caches (`php artisan optimize:clear`), restart the worker, compare the
counts noted before, and open the affected pages.
