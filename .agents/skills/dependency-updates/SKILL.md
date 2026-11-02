---
name: dependency-updates
description: Procedure for adding, updating and auditing Composer dependencies in duty-scheduler, including security advisories, Laravel major upgrades and checks of the production image. Use when running composer require/update, reacting to composer audit, or planning a framework upgrade.
---

# Dependency updates

Composer runs in the dev container (it has internet access; the production
server does not — see `deployment`):

```bash
docker compose exec -T app composer ...
```

## Before changing dependencies

- Save the current state: `cp composer.json composer.lock /tmp/` (rollback).
- Prefer a dry run first: `composer update <packages> --with-dependencies --dry-run`.

## Adding or updating

- **Dev-only packages need `--dev`.** `composer require pestphp/pest:^3.0`
  without `--dev` moves Pest (and PHPUnit, Collision) into `require`; the
  production image then installs them, and `package:discover` fails with
  "Class NunoMaduro\Collision\...\CollisionServiceProvider not found".
- After moving a package between `require` and `require-dev` by hand,
  `composer update --lock` is **not enough**: the lock keeps it in `packages`.
  Run `composer update <those packages>` and verify:

  ```bash
  python3 -c "import json;d=json.load(open('composer.lock'));print([x['name'] for x in d['packages'] if x['name'].startswith(('pestphp','phpunit','nunomaduro/collision','mockery','laravel/pint'))])"
  ```

  The list must be empty.
- Keep `composer.json` formatting (Composer sorts entries; avoid reformatting
  unrelated parts).
- Check that only the intended packages changed: compare versions in the old
  and new lock.

## Security advisories

- `composer audit` lists advisories. Fix first within the current major
  (`composer update <pkg> --with-dependencies`).
- Composer refuses all versions affected by advisories ("not loaded, because
  they are affected by security advisories"). If every version of the current
  major is affected, the major is out of support: plan the upgrade instead of
  ignoring the advisory.
- Check which versions an advisory affects:
  `https://packagist.org/api/security-advisories/?packages[]=laravel/framework`.

## Framework upgrades

- Laravel majors get bug fixes for 18 months and security fixes for 2 years.
  Laravel 12 security support ends around February 2027; Laravel 13 is
  supported by Filament 3 (>= 3.3.57), Livewire 3, spatie/laravel-permission 6,
  spatie/laravel-activitylog 4 and maatwebsite/excel 3.1, but needs
  laravel/tinker 3 and Pest 4.
- Stay on Filament 3; Filament 4/5 means rewriting the UI.
- Read the official upgrade guide and check the codebase for each listed change.

## Verify

1. Full test suite in both time-unit modes (`running-tests`).
2. `composer audit` — no advisories.
3. Dev stack: `php artisan optimize:clear`, `docker compose restart app worker`,
   open the main pages (matrix, drafts, auto-generation, resources, activity log).
4. **Production image:** `docker build --target prod -t wfm-app:check .`, then
   confirm dev packages are absent and the container boots:

   ```bash
   docker run --rm --entrypoint php wfm-app:check -r 'require "/var/www/vendor/autoload.php"; var_dump(class_exists("Pest\\TestSuite"));'
   docker image rm wfm-app:check
   ```

5. Update the Laravel version in both READMEs when the major changes.
