---
name: deployment
description: How duty-scheduler is built and deployed - Dockerfile targets, compose.prod.yml, deploy/deploy.sh for production and staging on one server, environment files, and the air-gapped target infrastructure. Use when touching Docker files, deployment scripts, env examples, or when a change needs something at runtime on the server (new service, env variable, scheduled task, queue).
---

# Build and deployment

## Images (`Dockerfile`)

One Dockerfile, several targets:

- `dev` — local development; code is mounted from the host (`docker-compose.yml`).
- `prod` — php-fpm with the code and `composer install --no-dev`, runs as
  `www-data`; used for both the `app` and the queue `worker` containers.
- `web` — nginx serving `public/` and proxying PHP to `app:9000`.

`.dockerignore` keeps secrets (`.env`, `deploy/*.env`), `vendor`, tests and
`bootstrap/cache/*.php` (it lists dev packages) out of images. The prod
entrypoint requires `APP_KEY` and builds caches at start (`php artisan optimize`,
`filament:optimize`), because they depend on environment variables.

## Production and staging on one server

- `compose.prod.yml` describes one environment: `app`, `worker`, `web`
  (the only published port, `APP_PORT`), `db`, `redis`. No `container_name`:
  each environment is its own Compose project (`wfm_prod`, `wfm_staging`) with
  its own network and volumes.
- Settings: `deploy/prod.env` and `deploy/staging.env` (not committed; examples
  `deploy/*.env.example`). Staging and production share the server IP and
  differ by port, so **`SESSION_COOKIE` must differ** (browsers do not separate
  cookies by port). `APP_ENV=staging` shows an amber banner and color scheme.
- `deploy/deploy.sh`: `staging [tag]` builds from the current commit and
  deploys; `prod <tag>` promotes an already tested tag (backs up the database
  first); `<env> create-admin|backup|import|status|logs|down|artisan`.
  Migrations run as a separate step before new containers start.
- The demo `UserSeeder` (password `password`) must never run in production;
  `wfm:create-admin` creates the first administrator.

## Runtime needs of new features

- Queue: the `worker` container runs `queue:work redis`. Jobs must be safe to
  run in a long-lived process; services that cache state should be bound as
  `scoped`, not `singleton` (see `ConstraintsEngine`).
- There is **no scheduler container**. Prefer event-driven solutions (e.g.
  the activity log pruning job dispatched at most once a day). If a real cron
  task appears, add a `scheduler` service running `php artisan schedule:work`
  to `compose.prod.yml` and document it.
- New environment variables go into both `deploy/*.env.example`, `.env.example`
  when relevant, and the README.

## Target infrastructure (agreed plan, not implemented yet)

- Production and staging run on one server **without internet access**.
- A local GitLab with a container registry and a Jenkins server, also without
  internet; Jenkins can reach GitLab.
- The developer's machine is the **only bridge** to the internet: anything
  needing internet (Docker Hub images, apt/pecl, `composer install`) must run
  there. Plan: the dev machine pushes code to a `gitlab` remote and pushes base
  and dependency images (dependencies tagged by the `composer.lock` hash) and
  mirrored third-party images to the GitLab registry; Jenkins builds app/web
  images offline from them, runs tests, pushes, deploys to staging over SSH with
  `deploy/deploy.sh` (pull instead of build); production is a manual Jenkins
  promotion of the same tag.

Do not add build steps that download from the internet on the server or in Jenkins.

## Verifying deployment changes locally

Deploy a throwaway staging on a free port with its own env file, check
`/up`, the login page, the staging banner and migrations, then remove it
completely (`docker compose -p wfm_staging -f compose.prod.yml ... down -v`,
`docker image rm`, delete the temporary env file). Never touch the dev project
`duty-scheduler` or its volumes while doing this.
