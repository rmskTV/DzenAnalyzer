#!/usr/bin/env python3
"""
Краулер контента Дзен-каналов через публичный API dzen.ru/api/web/v1/channel-more.

Режимы:
  1. Живой сбор:    python3 dzen_crawler.py bst24bratsk --days 21
  2. Из дампов:     python3 dzen_crawler.py --from-json 1.json 2.json 3.json

API отдаёт страницы по ~20 публикаций. Статьи лежат в items[],
видео — внутри контейнеров channel_long_video_floor (tab: longs/shorts),
в каждом до 3 видео. Пагинация — поле more.link в ответе.

Важно: публичный API возвращает только просмотры (views).
Показы (impressions) доступны лишь владельцу канала в студии Дзена.
"""

import argparse
import csv
import json
import re
import sys
import time
import urllib.parse
import urllib.request
from datetime import datetime, timezone
from pathlib import Path

API_URL = "https://dzen.ru/api/web/v1/channel-more"
USER_AGENT = (
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36"
)

# tab внутри floor-контейнера -> человекочитаемый тип
VIDEO_TAB_TYPES = {"longs": "video_long", "shorts": "short"}
# прямые item type (режим channel_id) -> человекочитаемый тип
VIDEO_ITEM_TYPES = {
    "gif": "video_long",
    "short_video": "short",
    "short_video_compact": "short",
}
ID_RE = re.compile(r"^[0-9a-f]{24}$")


def fetch_page(channel: str, next_page_id: str | None, tab: str | None = None) -> dict:
    params = {
        "sort_type": "regular",
        "country_code": "ru",
        "clid": "1400",
        "lang": "ru",
    }
    if tab:  # режим channel_id: вкладка + одиночный курсор
        params["channel_id"] = channel
        params["tab"] = tab
    else:  # режим channel_name: тройной курсор
        params["channel_name"] = channel
    if next_page_id:
        params["next_page_id"] = next_page_id
    url = f"{API_URL}?{urllib.parse.urlencode(params)}"
    req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
    with urllib.request.urlopen(req, timeout=30) as resp:
        return json.loads(resp.read().decode("utf-8"))


def extract_items(page: dict) -> list[dict]:
    """Достаёт статьи и видео (из floor-контейнеров) из одного ответа API."""
    rows = []
    for item in page.get("items", []):
        if "items" in item and str(item.get("type", "")).startswith("channel_"):
            # floor-контейнер: channel_long_video_floor / channel_short_video_floor
            tab = item.get("tab", "")
            kind = VIDEO_TAB_TYPES.get(tab, tab or item.get("type"))
            for video in item.get("items", []):
                rows.append(make_row(video, kind))
        else:
            raw = item.get("type", "?")
            kind = "article" if raw == "article" else VIDEO_ITEM_TYPES.get(raw, raw)
            rows.append(make_row(item, kind))
    return rows


def make_row(item: dict, kind: str) -> dict:
    ts = item.get("publicationDate")
    pub_date = (
        datetime.fromtimestamp(int(ts), tz=timezone.utc).strftime("%Y-%m-%d %H:%M")
        if ts
        else ""
    )
    url = item.get("shareLink") or item.get("link") or ""
    url = url.split("?")[0]
    views = item.get("views")
    comments = (item.get("socialInfo") or {}).get("commentCount") or 0
    size_sec = item.get("timeToReadSeconds") or (item.get("video") or {}).get("duration") or 0
    return {
        "type": kind,
        "title": (item.get("title") or "").strip(),
        "url": url,
        "published_at": pub_date,
        "published_ts": int(ts) if ts else 0,
        "views": views if views is not None else 0,
        "comments": comments,
        "size_sec": size_sec,
        "lead": (item.get("text") or "").strip()[:200],
    }


def crawl(channel: str, days: int, delay: float = 0.7, max_pages: int = 200) -> list[dict]:
    now_ms = int(time.time() * 1000)
    # Синтетический курсор "из будущего" -> API отдаёт самую свежую страницу.
    next_page_id = f"articles/-1/{now_ms},longs/-1/{now_ms},shorts/3/{now_ms}"
    cutoff = time.time() - days * 86400
    seen: set[str] = set()
    rows: list[dict] = []

    for page_num in range(1, max_pages + 1):
        try:
            page = fetch_page(channel, next_page_id)
        except Exception as e:
            print(f"  ! ошибка запроса (стр.{page_num}): {e}", file=sys.stderr)
            break

        page_rows = extract_items(page)
        fresh = [r for r in page_rows if r["published_ts"] >= cutoff]
        for r in fresh:
            key = r["url"] or r["title"]
            if key not in seen:
                seen.add(key)
                rows.append(r)

        # останавливаемся по самой старой ДАТИРОВАННОЙ публикации на странице
        dated = [r["published_ts"] for r in page_rows if r["published_ts"]]
        oldest = min(dated) if dated else 0
        print(
            f"  стр.{page_num}: +{len(fresh)} публикаций, "
            f"самая старая на странице: "
            f"{datetime.fromtimestamp(oldest, tz=timezone.utc).strftime('%Y-%m-%d %H:%M') if oldest else '—'}"
        )

        more_link = page.get("more", {}).get("link", "")
        if not more_link or not page_rows or (oldest and oldest < cutoff):
            break
        match = re.search(r"next_page_id=([^&]+)", more_link)
        if not match:
            break
        next_page_id = urllib.parse.unquote(match.group(1))
        time.sleep(delay)

    return rows


