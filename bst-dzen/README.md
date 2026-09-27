# БСТ · Дзен — система продвижения канала

Laravel API + Vue SPA + MySQL + Docker. Серийный сбор и анализ Дзен-каналов,
контент-конвейер и обратная связь (в разработке, фазы 4–6).

## Запуск

```bash
cd bst-dzen
make setup          # env + контейнеры + зависимости + миграции (список целей: make help)
# или вручную:
docker compose up -d --build
docker compose exec app php artisan migrate --force
docker compose exec app php artisan dzen:import-history   # разовый импорт истории прототипа
docker compose exec app php artisan dzen:collect --sync --days=21   # разовый/ручной сбор

make admin MAIL=admin@example.com PASS=...   # создать админа
make user MAIL=analyst@example.com CHANNELS=1,3   # аналитик: только «Конкуренты» этих каналов
```

- UI и API: http://localhost:8090 (SPA) · http://localhost:8090/api/health
- Крон-планировщик работает в контейнере `scheduler` (dzen:collect 22:00 UTC = 06:00 Ирк,
  dzen:ingest 22:10 UTC), очередь — в контейнере `queue`.

## Артекоманды

| Команда | Назначение |
|---|---|
| `dzen:collect [--days=21] [--sync]` | сбор постов всех активных каналов + снапшоты просмотров/комментариев постов и подписчиков каналов |
| `dzen:ingest` | забор материалов с внешних парсеров (настройка `parsers` в settings) |
| `dzen:classify [--days=21]` | рубрики/тип контента новых постов (LLM или keyword-эвристика) |
| `dzen:generate [--limit=N]` | evergreen-сетка дня + рерайты новых материалов (нужен LLM) |
| `dzen:publish` | постановка одобренных публикаций в RSS-очередь по режимам |
| `dzen:track` | связка наших публикаций с постами Дзена, фиксация просмотров созревших постов (16 ч+) |
| `dzen:rules` | предложение новой версии правил рерайта по статистике (вс) |
| `dzen:import-history` | разовый импорт из CSV python-прототипа |
| `dzen:channel {key} [--own] [--title=] [--tz=] [--competitors-of=<id>]` | добавить/обновить канал Дзена (key = имя из URL или 24-hex id); данные подтянет ближайший `dzen:collect` |
| `dzen:user {email} [--admin] [--password=] [--channels=1,2]` | создать/обновить пользователя (админ или аналитик с доступом к own-каналам) |

## API

Аутентификация — Sanctum SPA (cookie + CSRF): `POST /api/auth/login` · `POST /api/auth/logout` ·
`GET /api/auth/me`; перед логином — `GET /sanctum/csrf-cookie`.
Обычному пользователю доступна только аналитика назначенных ему own-каналов и их конкурентов
(вкладка «Конкуренты»); управление (каналы/черновики/правила/настройки) — только админам.
Публичные: `GET /api/health`, `GET /feed/{dzen_key}.xml`.

`GET /api/dashboard?days=21&own=<id>` · `GET /api/channels` ·
`PUT /api/channels/{id}` (флаги, конкурентный набор) · `GET /api/channels/{id}/posts|posts/filters|stats` ·
`GET|PUT /api/drafts[/{id}]` + `POST /api/drafts/{id}/approve|reject` ·
`GET /api/rules` + `POST /api/rules/{id}/activate` · `GET|PUT /api/settings` ·
`GET /feed/{dzen_key}.xml` — публичный RSS для студии Дзена

## Конвейер

```
22:00 UTC  collect   — сбор Дзена (06:00 Иркутск)
22:10 UTC  ingest    — материалы парсеров
22:15 UTC  classify  — рубрики
22:20 UTC  generate  — evergreen + рерайты (Bothub LLM)
23:00 UTC  publish   — одобренные → RSS-фид (режимы: publish/draft/hold)
23:30 UTC  track     — обратная связь: результаты публикаций
вс 01:00   rules     — предложение новых правил рерайта (активация вручную в UI)
```

## Конфигурация

- Роли каналов: `channels.is_own` (мой) / конкурентный набор `channel_competitors`;
  управляются из UI «Каналы» (или PUT /api/channels/{id}).
- Парсеры: UI «Настройки» → секция «Парсеры» (JSON-массив `[{name,url,active}]`).
- Модели LLM по операциям: UI «Настройки» → «Модели LLM».
- Режимы публикации по типам контента: UI «Настройки» → «Публикация».
- Секреты: `api/.env` — `LLM_BASE_URL`, `LLM_API_KEY`, `LLM_MODEL_DEFAULT`;
  `SANCTUM_STATEFUL_DOMAINS` — домены SPA для cookie-аутентификации (localhost + прод-домен).

## Деплой на сервер

1. `git clone` … → `cd bst-dzen && make setup` (нужны docker, compose, node на сервере;
   либо собрать `client/dist` локально и скопировать).
2. В `api/.env` обязательно: `APP_URL=https://<домен>`, добавить домен в
   `SANCTUM_STATEFUL_DOMAINS` (иначе после логина API вернёт 401),
   `APP_ENV=production`, `APP_DEBUG=false`, ключи `LLM_*`.
3. `make admin MAIL=... PASS=...` — первый админ; аналитики: `make user MAIL=... CHANNELS=<id own-каналов>`.
4. Крон не нужен: расписание крутит контейнер `scheduler` (`schedule:work`),
   очередь — контейнер `queue`. Обновление кода: `git pull && make up && make migrate`.
