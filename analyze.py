#!/usr/bin/env python3
"""Аналитика по data/all_channels.csv: плотность, тематики, заголовки, охваты."""

import csv
import re
import unicodedata
from collections import Counter, defaultdict
from datetime import datetime, timezone, timedelta

LOCAL_TZ = timezone(timedelta(hours=8))  # Иркутск UTC+8
NOW = datetime(2026, 9, 19, 15, 0, tzinfo=timezone.utc)

CHANNEL_TZ = {  # местное время канала
    "bst24bratsk": timezone(timedelta(hours=8)),
    "irk.aif.ru": timezone(timedelta(hours=8)),
    "irk.kp.ru": timezone(timedelta(hours=8)),
    "irk.ru": timezone(timedelta(hours=8)),
    "gorodprima.ru": timezone(timedelta(hours=7)),  # Красноярск
    "livennov": timezone(timedelta(hours=3)),        # Нижний Новгород
}

rows = []
with open("data/all_channels.csv", encoding="utf-8-sig") as f:
    for r in csv.DictReader(f):
        r["views"] = int(r["views"])
        r["dt"] = datetime.strptime(r["published_at"], "%Y-%m-%d %H:%M").replace(
            tzinfo=timezone.utc
        )
        r["age_days"] = max(0.5, (NOW - r["dt"]).total_seconds() / 86400)
        r["vpd"] = r["views"] / r["age_days"]  # просмотры в день жизни
        rows.append(r)

channels = sorted({r["channel"] for r in rows})

# ---------- 1. Плотность ----------
print("=" * 70)
print("1. ПЛОТНОСТЬ КОНТЕНТА")
print("=" * 70)
for ch in channels:
    rs = [r for r in rows if r["channel"] == ch]
    tzc = CHANNEL_TZ[ch]
    days = {r["dt"].astimezone(tzc).date() for r in rs}
    # дни покрытия: от первой до последней даты в окне
    d_min, d_max = min(days), max(days)
    span = (d_max - d_min).days + 1
    types = Counter(r["type"] for r in rs)
    by_hour = Counter(r["dt"].astimezone(tzc).hour for r in rs)
    peak_hours = ",".join(f"{h:02d}" for h, _ in by_hour.most_common(4))
    night = sum(v for h, v in by_hour.items() if h < 6)
    print(
        f"\n{ch}: {len(rs)} публ. за {span} дн. = {len(rs)/span:.1f}/день | {dict(types)}"
    )
    print(f"  пиковые часы (местн.): {peak_hours} | ночные 00-06: {night} ({night/len(rs)*100:.0f}%)"
    )

# ---------- 2. Классификация ----------
EVERGREEN = re.compile(
    r"примет|гороскоп|милота|смешн|прикол|рецепт|совет|шифровк|сканворд|викторин|"
    r"тест\b|афоризм|цитат[аы] дня|красот|интерьер|мода|стиль|психолог|лайфхак|"
    r"как выбрать|как правильно|что делать если|до чего же",
    re.I,
)
NEWS_MARKERS = re.compile(
    r"задержа|возбудил|погиб|умер|спас|произошл|стал известн|сообщил|запустил|"
    r"открыл|ввели|запретил|призвал|объявил|победил|нашли|вызвал|проверк|"
    r"в иркутск|в братск|в ангарск|в красноярск|в регионе|в области|в крае|"
    r"районе|под иркутском|с 1 |числа |сентябр|октябр|август|выбор|голосован|"
    r"пожар|дтп|авари|задержан|возгорани|мошенн|украл|краж|напал|сбит|утонул|"
    r"труп|следовател|суд |приговор",
    re.I,
)
TOPICS = [
    ("Происшествия/криминал", r"погиб|умер|дтп|пожар|возгорани|задержан|возбуждено|мошенн|украл|краж|напал|дебошир|пьяного?м|сбит|утонул|труп|следовател|приговор|уголовн|чп|экстренн|спасат|пропал|без вести|отравил"),
    ("Власть/политика/выборы", r"выбор|голосован|губернатор|депутат|госдум|власт|мэр|администрац|закон|правительств|парламент|мвд|министра|надзор|прокур"),
    ("ЖКХ/город/стройка", r"отоплен|котель|жкх|ремонт|дорог|школ|садик|детсад|больниц|поликлин|стройт|рассел|двор|парк|сквер|благоустрой|мусор|водо|электр|сет|капитальн|конкурс.*ремонт"),
    ("Транспорт", r"автобус|маршрут|рейс|самолёт|аэропорт|поезд|троллейбус|трамвай|такси|мост|тоннель|метро|перевоз|водител|лёд|ледокол"),
    ("Экономика/деньги/выплаты", r"рубл|выплат|пособи|зарплат|цен|тариф|субсид|льгот|инвест|бизнес|налог|стабил|потребит"),
    ("Природа/животные/погода", r"медвед|байкал|погод|снег|дожд|паводк|животн|кот\b|кот[аы]|собак|птиц|рыб|заповед|лес|эколог|клещ|змея|брусник|гриб"),
    ("Спорт", r"футбол|хоккей|матч|турнир|чемпион|спорт|гто|забил|команд|тренер"),
    ("Культура/досуг/события", r"театр|концерт|фестивал|выставк|праздн|кино|музей|артист|песн|ансамбл|библиотек|творч|художн"),
    ("Люди/общество", r"семь|детей|ребён|ребят|студент|школьник|пенсионер|врач|учител|жител|молодёж|волонтёр|имен|истори|судьб|наград|юбилей|поздрав"),
    ("Развлечения/лайфстайл", r"милота|смешн|прикол|примет|гороскоп|совет|рецепт|красот|интерьер|мода|стиль|психолог|лайфхак|тест\b|викторин"),
]

