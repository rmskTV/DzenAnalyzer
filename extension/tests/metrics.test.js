import { test } from 'node:test'
import assert from 'node:assert/strict'
import { median, matured, overview } from '../src/lib/metrics.js'

const now = 1_800_000_000
const day = 86400
const post = (ts, v, ty = 'article') => ({ ts, v, c: 0, ty, t: `t${ts}-${v}`, u: `u-${ts}-${v}`, s: 60 })

test('median считает как MetricsService (floor(n/2) по сортировке)', () => {
  assert.equal(median([]), 0)
  assert.equal(median([5]), 5)
  assert.equal(median([1, 2, 100]), 2)
  assert.equal(median([1, 2, 3, 100]), 3)
})

test('matured отбрасывает посты моложе 16 часов', () => {
  const posts = [post(now - 100, 10), post(now - 17 * 3600, 20), post(now - 3 * day, 30)]
  assert.deepEqual(matured(posts, now).map((p) => p.v), [20, 30])
})

test('overview: сводка, сетка, бенчмарк', () => {
  const own = {
    key: 'own',
    isOwn: true,
    meta: { title: 'Мы', subscribers: 1000 },
    posts: [
      post(now - 2 * day, 100), post(now - 3 * day, 200), post(now - 19 * day, 50),
      post(now - 100, 999), // незрелый — не в охватных
    ],
  }
  const rival = {
    key: 'rival',
    meta: { title: 'Конкурент', subscribers: 5000 },
    posts: [post(now - 2 * day, 400), post(now - 3 * day, 600), post(now - 6 * day, 4)],
  }
  const result = overview([own, rival], { days: 21, nowSec: now })

  assert.equal(result.channels.length, 2)
  const ownRow = result.channels.find((r) => r.isOwn)
  const rivalRow = result.channels.find((r) => !r.isOwn)
  assert.equal(ownRow.posts, 4)
  assert.equal(ownRow.viewsMedian, 100) // 100, 200, 50 (незрелый исключён)
  assert.equal(rivalRow.viewsMedian, 400)
  assert.equal(ownRow.types.article.n, 4)
  assert.equal(ownRow.grid.length, 7)
  assert.ok(ownRow.grid.every((row) => row.length === 24))
  assert.equal(ownRow.grid.flat().reduce((a, b) => a + b, 0), 4)

  assert.equal(result.dynamics.length > 0, true)
  const bm = result.benchmark
  assert.equal(bm.length, 4)
  const bmMedian = bm.find((r) => r.metric.includes('Медиана'))
  assert.equal(bmMedian.bestChannel, 'Конкурент')
  assert.equal(bmMedian.ratio, 0.25)
})
