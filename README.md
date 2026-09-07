# Duty Matrix

Модуль графіків чергувань, обліку відсутностей та кадрового планування.
Laravel 11 + Filament v3 (TALL) + PostgreSQL 16 + Redis 7, у Docker.

## Запуск (потрібен встановлений Docker + Docker Compose)

```bash
cp .env.example .env
make init
```

Команда `make init` виконає: збірку образів → підняття контейнерів →
`composer install` → генерацію `APP_KEY` → міграції → сидинг → публікацію
ассетів Filament → `storage:link`.

Після завершення відкрийте: **http://localhost:8080/admin**

### Тестові облікові записи (створює `UserSeeder`)

| Роль | Email | Пароль |
|---|---|---|
| Admin | admin@system.local | password |
| Manager | manager@system.local | password |
| User (6 співробітників) | employee1@system.local ... employee6@system.local | password |

## Структура проєкту

```
app/
  Models/            User, EventType, UserEvent, UserEventTypePermission
  Services/
    ConstraintsEngine.php      ← основна бізнес-логіка валідації (ТЗ п.4)
    ConstraintCheckResult.php  ← DTO результату (optimal/warning/forbidden)
  Policies/          RBAC-політики під Filament
  Filament/
    Resources/       EventTypeResource, UserResource, RoleResource (матриця прав)
    Pages/
      DutyMatrix.php            ← сторінка «Матриця чергувань» (ТЗ п.5)
      PeriodStatistics.php      ← аналітичні звіти (ТЗ п.6.2)
  Exports/
    ScheduleExport.php  ← експорт .xlsx зі стилями (ТЗ п.7)
database/
  migrations/        event_types, user_events, user_event_type_permissions, spatie-permission
  seeders/           RoleAndPermissionSeeder, UserSeeder, EventTypeSeeder
resources/views/filament/pages/
  duty-matrix.blade.php         ← сітка матриці, sticky-колонка, Alpine hover-підсвічування
  period-statistics.blade.php
tests/Unit/ConstraintsEngineTest.php  ← Pest-тести на всі 4 рівні Constraints Engine
docker-compose.yml, Dockerfile, Makefile, docker/
```

## Як влаштована логіка обмежень (ключова частина ТЗ)

`app/Services/ConstraintsEngine.php::validate()` виконує 4 перевірки по черзі
й повертає `ConstraintCheckResult` з одним із трьох статусів:

1. **checkPermission** — чи є запис у `user_event_type_permissions`
2. **checkBlockingExclusivity** — чи не конфліктує з `is_blocking`-подією (в обидва боки)
3. **checkCapacity** — чи не перевищено `max_capacity` на дату
4. **evaluateRestPeriod** — рахує відпочинок і ДО, і ПІСЛЯ нової події
   відносно сусідніх подій співробітника, обирає найгірший статус:
   `optimal` (≥ preferred) / `warning` (∈ [min, preferred)) / `forbidden` (< min)

Викликається з `DutyMatrix::createEvent()` при кожному швидкому призначенні
через модальне вікно. При `warning` подія все одно створюється, але з
`has_rest_warning = true` — саме це поле малює ⚠️ у клітинці матриці.

## Що вже працює

- Повна RBAC-модель через Spatie Permission: Admin / Manager / User +
  довільні кастомні ролі з гнучкою матрицею прав (`RoleResource`, лише для Admin)
- Уніфікована модель `user_events` + `event_types` як описано в ТЗ
- Constraints Engine з усіма 4 рівнями перевірки і покриттям тестами
- Матриця чергувань: sticky-колонка, вихідні, кольори типів подій,
  клік по клітинці → модальне швидке призначення, тумблери підсвічування,
  рядок «Непризначено ⚠️» з дефіцитом по `min_capacity`
- Rest zone highlighting при наведенні (Alpine.js, client-side)
- Сторінка звітів: розподіл навантаження, кадровий дефіцит, % винятків
- Excel-експорт з кольорами клітинок і підсумковими колонками
- Redis-кеш для матриці, довідника типів подій і покриття (з інвалідацією при записі)
- **Автогенерація графіка наперед** (`app/Services/AutoScheduler.php` +
  сторінка «Автогенерація графіка»): за обраний період (типово — місяць)
  і обрані типи подій (типово — ті, де `min_capacity > 0`) жадібний
  алгоритм зі справедливим балансуванням навантаження розставляє
  співробітників по днях, пропускаючи кожного кандидата через
  `ConstraintsEngine`. Створює лише `draft`-події; дні, які не вдалось
  заповнити (немає допущеного і водночас "прохідного" по обмеженнях
  співробітника), потрапляють у список дефіциту для ручного розбору.
  Покрито тестами (`tests/Unit/AutoSchedulerTest.php`).
- Docker Compose: app / webserver (nginx) / db (Postgres 16) / redis / worker (queue)
- Makefile з усіма командами з ТЗ п.8.3

## Що варто зробити першим (реалістичний roadmap)

1. **Прогнати `make init` і виправити дрібні неточності складання.**
   Найімовірніші місця: точні назви методів Filament v3 API (деякі методи типу
   `->after('starts_at')` на DateTimePicker, `extraModalFooterActions`) —
   звірте з `composer.lock`-версією `filament/filament`, яку встановить Docker.
2. **Rest zone highlighting зробити повністю серверним/точним** — зараз це
   спрощена Alpine-реалізація на hover; для продакшн-якості варто
   попередньо обчислювати зони на бекенді й віддавати як data-атрибути
   для кожної клітинки одразу (а не лише в момент наведення).
3. **Статус `approved`/затвердження графіка** — модель `status` enum вже є,
   але UI-дії "затвердити місяць" (масова зміна `draft → approved`, доступна
   Manager/Admin) ще не реалізовані — це явно згадано в ТЗ п.2.1.
4. **Автогенерація: перенести у чергу (queue worker)** — зараз рахує
   синхронно в HTTP-запиті; для великих періодів/штату (сотні людей × рік)
   варто dispatch'ити `Job`, який вже готовий інфраструктурно
   (`docker-compose worker`-контейнер уже піднятий і чекає), і показувати
   прогрес через Livewire-поллінг або database-нотифікацію по завершенню.
5. **Drag-and-drop переміщення подій** у матриці (зараз лише клік → модалка) —
   природне розширення Livewire-компонента з `wire:drag`/Alpine або SortableJS.
5. **Тести Feature-рівня** на Filament-ресурси й Policy (зараз є лише Unit-тести
   Constraints Engine).
6. **Продуктивність на 50×31**: перевірити реальний час рендеру великої матриці
   і за потреби пагінувати/лінивого підвантажувати місяці замість повного eager load.

## Якщо щось не запускається

```bash
make logs-app        # логи PHP-контейнера
make shell            # зайти всередину і подивитись помилку вручну
docker compose exec app php artisan about   # перевірити конфігурацію Laravel
docker compose exec app composer show filament/filament   # яка версія встановилась
```

Найчастіша причина падіння на першому запуску — розбіжність версій
`filament/filament`.
Якщо `composer install` підтягне новішу мажорну версію з іншим API —
звузьте версію в `composer.json` до конкретного релізу 3.x, з яким писався код.