def classify(title):
    ev = bool(EVERGREEN.search(title))
    nw = bool(NEWS_MARKERS.search(title))
    if ev:
        kind = "не новость (evergreen/развл.)"
    elif nw:
        kind = "новость"
    else:
        kind = "новость (по контексту)" if not ev else "не новость"
    topic = "Прочее"
    for name, pat in TOPICS:
        if re.search(pat, title, re.I):
            topic = name
            break
    return kind, topic

print()
print("=" * 70)
print("2. ТИПЫ КОНТЕНТА И ТЕМАТИКА (ключевая эвристика по заголовкам)")
print("=" * 70)
for ch in channels:
    rs = [r for r in rows if r["channel"] == ch]
    kinds = Counter()
    topics = Counter()
    for r in rs:
        k, t = classify(r["title"])
        kinds[k] += 1
        topics[t] += 1
    n = len(rs)
    news_pct = (kinds["новость"] + kinds["новость (по контексту)"]) / n * 100
    print(f"\n{ch}: новостей ~{news_pct:.0f}%  |  evergreen {kinds['не новость (evergreen/развл.)']/n*100:.0f}%")
    for t, c in topics.most_common():
        print(f"    {t:32} {c:4} ({c/n*100:4.1f}%)")

# ---------- 3. Заголовки ----------
print()
print("=" * 70)
print("3. ЗАГОЛОВКИ: признак -> медианные просмотры/день (только статьи)")
print("=" * 70)
def has_emoji(s):
    return any(unicodedata.category(c) == "So" for c in s)

