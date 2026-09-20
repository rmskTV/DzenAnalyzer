#!/usr/bin/env python3
"""Графики для отчёта v2. Запуск: .venv/bin/python charts.py"""

import csv
import re
from collections import defaultdict
from datetime import datetime, timedelta, timezone

import matplotlib

matplotlib.use("Agg")
import matplotlib.pyplot as plt
from pathlib import Path

NOW = datetime(2026, 9, 19, 16, 0, tzinfo=timezone.utc)
CHANNEL_TZ = {
    "bst24bratsk": timezone(timedelta(hours=8)),
    "irk.aif.ru": timezone(timedelta(hours=8)),
    "irk.kp.ru": timezone(timedelta(hours=8)),
    "irk.ru": timezone(timedelta(hours=8)),
    "gorodprima.ru": timezone(timedelta(hours=7)),
    "livennov": timezone(timedelta(hours=3)),
    "nts": timezone(timedelta(hours=8)),
}
ORDER = ["bst24bratsk", "nts", "irk.aif.ru", "gorodprima.ru", "irk.ru", "livennov", "irk.kp.ru"]
EG = re.compile(r"гороскоп|народные приметы|милота дня", re.I)

rows = []
with open("data/all_channels.csv", encoding="utf-8-sig") as f:
    for r in csv.DictReader(f):
        r["views"] = int(r["views"] or 0)
        r["comments"] = int(r["comments"] or 0)
        r["size_sec"] = int(r["size_sec"] or 0)
        dt = datetime.strptime(r["published_at"], "%Y-%m-%d %H:%M").replace(tzinfo=timezone.utc)
        r["dt"] = dt
        r["local"] = dt.astimezone(CHANNEL_TZ[r["channel"]])
        r["vpd"] = r["views"] / max(0.5, (NOW - dt).total_seconds() / 86400)
        rows.append(r)

OUT = Path("charts")
OUT.mkdir(exist_ok=True)
plt.rcParams.update({"figure.dpi": 150, "font.size": 9, "axes.grid": True, "grid.alpha": 0.3})


