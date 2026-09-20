<?php

namespace App\Services\Analysis;

use App\Models\Format;
use App\Models\Post;
use App\Models\Rubric;
use App\Services\LLM\LlmClient;
use Illuminate\Support\Collection;

/**
 * Классификатор: рубрика (о чём) + формат (как написано).
 * Рубрики — реестр в БД, LLM может создавать новые (rubric=NEW + new_rubric, с дедупом).
 * Форматы — фиксированный реестр (расширяется только администратором).
 * evergreen — производное свойство формата (formats.is_evergreen), у LLM не спрашивается.
 */
class RubricClassifier
{
    private const RUBRIC_HINTS = [
        'Происшествия' => 'криминал, ДТП, пожары, ЧП, пропавшие, приговоры судов',
        'Власть/политика' => 'выборы, органы власти, законы, бюджет, назначения',
        'ЖКХ/город' => 'ремонты, дороги, школы, больницы, стройка, дворы, мусор, тарифы ЖКХ',
        'Транспорт' => 'маршруты, расписание, аэропорт, мосты, перевозки',
        'Экономика/выплаты' => 'цены, зарплаты, льготы, пособия, бизнес, налоги',
        'Природа/животные' => 'животные, погода, экология, лес, стихия',
        'Спорт' => 'соревнования, клубы, спортсмены, ГТО',
        'Культура/досуг' => 'театр, кино, концерты, выставки, фестивали, афиша',
        'Люди/общество' => 'истории людей, награды, соцзащита, волонтёры, памятные даты',
        'Развлечения/лайфстайл' => 'мода, психология, интересное, досуг',
    ];

    private const FORMAT_HINTS = [
        'Заметка' => 'короткая новость о факте (кто/что/где/когда) без глубины',
        'Лонгрид' => 'развёрнутый материал с фактурой и экспертными комментариями',
        'Интервью' => 'беседа в формате вопросов-ответов или диалога',
        'Репортаж' => 'с места событий, эффект присутствия («глазами очевидца»)',
        'Кейс' => 'разбор реальной проблемы/ситуации с путями решения',
        'Дайджест' => 'подборка новостей за период, выжимка по большому событию (сбойка)',
        'Подборка/Listicle' => 'тематический список «ТОП-N», не привязан к дате',
        'Гайд/Инструкция' => 'пошаговое «как сделать/как оформить»',
        'Народный календарь' => 'гороскоп, приметы на дату',
        'Анонс/Афиша' => '«что будет», куда пойти',
        'Мнение/Аналитика' => 'авторский разбор «что это значит»',
    ];

    /** keyword-фолбэк для рубрик (без «Прочее»: не совпало — останется null до LLM-прогона) */
    private const FALLBACK = [
        'Происшествия' => '/погиб|умер|дтп|пожар|возгорани|задержан|возбуждено|мошенн|украл|краж|напал|сбит|утонул|труп|следовател|приговор|уголовн|чп|спасат|пропал/ui',
        'Власть/политика' => '/выбор|голосован|губернатор|депутат|госдум|власт|мэр|администрац|закон|правительств/ui',
        'ЖКХ/город' => '/отоплен|котель|жкх|ремонт|дорог|школ|садик|больниц|стройт|рассел|двор|парк|сквер|мусор/ui',
        'Транспорт' => '/автобус|маршрут|рейс|самолёт|аэропорт|поезд|троллейбус|трамвай|мост|тоннель|метро/ui',
        'Экономика/выплаты' => '/рубл|выплат|пособи|зарплат|цен|тариф|субсид|льгот|налог/ui',
        'Природа/животные' => '/медвед|байкал|погод|снег|дожд|паводк|животн|кот|собак|птиц|рыб|лес|эколог|клещ/ui',
        'Спорт' => '/футбол|хоккей|матч|турнир|чемпион|спорт|гто|тренер/ui',
        'Культура/досуг' => '/театр|концерт|фестивал|выставк|праздн|кино|музей|артист|песн|библиотек/ui',
        'Развлечения/лайфстайл' => '/гороскоп|примет|совет|рецепт|красот|мода|стиль|психолог|тест|милота/ui',
    ];

    /** keyword-фолбэк для форматов (только уверенные случаи) */
    private const FORMAT_FALLBACK = [
        'Народный календарь' => '/гороскоп|народные приметы/ui',
        'Дайджест' => '/дайджест|итоги|главное за|за день|за неделю|интернет-приём/ui',
        'Подборка/Listicle' => '/\bтоп\b|подборка|рейтинг/iu',
        'Анонс/Афиша' => '/афиша|анонс|куда пойти|не пропустите/ui',
    ];

    public function __construct(private readonly LlmClient $llm)
    {
    }

    /** @return string[] активные рубрики из реестра */
    public static function rubricNames(): array
    {
        return Rubric::where('is_active', true)->orderBy('id')->pluck('name')->all();
    }

    /** @return string[] активные форматы из реестра */
    public static function formatNames(): array
    {
        return Format::where('is_active', true)->orderBy('id')->pluck('name')->all();
    }

    /** @return string[] форматы с флагом evergreen */
    public static function evergreenFormats(): array
    {
        return Format::where('is_active', true)->where('is_evergreen', true)->pluck('name')->all();
    }

