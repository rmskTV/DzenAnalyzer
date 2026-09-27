// Конкурентная сводка: порт bst-dzen/api/app/Services/Analysis/MetricsService.php.
// Без LLM-зависимостей: форматы/evergreen не считаются (это классификация),
// вместо них — типы публикаций (статья/шортс/лонг).

export const MATURITY_HOURS = 16
export const LENGTH_BUCKETS = [
  ['<1', 0, 60],
  ['1-2', 60, 120],
  ['2-3', 120, 180],
  ['3-4', 180, 240],
  ['>4', 240, Infinity],
]

export function median(values) {
  const sorted = [...values].sort((a, b) => a - b)
  const n = sorted.length
  if (n === 0) return 0
  return sorted[Math.floor(n / 2)]
}

/** Посты, прожившие в паблике достаточно для участия в охватных метриках */
export function matured(posts, nowSec = Date.now() / 1000) {
  const cutoff = nowSec - MATURITY_HOURS * 3600
  return posts.filter((p) => p.ts > 0 && p.ts <= cutoff)
}

function inWindow(posts, days, nowSec) {
  const since = nowSec - days * 86400
  return posts.filter((p) => p.ts >= since)
}

/** Сетка 7×24 публикаций (Пн..Вс × часы) в локальном времени браузера */
function publishGrid(posts) {
  const grid = Array.from({ length: 7 }, () => Array(24).fill(0))
  for (const p of posts) {
    const d = new Date(p.ts * 1000)
    const weekday = (d.getDay() + 6) % 7
    grid[weekday][d.getHours()]++
  }
  return grid
}

function channelRow(ch, days, nowSec) {
  const posts = inWindow(ch.posts, days, nowSec)
  const maturedPosts = matured(posts, nowSec)
  const views = maturedPosts.map((p) => p.v)
  const viewsSum = views.reduce((a, b) => a + b, 0)
  const commentsSum = maturedPosts.reduce((a, p) => a + p.c, 0)

  const types = {}
  for (const ty of ['article', 'short', 'video_long']) {
    const bucket = maturedPosts.filter((p) => p.ty === ty).map((p) => p.v)
    types[ty] = { n: posts.filter((p) => p.ty === ty).length, viewsMedian: bucket.length ? median(bucket) : null }
  }

  const articles = maturedPosts.filter((p) => p.ty === 'article')
  const length = LENGTH_BUCKETS.map(([label, lo, hi]) => {
    const bucket = articles.filter((p) => p.s >= lo && p.s < hi).map((p) => p.v)
    return { bucket: label, n: bucket.length, viewsMedian: bucket.length ? median(bucket) : null }
  })

  return {
    key: ch.key,
    title: ch.meta?.title ?? ch.key,
    isOwn: ch.isOwn,
    subscribers: ch.meta?.subscribers ?? null,
    posts: posts.length,
    postsPerDay: posts.length ? Math.round((posts.length / days) * 10) / 10 : 0,
    viewsSum,
    viewsMedian: views.length ? median(views) : 0,
    viewsMean: views.length ? views.reduce((a, b) => a + b, 0) / views.length : 0,
    engagement: viewsSum > 0 ? Math.round((commentsSum / viewsSum) * 1000 * 100) / 100 : 0,
    types,
    length,
    grid: publishGrid(posts),
  }
}

function dynamics(channels, days, nowSec) {
  const byDate = new Map()
  for (const ch of channels) {
    for (const p of inWindow(ch.posts, days, nowSec)) {
      const date = new Date(p.ts * 1000).toISOString().slice(0, 10)
      if (!byDate.has(date)) byDate.set(date, {})
      const row = byDate.get(date)
      row[ch.key] = (row[ch.key] ?? 0) + 1
    }
  }
  return [...byDate.entries()].sort(([a], [b]) => (a < b ? -1 : 1)).map(([date, counts]) => ({ date, counts }))
}

/** Own против лучшего конкурента по ключевым метрикам */
function benchmark(rows) {
  const own = rows.find((r) => r.isOwn)
  const competitors = rows.filter((r) => !r.isOwn)
  if (!own || competitors.length === 0) return []

  const metrics = [
    ['viewsMedian', 'Медиана просмотров (16ч+)'],
    ['engagement', 'Комментариев на 1000 просмотров'],
    ['postsPerDay', 'Публикаций в день'],
    ['viewsSum', 'Суммарные просмотры за окно'],
  ]
  return metrics.map(([key, title]) => {
    const best = competitors.reduce((a, b) => (b[key] > a[key] ? b : a))
    return {
      metric: title,
      own: own[key],
      best: best[key],
      bestChannel: best.title,
      ratio: best[key] > 0 ? Math.round((own[key] / best[key]) * 1000) / 1000 : null,
    }
  })
}

/**
 * @param {Array<{key: string, isOwn?: boolean, meta: object, posts: array}>} channels
 * @returns {{windowDays: number, channels: array, dynamics: array, benchmark: array}}
 */
export function overview(channels, { days = 21, nowSec = Date.now() / 1000 } = {}) {
  const rows = channels.map((ch) => channelRow(ch, days, nowSec))
  rows.sort((a, b) => (b.isOwn ? 1 : 0) - (a.isOwn ? 1 : 0) || b.viewsMedian - a.viewsMedian)
  return {
    windowDays: days,
    channels: rows,
    dynamics: dynamics(channels, days, nowSec),
    benchmark: benchmark(rows),
  }
}