def crawl_tab(
    channel: str, tab: str, cutoff: float, delay: float, max_pages: int, seen: set, rows: list
) -> None:
    """Обход одной вкладки; курсор и подпись страниц — для текущего режима."""
    next_page_id = f"-1/{int(time.time() * 1000)}"
    for page_num in range(1, max_pages + 1):
        try:
            page = fetch_page(channel, next_page_id, tab=tab)
        except Exception as e:
            print(f"  ! ошибка запроса [{tab}] (стр.{page_num}): {e}", file=sys.stderr)
            break

        page_rows = extract_items(page)
        fresh = [r for r in page_rows if r["published_ts"] >= cutoff]
        for r in fresh:
            key = r["url"] or r["title"]
            if key not in seen:
                seen.add(key)
                rows.append(r)

        dated = [r["published_ts"] for r in page_rows if r["published_ts"]]
        oldest = min(dated) if dated else 0
        print(
            f"  [{tab}] стр.{page_num}: +{len(fresh)}, старая: "
            f"{datetime.fromtimestamp(oldest, tz=timezone.utc).strftime('%Y-%m-%d %H:%M') if oldest else '—'}"
        )

        more_link = page.get("more", {}).get("link", "")
        if not more_link or not page_rows or (oldest and oldest < cutoff):
            break
        match = re.search(r"next_page_id=([^&]+)", more_link)
        if not match:
            break
        next_page_id = urllib.parse.unquote(match.group(1))
        time.sleep(delay)


def crawl_by_id(channel_id: str, days: int, delay: float = 0.7, max_pages: int = 200) -> list[dict]:
    """Канал по channel_id: три вкладки с независимыми курсорами."""
    cutoff = time.time() - days * 86400
    seen: set[str] = set()
    rows: list[dict] = []
    for tab in ("articles", "longs", "shorts"):
        crawl_tab(channel_id, tab, cutoff, delay, max_pages, seen, rows)
    return rows


def parse_dumps(files: list[str]) -> list[dict]:
    seen: set[str] = set()
    rows: list[dict] = []
    for fn in files:
        with open(fn, encoding="utf-8") as f:
            page = json.load(f)
        for r in extract_items(page):
            key = r["url"] or r["title"]
            if key not in seen:
                seen.add(key)
                rows.append(r)
    return rows


def save_csv(rows: list[dict], out_path: str) -> None:
    Path(out_path).parent.mkdir(parents=True, exist_ok=True)
    fields = [
        "published_at", "type", "title", "url",
        "views", "comments", "size_sec", "lead",
    ]
    with open(out_path, "w", newline="", encoding="utf-8-sig") as f:
        writer = csv.DictWriter(f, fieldnames=fields)
        writer.writeheader()
        for r in sorted(rows, key=lambda x: x["published_ts"], reverse=True):
            writer.writerow({k: r.get(k, "") for k in fields})


def print_summary(rows: list[dict], channel: str) -> None:
    by_type: dict[str, list[int]] = {}
    for r in rows:
        by_type.setdefault(r["type"], []).append(r["views"])
    print(f"\nКанал: {channel} | публикаций: {len(rows)}")
    for kind, views in sorted(by_type.items()):
        print(
            f"  {kind:12} n={len(views):3}  "
            f"просмотры: сумма={sum(views)}, среднее={sum(views)/len(views):.1f}, "
            f"макс={max(views)}"
        )


def main() -> None:
    parser = argparse.ArgumentParser(description="Сбор контента Дзен-каналов")
    parser.add_argument("channels", nargs="*", help="имена каналов (dzen.ru/<name>)")
    parser.add_argument("--days", type=int, default=21, help="глубина выборки (дней)")
    parser.add_argument("--from-json", nargs="+", help="парсить сохранённые дампы API")
    parser.add_argument("--out-dir", default="data", help="каталог для CSV")
    args = parser.parse_args()

    if args.from_json:
        rows = parse_dumps(args.from_json)
        out = str(Path(args.out_dir) / f"{Path(args.from_json[0]).stem}_content.csv")
        save_csv(rows, out)
        print_summary(rows, f"из дампов: {', '.join(args.from_json)}")
        print(f"CSV: {out}")
        return

    if not args.channels:
        parser.error("укажите имя канала или --from-json")

    for channel in args.channels:
        # форматы: <name> | <24-hex id> | label=<name или id>
        label, _, target = channel.partition("=")
        if not target:
            label = target = channel
        target = target.rstrip("/").split("/")[-1]  # поддержка URL dzen.ru/...
        print(f"\nСобираю канал {label} (последние {args.days} дн.)...")
        if ID_RE.match(target):
            rows = crawl_by_id(target, args.days)
        else:
            rows = crawl(target, args.days)
        out = str(Path(args.out_dir) / f"{label}_content.csv")
        save_csv(rows, out)
        print_summary(rows, label)
        print(f"CSV: {out}")


if __name__ == "__main__":
    main()
