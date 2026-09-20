# DzenAnalyzer: состояние на 20.09.2026 (передача в новую сессию)

## Состав репозитория (ничего не закоммичено — всё в untracked!)

| Путь | Что это |
|---|---|
| `*.py`, `report.md`, `report_v2.md`, `charts/`, `data/*.csv` | **Python-прототип**: краулер, аналитика, отчёты. Завершён, послужил источником логики и данных импорта |
| `1.json…3.json` | исходные дампы API от пользователя |
| `bst-dzen/` | **Продакшн-система**: Laravel 13 API + Vue 3 SPA + MySQL 8 + Docker |
| `.venv/` | окружение для python-графиков (matplotlib) |

## Что сделано в bst-dzen

### Инфраструктура
docker-compose: nginx (порт **8090**), app (php-fpm 8.4, Laravel 13), queue, scheduler, mysql:8 — всё работает. `../data` смонтирован в app read-only.

### Сбор
- `DzenApiClient` — оба режима (`channel_name` / `channel_id`+tab), без сессий и CSRF (GET не проверяет токены)
- `DzenCrawler` — синтетический курсор «из будущего», floor-контейнеры (`channel_*_floor`), пауза 0.7 с
- job `CrawlChannel` (queue), `dzen:collect [--days=2] [--sync]` → посты + ежедневные снапшоты views/comments
- `dzen:ingest` — забор материалов у парсеров (settings-ключ `parsers`; формат `[{external_id,url,title,text,published_at}]`)

### Классификация
- Реестр **rubrics** (10 системных + создаваемые LLM через `NEW`+`new_rubric` с дедупом по имени; созданы: **Здоровье** n=78, **Образование** n=48)
- Реестр **formats** — 11, расширение только администратором; `is_evergreen` у Подборки/Listicle, Гайда/Инструкции, Народного календаря
- Промпт: определения рубрик, правила коллизий, форматы строго из списка; галлюцинация имени → keyword-фолбэк (не null)
- `dzen:classify [--days=3] [--force]` (батчи по 20, JSON-режим). Всё окно переклассифицировано: **0 постов без рубрики**

### Аналитика
- `MetricsService` → `GET /api/competitive?own=<id>&days=21`: скоуп = own + его конкуренты; benchmark, `length`/`length_set` (интервалы времени чтения, без отбрасываний), `formats` (n + медиана просмотров по форматам), `publish_grid` (7×24 в таймзоне канала), `dynamics`. Охватная метрика — сырые просмотры созревших постов (прожили в паблике ≥ 16 ч, `Post::MATURITY_HOURS`); объёмные метрики — по всем постам
- `EventClusterer` (`dzen:events [--days=21]`) — порт python-кластеризации: токенный Жаккар ≥ 0.3, окно ±48 ч, scope на own-канал; `GET /api/events?own=` — покрытие/скорость/дуэли

### Контент
- `LlmClient` → Bothub `https://openai.bothub.ru/v1`, модель `gemini-3.8-flash`; ключ в `api/.env`, модели по операциям (classify/rewrite/evergreen/rules) — в settings (UI «Настройки»)
- `Rewriter` — 3 заголовка A/B/C с разными приёмами, тело 2–4 мин, правила из активных `rule_versions`
- `EvergreenGenerator` — приметы/гороскоп по чётности дня года
- `dzen:generate` — норма из `content.daily_limit` (default 4): 1 evergreen + рерайты новых материалов

### Публикация
- `GET /feed/{dzen_key}.xml` — публичный RSS (проверен на демо-item)
- `dzen:publish` — режимы `publish/draft/hold` по `source_type` из settings `publish_modes`; лимит `content.max_per_day`; режим publish требует статуса `approved`

### Фидбек
- `dzen:track` — связка очереди с постами Дзена (Жаккар заголовков ≥ 0.5), статус → published; фиксация `result_views` (просмотры поста после 16 ч жизни, однократно)
- `dzen:rules` (воскресенье) — LLM предлагает версию правил по win-rate'ам приёмов заголовков (нужно ≥5 результатов); активация вручную на экране «Правила»

