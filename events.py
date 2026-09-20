#!/usr/bin/env python3
"""
Кластеризация «событий»: ищем одну и ту же новость у разных каналов.

Метод: нормализация заголовка+лида -> множество значимых токенов ->
пары публикаций разных каналов в окне ±48 ч -> Жаккар на токенах >= 0.3
(откалибровано вручную) -> union-find -> кластеры-события.

Выход: data/events.csv (только межканальные кластеры) + выборка для проверки.
"""

import csv
import re
import sys
from collections import defaultdict
from datetime import datetime

THRESHOLD = 0.30
WINDOW_SEC = 48 * 3600

STOP = set(
    "в во и на из за по с со к у о об от до для что как это этот эта эти "
    "был была были будет быть он она они мы вы я не ни же бы ли а но или "
    "году года году ещё еще уже там тогда который которая которые "
    "из-за по-настоящему т.д т.п п".split()
)


def normalize(text: str) -> list[str]:
    text = text.lower().replace("ё", "е")
    tokens = re.findall(r"[а-яa-z0-9]+", text)
    return [t for t in tokens if t not in STOP and len(t) > 1]


def shingles(tokens: list[str]) -> set[str]:
    """Множество значимых токенов заголовка+лида."""
    return set(tokens)


def jaccard(a: set, b: set) -> float:
    if not a or not b:
        return 0.0
    inter = len(a & b)
    return inter / (len(a) + len(b) - inter)


class DSU:
    def __init__(self, n: int) -> None:
        self.p = list(range(n))

    def find(self, x: int) -> int:
        while self.p[x] != x:
            self.p[x] = self.p[self.p[x]]
            x = self.p[x]
        return x

    def union(self, a: int, b: int) -> None:
        a, b = self.find(a), self.find(b)
        if a != b:
            self.p[b] = a


def main() -> None:
    rows = []
    with open("data/all_channels.csv", encoding="utf-8-sig") as f:
        for r in csv.DictReader(f):
            r["ts"] = int(
                datetime.strptime(r["published_at"], "%Y-%m-%d %H:%M").timestamp()
            )
            r["views"] = int(r["views"] or 0)
            r["comments"] = int(r["comments"] or 0)
            r["sh"] = shingles(normalize(r["title"] + " " + (r["lead"] or "")))
            rows.append(r)
    rows.sort(key=lambda r: r["ts"])

    n = len(rows)
    dsu = DSU(n)
    pairs_checked = 0
    for i in range(n):
        j = i + 1
        while j < n and rows[j]["ts"] - rows[i]["ts"] <= WINDOW_SEC:
            if rows[i]["channel"] != rows[j]["channel"]:
                pairs_checked += 1
                if jaccard(rows[i]["sh"], rows[j]["sh"]) >= THRESHOLD:
                    dsu.union(i, j)
            j += 1

    clusters = defaultdict(list)
    for i in range(n):
        clusters[dsu.find(i)].append(rows[i])

    # только межканальные кластеры
    events = []
    for members in clusters.values():
        if len({m["channel"] for m in members}) >= 2:
            members.sort(key=lambda m: m["ts"])
            first_ts = members[0]["ts"]
            events.append(members)

    events.sort(key=lambda m: m[0]["ts"], reverse=True)
    print(f"Постов: {n}, пар проверено: {pairs_checked}, событий (>=2 канала): {len(events)}")
    print(f"Порог Жаккара: {THRESHOLD}")

    with open("data/events.csv", "w", newline="", encoding="utf-8-sig") as f:
        w = csv.writer(f)
        w.writerow(["event_id", "n_channels", "channel", "title", "published_at",
                    "delay_min", "views", "comments", "type"])
        for eid, members in enumerate(events, 1):
            first_ts = members[0]["ts"]
            for m in members:
                w.writerow([
                    eid,
                    len({x["channel"] for x in members}),
                    m["channel"],
                    m["title"][:120],
                    m["published_at"],
                    round((m["ts"] - first_ts) / 60),
                    m["views"],
                    m["comments"],
                    m["type"],
                ])
    print("Сохранено: data/events.csv")

    # выборка для ручной проверки: 12 событий через одно
    if "--sample" in sys.argv:
        step = max(1, len(events) // 12)
        for members in events[::step][:12]:
            print("\n--- событие ---")
            for m in members:
                print(f"  [{m['channel']:16}] {m['published_at']} | {m['title'][:70]}")


if __name__ == "__main__":
    main()
