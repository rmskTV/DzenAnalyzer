// Кластеризация событий: одна новость у нескольких каналов.
// Порт bst-dzen/api/app/Services/Analysis/EventClusterer.php:
// токенный Жаккар >= 0.3, окно ±48 ч, union-find; + дуэли, покрытие, кривая затухания.

const THRESHOLD = 0.30
const WINDOW_SEC = 48 * 3600

const STOP = new Set(
  ('в во и на из за по с со к у о об от до для что как это этот эта эти ' +
    'был была были будет быть он она они мы вы я не ни же бы ли а но или ' +
    'году года ещё еще уже там тогда который которая которые').split(' '),
)

/** Значимые токены заголовка+лида */
export function tokens(post) {
  const text = ((post.t ?? '') + ' ' + (post.ld ?? '')).toLowerCase().replace(/ё/g, 'е')
  const matches = text.match(/[a-zа-я0-9]+/g) ?? []
  return matches.filter((t) => t.length > 1 && !STOP.has(t))
}

export function jaccard(ta, tb) {
  if (ta.length === 0 || tb.length === 0) return 0
  const setA = new Set(ta)
  let inter = 0
  for (const t of new Set(tb)) {
    if (setA.has(t)) inter++
  }
  return inter / (setA.size + new Set(tb).size - inter)
}

/**
 * Построить кластеры событий за окно.
 * @param {Array<{key: string, posts: array}>} channels свой + конкуренты
 * @returns {Array<{id: number, firstTs: number, nChannels: number, title: string,
 *   members: Array<{key: string, post: object, delayMin: number}>}>}
 */
export function buildEvents(channels, { days = 21, nowSec = Date.now() / 1000 } = {}) {
  const since = nowSec - days * 86400
  const items = []
  for (const ch of channels) {
    for (const p of ch.posts) {
      if (p.ts >= since && p.ts > 0) items.push({ key: ch.key, p })
    }
  }
  items.sort((a, b) => a.p.ts - b.p.ts)

  const tokenCache = new Map()
  const toks = (item) => {
    if (!tokenCache.has(item.p)) tokenCache.set(item.p, tokens(item.p))
    return tokenCache.get(item.p)
  }

  const n = items.length
  const parent = Array.from({ length: n }, (_, i) => i)
  const find = (x) => {
    while (parent[x] !== x) {
      parent[x] = parent[parent[x]]
      x = parent[x]
    }
    return x
  }

  for (let i = 0; i < n; i++) {
    for (let j = i + 1; j < n; j++) {
      if (items[j].p.ts - items[i].p.ts > WINDOW_SEC) break
      if (items[i].key === items[j].key) continue
      if (jaccard(toks(items[i]), toks(items[j])) >= THRESHOLD) {
        const ra = find(i)
        const rb = find(j)
        if (ra !== rb) parent[rb] = ra
      }
    }
  }

  const groups = new Map()
  for (let i = 0; i < n; i++) {
    const root = find(i)
    if (!groups.has(root)) groups.set(root, [])
    groups.get(root).push(items[i])
  }

  const clusters = []
  for (const members of groups.values()) {
    const keys = new Set(members.map((m) => m.key))
    if (keys.size < 2) continue
    members.sort((a, b) => a.p.ts - b.p.ts)
    const firstTs = members[0].p.ts
    clusters.push({
      id: clusters.length + 1,
      firstTs,
      nChannels: keys.size,
      title: members[0].p.t,
      members: members.map((m) => ({ key: m.key, post: m.p, delayMin: Math.round((m.p.ts - firstTs) / 60) })),
    })
  }
  clusters.sort((a, b) => b.firstTs - a.firstTs)
  return clusters
}

/**
 * Дуэли инфоповодов: кластеры с участием own, own против лучшего по просмотрам.
 * @returns {{totalEvents: number, duelsCount: number, wins: number, duels: array}}
 */
export function duels(clusters, ownKey, { limit = 50 } = {}) {
  const withOwn = clusters.filter((c) => c.members.some((m) => m.key === ownKey))
  const rows = []
  let wins = 0
  for (const cluster of withOwn) {
    const own = cluster.members.find((m) => m.key === ownKey)
    const rivals = cluster.members.filter((m) => m.key !== ownKey)
    if (!own || rivals.length === 0) continue
    const best = rivals.reduce((a, b) => (b.post.v > a.post.v ? b : a))
    if (own.post.v >= best.post.v) wins++
    rows.push({
      id: cluster.id,
      firstTs: cluster.firstTs,
      win: own.post.v >= best.post.v,
      ratio: best.post.v > 0 ? Math.round((own.post.v / best.post.v) * 100) / 100 : null,
      own,
      best,
    })
  }
  return {
    totalEvents: clusters.length,
    duelsCount: rows.length,
    wins,
    duels: rows.slice(0, limit),
  }
}

/** Сводка покрытия/скорости по каналам */
export function coverage(clusters, channelsMeta) {
  const total = clusters.length
  const byChannel = new Map()
  for (const cluster of clusters) {
    for (const m of cluster.members) {
      if (!byChannel.has(m.key)) byChannel.set(m.key, { key: m.key, clusterIds: new Set(), delays: [], first: 0 })
      const row = byChannel.get(m.key)
      row.clusterIds.add(cluster.id)
      row.delays.push(m.delayMin)
      if (m.delayMin === 0) row.first++
    }
  }
  return [...byChannel.values()]
    .map((row) => ({
      key: row.key,
      title: channelsMeta[row.key]?.title ?? row.key,
      isOwn: channelsMeta[row.key]?.isOwn ?? false,
      nEvents: row.clusterIds.size,
      share: total > 0 ? Math.round((row.clusterIds.size / total) * 1000) / 10 : 0,
      medianDelayMin: row.delays.length ? Math.round([...row.delays].sort((a, b) => a - b)[Math.floor(row.delays.length / 2)]) : 0,
      firstCount: row.first,
    }))
    .sort((a, b) => b.nEvents - a.nEvents)
}

const DELAY_BUCKETS = [
  ['0–1 ч', 0, 3600],
  ['1–2 ч', 3600, 7200],
  ['2–6 ч', 7200, 21600],
  ['6–24 ч', 21600, 86400],
  ['>24 ч', 86400, Infinity],
]

/**
 * Кривая затухания: медианная доля от лидера (просмотры) в зависимости
 * от задержки own-поста. Считается по кластерам с участием own.
 */
export function decayCurve(clusters, ownKey) {
  const shares = DELAY_BUCKETS.map(() => [])
  for (const cluster of clusters) {
    const own = cluster.members.find((m) => m.key === ownKey)
    if (!own) continue
    const leader = Math.max(...cluster.members.map((m) => m.post.v))
    if (leader <= 0) continue
    const delaySec = own.delayMin * 60
    const share = (own.post.v / leader) * 100
    for (let i = 0; i < DELAY_BUCKETS.length; i++) {
      if (delaySec >= DELAY_BUCKETS[i][1] && delaySec < DELAY_BUCKETS[i][2]) {
        shares[i].push(share)
        break
      }
    }
  }
  const median = (values) => {
    if (!values.length) return null
    const sorted = [...values].sort((a, b) => a - b)
    return Math.round(sorted[Math.floor(sorted.length / 2)] * 10) / 10
  }
  return DELAY_BUCKETS.map(([label], i) => ({ bucket: label, n: shares[i].length, medianShare: median(shares[i]) }))
}