### SPA (6 экранов, Vue 3 + Vite + Chart.js)
«Состояние системы» (KPI, сбор по каналам, конвейер, LLM, ингест, расписание, таксономия с бейджами LLM/evergreen) · «Каналы» · «Конкуренты» (селектор own-канала; KPI; графики охваты/динамика/форматы/вовлечённость/длина/сетка публикаций; бенчмарк; покрытие; дуэли; топ постов) · «Черновики» (ревью, выбор A/B/C, одобрение/отклонение) · «Правила» · «Настройки»

### Расписание (UTC; 06:00 Иркутск = 22:00 UTC пред. дня)
22:00 collect → 22:10 ingest → 22:15 classify → 22:18 events → 22:20 generate → 23:00 publish → 23:30 track; воскресенье 01:00 rules.

### Данные
7 каналов: **bst24bratsk = own**; конкуренты — irk.kp.ru, irk.ru, irk.aif.ru, nts (Иркутская область); gorodprima.ru и livennov — в справочнике вне набора. ~1 870 постов, снапшоты с 19.09, 110 кластеров событий.

## Что НЕ сделано (приоритет сверху вниз)

1. **RSS не подключён к студии Дзена** — нужен публичный хост (localhost Дзен не достанет); тест на 2–3 item'ах не проводился. До этого петля фидбека без реальных данных.
2. **Парсеры не настроены** — эндпоинты BST/внешних не вписаны в settings → рерайты не тестируются, работает только evergreen.
3. **Auth отсутствует** — API открыт (CORS `*`), до публичного деплоя обязателен Sanctum-токен.
4. **Нет коммитов** — весь проект untracked; закоммитить первым делом.
5. `own_publications` не имеют `content_format` — конвейер не умеет заказывать формат («превратить заметку в дайджест»); эксперименты тегируются только приёмом заголовка.
6. **Тренды по снапшотам** — данные копятся, UI кривых накопления/полужизни поста нет.
7. Экран «Каналы» — только просмотр; управление is_own/is_active/конкурентами — лишь через `PUT /api/channels/{id}`.
8. `dzen:events` синхронный (не в queue) — при росте числа каналов может затянуться.
9. Нет автотестов; smoke-проверки были ручными.
10. mysql volume без бэкапов; `bst-dzen/README.md` частично устарел (нет system-status/competitive/events/taxonomy).
11. Алерты (протухший сбор, failed jobs) — только визуально на главной, без уведомлений.

## Быстрый старт

```bash
cd bst-dzen && docker compose up -d
# UI: http://localhost:8090 · API: /api/health
docker compose exec app php artisan dzen:collect --sync --days=2   # ручной сбор
docker compose exec app php artisan dzen:llm-test                  # проверка LLM
docker compose exec app php artisan dzen:classify --days=3         # классификация новых
```

Секреты: `bst-dzen/api/.env` — `LLM_API_KEY`, `LLM_BASE_URL` (openai.bothub.ru/v1), `LLM_MODEL_DEFAULT` (gemini-3.8-flash), БД `dzen/dzen_secret`.

## Ключевые файлы-ориентиры

| Что | Где |
|---|---|
| Краулер Дзена | `bst-dzen/api/app/Services/Dzen/{DzenApiClient,DzenCrawler}.php` |
| Классификатор (промпт, реестры, фолбэки) | `bst-dzen/api/app/Services/Analysis/RubricClassifier.php` |
| Метрики конкурентные | `bst-dzen/api/app/Services/Analysis/MetricsService.php` |
| Кластеризация событий | `bst-dzen/api/app/Services/Analysis/EventClusterer.php` |
| Рерайт / evergreen | `bst-dzen/api/app/Services/Content/{Rewriter,EvergreenGenerator,InfopovodSelector}.php` |
| RSS | `bst-dzen/api/app/Services/Publish/RssFeed.php` + `routes/web.php` |
| Трекер / правила | `bst-dzen/api/app/Services/Feedback/{PublicationTracker,RulesEngine}.php` |
| Команды | `bst-dzen/api/app/Console/Commands/dzen:*.php` |
| Расписание | `bst-dzen/api/routes/console.php` |
| Экраны SPA | `bst-dzen/client/src/pages/*.vue` |
| Python-эталоны логики | `dzen_crawler.py`, `events.py`, `analyze.py`, `analyze2.py` |
