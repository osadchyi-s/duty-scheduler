---
name: project-overview
description: Map and working conventions of the duty-scheduler (WFM Duty Matrix) project. Use at the start of any task in this repository, before changing code, to find where things live and which project rules apply (languages, comments, commits, Docker workflow).
---

# Duty scheduler: project overview

Laravel 12 + Filament 3 (TALL) admin panel for duty scheduling. PostgreSQL 16,
Redis 7, everything runs in Docker. The UI language is Ukrainian by default
(English available); code, comments and identifiers are English.

## Where things are

| Area | Location |
|---|---|
| Duty matrix (main page, tabs for drafts) | `app/Filament/Pages/DutyMatrix.php`, `resources/views/filament/pages/duty-matrix.blade.php` |
| Assignment rules (rest, overlaps, capacity, limits, inactive users) | `app/Services/ConstraintsEngine.php`, `app/Services/DutyLimitChecker.php` |
| Auto-generation | `app/Services/AutoScheduler.php`, page `app/Filament/Pages/AutoSchedule.php` |
| Schedule drafts (create, duplicate, approve) | `app/Services/ScheduleDraftService.php`, `app/Models/ScheduleDraft.php` |
| Period types for duty limits | `app/Periods/` (registry `PeriodTypes`) |
| Days/hours conversion | `app/Support/TimeUnit.php`, `config/scheduling.php` |
| Matrix cache keys | `app/Support/MatrixCache.php` |
| Activity log helpers | `app/Support/AuditLog.php`, `app/Models/Concerns/RecordsActivity.php` |
| Resources (users, event types, groups, periods, limits, roles, activity log) | `app/Filament/Resources/` |
| Translations | `lang/en.json` (source strings), `lang/uk.json` |
| Docs | `README.md` (English) and `READMEua.md` (Ukrainian) — keep both in sync |
| Production/staging | `Dockerfile` (targets `dev`, `prod`, `web`), `compose.prod.yml`, `deploy/` |

Ignore `vendor/`, `storage/`, `bootstrap/cache/`, `public/build/` and generated assets.

## Rules for every change

- **Comments in English**, in the style of the surrounding code: explain *why*,
  not what. Do not add comments that restate the code.
- **Every UI string goes through `__()`** and is added to both `lang/en.json`
  and `lang/uk.json` (see the `localization` skill).
- **Documentation changes go into both READMEs.**
- **Tests:** add or update Pest tests next to the change and run the whole
  suite in both time-unit modes (see the `running-tests` skill).
- **No Tailwind build step:** many Tailwind utilities do not exist in the
  prebuilt Filament CSS (see the `filament-ui-styling` skill).
- **Do not commit, push or run destructive commands on the dev database
  without the user's explicit request.** Back up before migrations (see
  `database-changes`).
- **Commit messages** follow the repository style: one short English line,
  past tense, e.g. `Added rolling period type for duty limits`. Suggest a
  title when asked; do not commit on your own.

## Docker workflow

The development stack is `docker-compose.yml` (project name `duty-scheduler`,
containers `wfm_app`, `wfm_db`, `wfm_redis`, `wfm_webserver`, `wfm_worker`).
PHP, Composer and Artisan run inside the `app` container:

```bash
docker compose exec -T app php artisan test
docker compose exec -T app php artisan tinker --execute '...'
docker compose exec -T app composer ...
```

There is no PHP on the host. Files created inside the container (for example
by `vendor:publish`) are owned by root on the host; fix with
`docker compose exec -T app chown "$(id -u):$(id -g)" <files>`.

After code changes that the queue worker uses, restart it:
`docker compose restart worker`.

## Related skills

`scheduling-domain` (business rules), `running-tests`, `localization`,
`filament-ui-styling`, `database-changes`, `activity-log`,
`dependency-updates`, `deployment`.