def med(v):
    v = sorted(v)
    return v[len(v) // 2] if v else 0


# 1. Boxplot охватов статей
arts = defaultdict(list)
for r in rows:
    if r["type"] == "article":
        arts[r["channel"]].append(r["vpd"])
data = [arts[ch] for ch in ORDER]
fig, ax = plt.subplots(figsize=(8, 4.2))
bp = ax.boxplot(data, tick_labels=ORDER, showfliers=False, patch_artist=True)
for i, box in enumerate(bp["boxes"]):
    box.set_facecolor("#e74c3c" if ORDER[i] == "bst24bratsk" else "#3498db")
    box.set_alpha(0.7)
ax.set_yscale("log")
ax.set_ylabel("просмотры/день жизни (лог)")
ax.set_title("Охваты статей по каналам — БСТ выделен красным")
fig.tight_layout()
fig.savefig(OUT / "01_vpd_box.png")
plt.close(fig)

# 2. Heat-map час x день недели: БСТ vs КП (местное время)
fig, axes = plt.subplots(1, 2, figsize=(11, 4))
for ax, ch in zip(axes, ["bst24bratsk", "irk.kp.ru"]):
    grid = [[0] * 24 for _ in range(7)]
    for r in rows:
        if r["channel"] == ch and r["type"] == "article":
            grid[r["local"].weekday()][r["local"].hour] += 1
    ax.imshow(grid, aspect="auto", cmap="YlOrRd")
    ax.set_xticks(range(0, 24, 3))
    ax.set_yticks(range(7), ["Пн", "Вт", "Ср", "Чт", "Пт", "Сб", "Вс"])
    ax.set_title(f"{ch}: когда публикует")
    ax.grid(False)
fig.suptitle("Сетка публикаций (местное время):BST vs лидер")
fig.tight_layout()
fig.savefig(OUT / "02_heatmap_publish.png")
plt.close(fig)

# 3. Скорость vs доля охвата от лидера события
ev = defaultdict(list)
with open("data/events.csv", encoding="utf-8-sig") as f:
    for r in csv.DictReader(f):
        r["delay_min"] = int(r["delay_min"])
        r["views"] = int(r["views"])
        dt = datetime.strptime(r["published_at"], "%Y-%m-%d %H:%M").replace(tzinfo=timezone.utc)
        r["vpd"] = r["views"] / max(0.5, (NOW - dt).total_seconds() / 86400)
        ev[r["event_id"]].append(r)
xs, ys, cs = [], [], []
COLORS = {"bst24bratsk": "#e74c3c", "irk.kp.ru": "#3498db", "nts": "#9b59b6",
          "irk.aif.ru": "#95a5a6", "irk.ru": "#2ecc71", "gorodprima.ru": "#f39c12",
          "livennov": "#1abc9c"}
for members in ev.values():
    leader = max(m["vpd"] for m in members)
    for m in members:
        if m["delay_min"] == 0 or leader == 0:
            continue
        xs.append(max(m["delay_min"], 5))
        ys.append(max(m["vpd"] / leader * 100, 0.5))
        cs.append(COLORS.get(m["channel"], "gray"))
fig, ax = plt.subplots(figsize=(8, 4.5))
ax.scatter(xs, ys, c=cs, s=28, alpha=0.75)
for ch, c in COLORS.items():
    ax.scatter([], [], c=c, label=ch)
ax.set_xscale("log")
ax.set_yscale("log")
ax.set_xlabel("опубликовали позже лидера, мин (лог)")
ax.set_ylabel("охват в % от лидера события (лог)")
ax.set_title("Опоздал — потерял: отставание vs доля охвата (только не-лидеры)")
ax.legend(fontsize=7, loc="lower left")
fig.tight_layout()
fig.savefig(OUT / "03_delay_vs_share.png")
plt.close(fig)

# 4. Длина статьи vs медианный vpd
BUCKETS = [(0, 60, "<1м"), (60, 120, "1-2м"), (120, 180, "2-3м"), (180, 240, "3-4м"), (240, 10**9, ">4м")]
fig, ax = plt.subplots(figsize=(8, 4.2))
width = 0.38
for k, (ch, color) in enumerate([("irk.kp.ru", "#3498db"), ("gorodprima.ru", "#f39c12")]):
    vals, labels = [], []
    for lo, hi, lab in BUCKETS:
        v = [r["vpd"] for r in rows
             if r["channel"] == ch and r["type"] == "article" and lo <= r["size_sec"] < hi]
        if len(v) >= 5:
            vals.append(med(v))
            labels.append(lab)
    pos = [i + (k - 0.5) * width for i in range(len(vals))]
    ax.bar(pos, vals, width, color=color, label=ch, alpha=0.8)
    ax.set_xticks(range(len(vals)))
    ax.set_xticklabels(labels[: len(vals)])
ax.set_xlabel("время чтения статьи")
ax.set_ylabel("медиана просмотров/день")
ax.set_title("Длинные материалы получают больше охватов")
ax.legend()
fig.tight_layout()
fig.savefig(OUT / "04_length_vs_vpd.png")
plt.close(fig)

# 5. Evergreen vs прочее
fig, ax = plt.subplots(figsize=(8, 4.2))
eg_m, nn_m, labels = [], [], []
for ch in ORDER:
    a = [r["vpd"] for r in rows if r["channel"] == ch and r["type"] == "article"]
    eg = [r["vpd"] for r in rows if r["channel"] == ch and r["type"] == "article" and EG.search(r["title"])]
    nn = [r["vpd"] for r in rows if r["channel"] == ch and r["type"] == "article" and not EG.search(r["title"])]
    if eg:
        eg_m.append(med(eg))
        nn_m.append(med(nn))
        labels.append(ch)
x = range(len(labels))
ax.bar([i - 0.2 for i in x], eg_m, 0.4, color="#e67e22", label="гороскопы/приметы")
ax.bar([i + 0.2 for i in x], nn_m, 0.4, color="#bdc3c7", label="остальные статьи")
ax.set_yscale("log")
ax.set_xticks(list(x))
ax.set_xticklabels(labels, rotation=20)
ax.set_ylabel("медиана просмотров/день (лог)")
ax.set_title("Evergreen-сетки против новостей — медианные охваты")
ax.legend()
fig.tight_layout()
fig.savefig(OUT / "05_evergreen.png")
plt.close(fig)

# 6. Вовлечённость
fig, ax = plt.subplots(figsize=(8, 4.2))
names, vals, colors = [], [], []
for ch in ORDER:
    rs = [r for r in rows if r["channel"] == ch and r["views"] >= 10]
    if not rs:
        continue
    tc, tv = sum(r["comments"] for r in rs), sum(r["views"] for r in rs)
    names.append(ch)
    vals.append(tc / max(tv, 1) * 1000)
    colors.append("#e74c3c" if ch == "bst24bratsk" else "#3498db")
ax.bar(names, vals, color=colors, alpha=0.8)
ax.set_ylabel("комментариев на 1000 просмотров")
ax.set_title("Вовлечённость аудитории")
ax.tick_params(axis="x", rotation=20)
fig.tight_layout()
fig.savefig(OUT / "06_engagement.png")
plt.close(fig)

print("Графики:", *sorted(p.name for p in OUT.glob("*.png")), sep="\n  ")