FEATS = {
    "длина": lambda t: len(t),
    "двоеточие": lambda t: ":" in t,
    "кавычки «»": lambda t: "«" in t,
    "цифры": lambda t: bool(re.search(r"\d", t)),
    "вопрос ?": lambda t: "?" in t,
    "emoji": lambda t: has_emoji(t),
    "как/почему/что/зачем": lambda t: bool(re.search(r"\b(как|почему|что|зачем|чем)\b", t, re.I)),
}
def med(vals):
    vals = sorted(vals)
    if not vals:
        return 0
    return vals[len(vals)//2]

for ch in channels:
    arts = [r for r in rows if r["channel"] == ch and r["type"] == "article"]
    if not arts:
        continue
    lens = [len(r["title"]) for r in arts]
    print(f"\n{ch} (n={len(arts)}): длина загол. средн={sum(lens)/len(lens):.0f}, медиана={med(lens)}")
    base = med([r["vpd"] for r in arts])
    for fname, fn in FEATS.items():
        pos = [r["vpd"] for r in arts if fn(r["title"])]
        neg = [r["vpd"] for r in arts if not fn(r["title"])]
        if pos and neg:
            print(
                f"    {fname:22} {len(pos):3} шт ({len(pos)/len(arts)*100:3.0f}%) | "
                f"мед. vpd: с признаком {med(pos):7.1f} vs без {med(neg):7.1f} "
                f"(x{med(pos)/max(med(neg),0.1):.1f})"
            )

# ---------- 4. Охваты ----------
print()
print("=" * 70)
print("4. ОХВАТЫ: медиана/среднее, концентрация, свежесть")
print("=" * 70)
for ch in channels:
    rs = [r for r in rows if r["channel"] == ch]
    arts = sorted((r["vpd"] for r in rs if r["type"] == "article"), reverse=True)
    shorts = sorted((r["vpd"] for r in rs if r["type"] == "short"), reverse=True)
    total = sum(arts)
    top10 = sum(arts[: max(1, len(arts) // 10)]) if arts else 0
    zero_24h = sum(
        1 for r in rs
        if r["views"] == 0 and r["age_days"] < 1.5 and r["type"] == "article"
    )
    print(f"\n{ch}:")
    if arts:
        print(
            f"    статьи:  медиана vpd={med(arts):8.1f}  средн={sum(arts)/len(arts):8.1f}  "
            f"топ-10% статей дают {top10/max(total,1)*100:.0f}% просмотров"
        )
    if shorts:
        print(
            f"    шортсы:  n={len(shorts)}  медиана vpd={med(shorts):8.1f}  "
            f"макс={max(shorts):8.1f}"
        )

# ---------- 5. Топ заголовков по vpd ----------
print()
print("=" * 70)
print("5. ТОП-5 ЗАГОЛОВКОВ ПО ПРОСМОТРАМ/ДЕНЬ (статьи, для стиля)")
print("=" * 70)
for ch in channels:
    arts = sorted(
        (r for r in rows if r["channel"] == ch and r["type"] == "article"),
        key=lambda r: r["vpd"], reverse=True,
    )
    print(f"\n{ch}:")
    for r in arts[:5]:
        print(f"    [{r['vpd']:7.1f} vpd] {r['title'][:75]}")

# ---------- 6. День недели ----------
print()
print("=" * 70)
print("6. МЕДИАНА VPD ПО ДНЯМ НЕДЕЛИ (статьи, все каналы)")
print("=" * 70)
WD = ["Пн", "Вт", "Ср", "Чт", "Пт", "Сб", "Вс"]
by_wd = defaultdict(list)
for r in rows:
    if r["type"] == "article":
        by_wd[r["dt"].astimezone(CHANNEL_TZ[r["channel"]]).weekday()].append(r["vpd"])
for wd in range(7):
    v = by_wd.get(wd, [])
    n = len(v)
    print(f"    {WD[wd]}: n={n:4}  медиана vpd={med(v):7.1f}")

# ---------- 7. Время публикации ----------
print()
print("=" * 70)
print("7. МЕДИАНА VPD ПО ЧАСУ ПУБЛИКАЦИИ (местное время канала, статьи, все каналы)")
print("=" * 70)
by_h = defaultdict(list)
for r in rows:
    if r["type"] == "article":
        by_h[r["dt"].astimezone(CHANNEL_TZ[r["channel"]]).hour].append(r["vpd"])
for h in range(24):
    v = by_h.get(h, [])
    if v:
        print(f"    {h:02d}:00  n={len(v):4}  медиана vpd={med(v):7.1f}  {'#'*int(med(v)/20)}")

# ---------- 8. Evergreen vs новости ----------
print()
print("=" * 70)
print("8. МЕДИАНА VPD: обычные статьи vs гороскопы/приметы (evergreen)")
print("=" * 70)
EG = re.compile(r"гороскоп|народные приметы|милота дня", re.I)
for ch in channels:
    arts = [r for r in rows if r["channel"] == ch and r["type"] == "article"]
    eg = sorted(r["vpd"] for r in arts if EG.search(r["title"]))
    nn = sorted(r["vpd"] for r in arts if not EG.search(r["title"]))
    if eg:
        print(
            f"    {ch:16} evergreen n={len(eg):3} мед={med(eg):7.1f}  |  "
            f"прочее n={len(nn):3} мед={med(nn):7.1f}  (x{med(eg)/max(med(nn),0.1):.0f})"
        )
