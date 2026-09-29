# ============================================================
# БСТ · Дзен — Makefile для dev-окружения.
# Команды выполняются внутри контейнеров docker compose.
# Идентификаторы — английские, пояснения — русские.
# ============================================================


DC       = docker compose
EXEC     = $(DC) exec app
PHP      = $(EXEC) php
ARTISAN  = $(PHP) artisan
COMPOSER = $(EXEC) composer
MYSQL    = $(DC) exec mysql mysql -udzen -pdzen_secret dzen




.DEFAULT_GOAL := help




.PHONY: help up down restart ps logs shell setup migrate seed fresh test pint stan key tinker mysql user admin channel transfer-competitors queue-restart collect npm-install npm-build npm-dev clean




help: ## Показать список доступных целей
	@awk 'BEGIN {FS = ":.*##"} \
		/^[a-zA-Z_-]+:.*##/ { printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2 }' $(MAKEFILE_LIST)




up: ## Запуск контейнеров (со сборкой образа)
	$(DC) up -d --build




down: ## Остановка контейнеров (данные сохраняются)
	$(DC) down




restart: ## Перезапуск контейнеров приложения (app, queue, scheduler, nginx)
	$(DC) restart app queue scheduler nginx




ps: ## Статус контейнеров
	$(DC) ps




logs: ## Логи app/queue/scheduler/nginx в реальном времени
	$(DC) logs -f app queue scheduler nginx




shell: ## Shell внутри контейнера app
	$(EXEC) sh




setup: ## Полный bootstrap: env, контейнеры, зависимости, сборка, миграции
	@test -f api/.env || cp api/.env.example api/.env
	@mkdir -p api/storage/framework/cache/data api/storage/framework/sessions api/storage/framework/testing api/storage/framework/views api/storage/logs
	$(DC) up -d --build
	$(COMPOSER) install --no-interaction
	cd client && npm install --no-audit --no-fund && npm run build
	@grep -qE '^APP_KEY=.+' api/.env || $(ARTISAN) key:generate --force
	$(ARTISAN) migrate --force
	@echo "Готово: http://localhost:8090 (админ: make admin MAIL=... PASS=...)"




migrate: ## Запуск миграций БД (artisan migrate)
	$(ARTISAN) migrate --force




seed: ## Пересоздание БД с нуля + сиды (DROP всех таблиц!)
	$(ARTISAN) migrate:fresh --seed --force




fresh: ## Пересоздание БД с нуля (DROP всех таблиц!)
	$(ARTISAN) migrate:fresh --force




test: ## Запуск тестов (PHPUnit, sqlite in-memory)
	$(ARTISAN) test




pint: ## Запуск Laravel Pint (форматирование с правками)
	$(EXEC) vendor/bin/pint




stan: ## Запуск PHPStan/Larastan (статический анализ, level 5)
	$(EXEC) vendor/bin/phpstan analyse --memory-limit=2G




key: ## Генерация APP_KEY в .env (если ещё не задан)
	@grep -qE '^APP_KEY=.+' api/.env || $(ARTISAN) key:generate --force




tinker: ## Интерактивная консоль Laravel (tinker)
	$(ARTISAN) tinker




mysql: ## mysql-клиент внутри контейнера mysql (БД dzen)
	$(MYSQL)




user: ## Создать/обновить пользователя: make user MAIL=a@b [PASS=...] [CHANNELS=1,2]
	@test -n "$(MAIL)" || { echo "Укажите MAIL=user@example.com (опционально PASS=..., CHANNELS=1,2)"; exit 1; }
	$(ARTISAN) dzen:user $(MAIL) $(if $(PASS),--password='$(PASS)') $(if $(CHANNELS),--channels='$(CHANNELS)')




channel: ## Добавить канал Дзена: make channel KEY=<имя|24hex> [TITLE=] [TZ=] [OWN=1] [COMPETITORS_OF=<ownId>]
	@test -n "$(KEY)" || { echo "Укажите KEY=<имя из url или 24-hex id> (опц. TITLE=, TZ=, OWN=1, COMPETITORS_OF=<id own-канала>)"; exit 1; }
	$(ARTISAN) dzen:channel $(KEY) $(if $(TITLE),--title='$(TITLE)') $(if $(TZ),--tz='$(TZ)') $(if $(OWN),--own) $(if $(COMPETITORS_OF),--competitors-of='$(COMPETITORS_OF)')



transfer-competitors: ## Перенос всех конкурентов между own-каналами: make transfer-competitors FROM=<oldId> TO=<newId>
	@test -n "$(FROM)" && test -n "$(TO)" || { echo "Укажите FROM=<старый ownId> TO=<новый ownId>"; exit 1; }
	$(ARTISAN) dzen:transfer-competitors $(FROM) $(TO)




admin: ## Создать/обновить администратора: make admin MAIL=a@b [PASS=...]
	@test -n "$(MAIL)" || { echo "Укажите MAIL=admin@example.com (опционально PASS=...)"; exit 1; }
	$(ARTISAN) dzen:user $(MAIL) --admin $(if $(PASS),--password='$(PASS)')




queue-restart: ## Перезапуск воркеров очереди (после изменений кода jobs)
	$(DC) restart queue




collect: ## Ручной сбор Дзена (dzen:collect --sync); опц. CHANNEL=<id|key> DAYS=<N>
	$(ARTISAN) dzen:collect --sync --days=$(if $(DAYS),$(DAYS),21) $(if $(CHANNEL),--channel='$(CHANNEL)')




npm-install: ## npm install фронтенда (на хосте, каталог client)
	cd client && npm install --no-audit --no-fund




npm-build: ## Прод-сборка фронтенда (vite build, на хосте)
	cd client && npm run build




npm-dev: ## Vite dev-сервер с HMR (localhost:5173, на хосте)
	cd client && npm run dev




clean: ## Остановка + УДАЛЕНИЕ volumes (БД и кэши!)
	$(DC) down -v
