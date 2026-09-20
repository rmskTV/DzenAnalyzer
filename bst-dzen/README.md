# БСТ · Дзен — система продвижения канала

Laravel API + Vue SPA + MySQL + Docker. Серийный сбор и анализ Дзен-каналов,
контент-конвейер и обратная связь (в разработке, фазы 4–6).

## Запуск

```bash
cd bst-dzen
docker compose up -d --build
docker compose exec app php artisan migrate --force
docker compose exec app php artisan dzen:import-history   # разовый импорт истории прототипа
docker compose exec app php artisan dzen:collect --sync --days=2   # разовый/ручной сбор
```

- UI и API: http://localhost:8090 (SPA) · http://localhost:8090/api/health
- Крон-планировщик работает в контейнере `scheduler` (dzen:collect 22:00 UTC = 06:00 Ирк,
  dzen:ingest 22:10 UTC), очередь — в контейнере `queue`.

## Артекоманды

| Команда | Назначение |
|---|---|
| `dzen:collect [--days=2] [--sync]` | сбор постов всех активных каналов + снапшоты просмотров/комментариев |
| `dzen:ingest` | забор материалов с внешних парсеров (настройка `parsers` в settings) |
| `dzen:classify [--days=3]` | рубрики/тип контента новых постов (LLM или keyword-эвристика) |
| `dzen:generate [--limit=N]` | evergreen-сетка дня + рерайты новых материалов (нужен LLM) |
| `dzen:publish` | постановка одобренных публикаций в RSS-очередь по режимам |
| `dzen:track` | связка наших публикаций с постами Дзена, расчёт vpd-результатов |
| `dzen:rules` | предложение новой версии правил рерайта по статистике (вс) |
| `dzen:import-history` | разовый импорт из CSV python-прототипа |

## API

`GET /api/health` · `GET /api/dashboard?days=21&own=<id>` · `GET /api/channels` ·
`PUT /api/channels/{id}` (флаги, конкурентный набор) · `GET /api/channels/{id}/posts|stats` ·
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
- Секреты: `api/.env` — `LLM_BASE_URL`, `LLM_API_KEY`, `LLM_MODEL_DEFAULT`.
