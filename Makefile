.DEFAULT_GOAL := help

init: build up composer-install key migrate seed filament-assets storage-link ## Повний запуск проєкту з нуля
	@echo "✅ WFM Duty Matrix готовий: http://localhost:8080/admin"
	@echo "   Admin:   admin@system.local / password"
	@echo "   Manager: manager@system.local / password"

filament-assets: ## Публікація статичних ассетів Filament (CSS/JS)
	docker compose exec app php artisan filament:assets

storage-link: ## Символьне посилання storage -> public
	docker compose exec app php artisan storage:link

build: ## Збірка Docker-образів
	docker compose build

up: ## Запуск контейнерів
	docker compose up -d

down: ## Зупинка контейнерів
	docker compose down

restart: down up ## Перезапуск контейнерів

composer-install: ## Встановлення PHP-залежностей
	docker compose exec -e COMPOSER_MEMORY_LIMIT=-1 app composer install

key: ## Генерація APP_KEY
	docker compose exec app php artisan key:generate

migrate: ## Запуск міграцій БД
	docker compose exec app php artisan migrate --force

migrate-fresh: ## Перестворення БД з нуля + seed
	docker compose exec app php artisan migrate:fresh --seed --force

seed: ## Запуск сидингу первинних даних
	docker compose exec app php artisan db:seed --force

shell: ## Вхід у консоль app-контейнера
	docker compose exec app bash

shell-db: ## Вхід у psql
	docker compose exec db psql -U $${DB_USERNAME:-wfm} -d $${DB_DATABASE:-wfm_duty_matrix}

logs: ## Перегляд логів усіх сервісів
	docker compose logs -f

logs-app: ## Перегляд логів app-контейнера
	docker compose logs -f app

test: ## Запуск тестів (Pest / PHPUnit)
	docker compose exec app php artisan test

cache-clear: ## Очистка кешу Laravel/Redis
	docker compose exec app php artisan optimize:clear

queue-work: ## Запуск воркера черг вручну
	docker compose exec app php artisan queue:work redis

help: ## Показати список команд
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-18s\033[0m %s\n", $$1, $$2}'

.PHONY: init build up down restart composer-install key migrate migrate-fresh seed filament-assets storage-link shell shell-db logs logs-app test cache-clear queue-work help