    /** Классифицировать посты без рубрики или формата; возвращает число обработанных */
    public function classifyPending(Collection $posts): int
    {
        $pending = $posts->filter(fn (Post $p) => $p->rubric === null || $p->content_format === null);
        if ($pending->isEmpty()) {
            return 0;
        }

        if (! $this->llm->isConfigured()) {
            foreach ($pending as $post) {
                $this->applyFallback($post);
            }

            return $pending->count();
        }

        $done = 0;
        foreach (array_chunk($pending->all(), 20) as $batch) {
            $payload = array_map(
                fn (Post $p) => [
                    'id' => $p->id,
                    'title' => mb_substr($p->title, 0, 160),
                    'lead' => mb_substr((string) $p->lead, 0, 160),
                ],
                $batch,
            );

            try {
                $result = $this->llm->chatJson('classify', [
                    ['role' => 'system', 'content' => $this->systemPrompt()],
                    ['role' => 'user', 'content' => json_encode($payload, JSON_UNESCAPED_UNICODE)],
                ], 0.1);
            } catch (\Throwable) {
                foreach ($batch as $post) {
                    $this->applyFallback($post);
                }
                $done += count($batch);

                continue;
            }

            $map = collect($result['items'] ?? []);
            foreach ($batch as $post) {
                $item = $map->firstWhere('id', $post->id);
                if ($item) {
                    $this->applyItem($post, $item);
                } else {
                    $this->applyFallback($post);
                }
                $done++;
            }
        }

        return $done;
    }

    private function systemPrompt(): string
    {
        $rubrics = Rubric::where('is_active', true)->orderBy('id')->get();
        $rubricLines = $rubrics->map(
            fn (Rubric $r) => '- ' . $r->name . ' — ' . (self::RUBRIC_HINTS[$r->name] ?? 'см. название'),
        )->implode("\n");

        $formatLines = collect(self::formatNames())->map(
            fn (string $f) => '- ' . $f . ' — ' . (self::FORMAT_HINTS[$f] ?? ''),
        )->implode("\n");

        return <<<TXT
        Ты классификатор постов региональных СМИ Дзена. Для каждого поста определи РУБРИКУ (о чём) и ФОРМАТ (как написано).

        Рубрики (выбирай из списка; новую создавай, только если не подходит ни одна):
        {$rubricLines}

        Форматы (строго из списка, не придумывай):
        {$formatLines}

        Правила:
        1. Конкретное происшествие важнее отраслевой рубрики: «автобус попал в ДТП» → Происшествия, не Транспорт.
        2. При конфликте рубрик выбирай более конкретную к предмету поста.
        3. Новая рубрика — короткое имя (1-2 слова, именительный падеж), только если ни одна не подходит совсем.
        4. Интервью vs Репортаж: диалог, вопросы-ответы → Интервью; повествование с места событий → Репортаж.
        5. Дайджест vs Подборка: новостные поводы за период → Дайджест; вечный тематический список → Подборка/Listicle.
        6. Кейс vs Мнение: практическое решение проблемы → Кейс; интерпретация событий → Мнение/Аналитика.

        Верни JSON: {"items":[{"id":<id>,"rubric":"<имя из списка или NEW>","new_rubric":<null или имя новой>,"format":"<имя из списка>"}]}
        TXT;
    }

    /** Применить результат LLM к посту */
    private function applyItem(Post $post, array $item): void
    {
        $rubric = $item['rubric'] ?? null;
        if ($post->rubric === null) {
            if (is_string($rubric) && strcasecmp($rubric, 'NEW') === 0 && ! empty($item['new_rubric'])) {
                $post->rubric = $this->resolveNewRubric((string) $item['new_rubric']);
            } elseif (is_string($rubric)) {
                $known = Rubric::where('is_active', true)->whereRaw('LOWER(name) = ?', [mb_strtolower($rubric)])->value('name');
                // галлюцинация имени -> keyword-фолбэк, а не null
                $post->rubric = $known ?? $this->fallbackRubric($post->title . ' ' . $post->lead);
            }
        }

        if ($post->content_format === null && is_string($item['format'] ?? null)) {
            $format = Format::where('is_active', true)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($item['format'])])
                ->value('name');
            $post->content_format = $format; // null при галлюцинации
        }

        $post->save();
    }

    /** Создать/найти рубрику по нормализованному имени (дедуп) */
    private function resolveNewRubric(string $raw): ?string
    {
        $name = mb_substr(trim(preg_replace('/\s+/u', ' ', $raw)), 0, 64);
        if ($name === '') {
            return null;
        }
        $name = mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1);

        $existing = Rubric::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
        if ($existing) {
            return $existing->name;
        }

        return Rubric::create(['name' => $name, 'created_by' => 'llm'])->name;
    }

    /** Keyword-фолбэк: только уверенные случаи, иначе поля остаются null */
    private function applyFallback(Post $post): void
    {
        if ($post->rubric === null) {
            $post->rubric = $this->fallbackRubric($post->title . ' ' . $post->lead);
        }

        if ($post->content_format === null) {
            foreach (self::FORMAT_FALLBACK as $format => $pattern) {
                if (preg_match($pattern, $post->title)) {
                    $post->content_format = $format;
                    break;
                }
            }
            if ($post->content_format === null && $post->type === 'article' && $post->size_sec >= 180) {
                $post->content_format = 'Лонгрид';
            }
        }

        $post->save();
    }

    private function fallbackRubric(string $text): ?string
    {
        foreach (self::FALLBACK as $rubric => $pattern) {
            if (preg_match($pattern, $text)) {
                return $rubric;
            }
        }

        return null;
    }
}
