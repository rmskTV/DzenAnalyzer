#!/usr/bin/env python3
"""Аналитика v2: вовлечённость, длина, событийная конкуренция. Фокус — bst24bratsk."""

import csv
from collections import defaultdict
from datetime import datetime, timezone

NOW = datetime(2026, 9, 19, 16, 0, tzinfo=timezone.utc)
MY = "bst24bratsk"


def med(v):
    v = sorted(v)
    return v[len(v) // 2] if v else 0


rows = []
with open("data/all_channels.csv", encoding="utf-8-sig") as f:
    for r in csv.DictReader(f):
        r["views"] = int(r["views"] or 0)
        r["comments"] = int(r["comments"] or 0)
        r["size_sec"] = int(r["size_sec"] or 0)
        r["ts"] = int(
            datetime.strptime(r["published_at"], "%Y-%m-%d %H:%M")
            .replace(tzinfo=timezone.utc)
            .timestamp()
        )
        age = max(0.5, (NOW.timestamp() - r["ts"]) / 86400)
        r["vpd"] = r["views"] / age
        rows.append(r)

channels = sorted({r["channel"] for r in rows})

# ---------- 1. Вовлечённость ----------
print("=" * 70)
print("1. ВОВЛЕЧЁННОСТЬ: комментарии на 1000 просмотров (посты с views>0)")
print("=" * 70)
for ch in channels:
    rs = [r for r in rows if r["channel"] == ch and r["views"] >= 10]
    if not rs:
        continue
    tot_v = sum(r["views"] for r in rs)
    tot_c = sum(r["comments"] for r in rs)
    with_c = sum(1 for r in rs if r["comments"] > 0)
    arts = [r for r in rs if r["type"] == "article"]
    print(
        f"  {ch:16} {tot_c/max(tot_v,1)*1000:6.2f} комм/1000 пр. | "
        f"постов с комм.: {with_c}/{len(rs)} ({with_c/len(rs)*100:.0f}%) | "
        f"всего комм.: {tot_c}"
    )
print("\n  Самые обсуждаемые посты (комм/1000 пр., comments>=4):")
discussed = [r for r in rows if r["comments"] >= 4 and r["views"] >= 10]
for r in sorted(discussed, key=lambda x: x["comments"] / max(x["views"], 1), reverse=True)[:8]:
    print(
        f"    [{r['channel']:14}] {r['comments']} комм / {r['views']} пр = "
        f"{r['comments']/max(r['views'],1)*1000:.0f}/1000 | {r['title'][:55]}"
    )

# ---------- 2. Длина vs охват ----------
print()
print("=" * 70)
print("2. ДЛИНА СТАТЬИ (timeToReadSeconds) -> медиана vpd")
print("=" * 70)
BUCKETS = [(0, 60, "<1 мин"), (60, 120, "1-2 мин"), (120, 180, "2-3 мин"),
           (180, 240, "3-4 мин"), (240, 10**9, ">4 мин")]
for ch in channels:
    arts = [r for r in rows if r["channel"] == ch and r["type"] == "article" and r["size_sec"]]
    if len(arts) < 20:
        continue
    parts = []
    for lo, hi, label in BUCKETS:
        v = [r["vpd"] for r in arts if lo <= r["size_sec"] < hi]
        if len(v) >= 5:
            parts.append(f"{label}: n={len(v)}, мед={med(v):.1f}")
    print(f"  {ch:16} | " + " | ".join(parts))

# ---------- 3. Событийная конкуренция ----------
print()
print("=" * 70)
print("3. СОБЫТИЙНАЯ КОНКУРЕНЦИЯ (85 межканальных событий)")
print("=" * 70)
ev = defaultdict(list)
with open("data/events.csv", encoding="utf-8-sig") as f:
    for r in csv.DictReader(f):
        r["delay_min"] = int(r["delay_min"])
        r["views"] = int(r["views"])
        r["vpd"] = r["views"] / max(
            0.5,
            (
                NOW.timestamp()
                - datetime.strptime(r["published_at"], "%Y-%m-%d %H:%M")
                .replace(tzinfo=timezone.utc)
                .timestamp()
            )
            / 86400,
        )
        ev[r["event_id"]].append(r)

n_events = len(ev)
print(f"\n  Покрытие событий (в скольких событиях канал участвовал):")
for ch in channels:
    mine = [m for e in ev.values() for m in e if m["channel"] == ch]
    if not mine:
        continue
    first = sum(1 for m in mine if m["delay_min"] == 0)
    print(
        f"    {ch:16} {len(mine):3}/{n_events} ({len(mine)/n_events*100:3.0f}%) | "
        f"медианное отставание: {med([m['delay_min'] for m in mine]):5.0f} мин | "
        f"был первым: {first} раз"
    )

# дуэли bst24bratsk
print("\n  Дуэли bst24bratsk против конкурентов на одном событии:")
duels = []
for eid, members in ev.items():
    mine = [m for m in members if m["channel"] == MY]
    others = [m for m in members if m["channel"] != MY]
    if mine and others:
        best_other = max(others, key=lambda m: m["vpd"])
        duels.append((eid, mine[0], best_other))
if duels:
    wins = sum(1 for _, m, o in duels if m["vpd"] > o["vpd"])
    print(f"    всего дуэлей: {len(duels)}, побед bst24bratsk: {wins}")
    for eid, m, o in sorted(duels, key=lambda x: x[1]["vpd"] / max(x[2]["vpd"], 0.1), reverse=True)[:5]:
        ratio = m["vpd"] / max(o["vpd"], 0.1)
        print(f"\n    [x{ratio:.1f}] событие {eid}")
        print(f"      БСТ : {m['title'][:65]} (отставание {m['delay_min']} мин)")
        print(f"      {o['channel']:14}: {o['title'][:65]}")
    print("\n    Проигранные дуэли (худшие):")
    for eid, m, o in sorted(duels, key=lambda x: x[1]["vpd"] / max(x[2]["vpd"], 0.1))[:5]:
        ratio = m["vpd"] / max(o["vpd"], 0.1)
        print(f"\n    [x{ratio:.2f}] событие {eid}")
        print(f"      БСТ : {m['title'][:65]} (отставание {m['delay_min']} мин)")
        print(f"      {o['channel']:14}: {o['title'][:65]}")

# отставание -> доля просмотров от лидера
print("\n  Связь отставания с охватом (все участники событий, vpd % от лидера события):")
bands = [(0, 30, "0-0.5 ч"), (30, 120, "0.5-2 ч"), (120, 360, "2-6 ч"),
         (360, 720, "6-12 ч"), (720, 10**9, ">12 ч")]
by_band = defaultdict(list)
for eid, members in ev.items():
    leader = max(m["vpd"] for m in members)
    for m in members:
        for lo, hi, label in bands:
            if lo <= m["delay_min"] < hi:
                by_band[label].append(m["vpd"] / max(leader, 0.1) * 100)
for lo, hi, label in bands:
    v = by_band[label]
    if v:
        print(f"    {label:8} n={len(v):3}  медиана доли от лидера: {med(v):5.1f}%")
