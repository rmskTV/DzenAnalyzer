// Анализ заголовков: признак -> медиана просмотров с признаком vs без (lift).
// Порт analyze.py (прототип), только созревшие статьи.

const FEATURES = {
  'двоеточие': (t) => t.includes(':'),
  'кавычки «»': (t) => t.includes('«'),
  'цифры': (t) => /\d/.test(t),
  'вопрос ?': (t) => t.includes('?'),
  'emoji': (t) => /\p{So}/u.test(t),
  'как/почему/что': (t) => /(?<![а-яёa-z])(как|почему|что|зачем|чем)(?![а-яёa-z])/i.test(t),
}

const LENGTH_BUCKETS = [
  ['короткий <40', 0, 40],
  ['средний 40–60', 40, 60],
  ['длинный >60', 60, Infinity],
]

function featureRows(articles) {
  const titles = articles.map((p) => p.t)
  const viewsOf = (p) => p.v
  const rows = []

  const build = (name, predicate) => {
    const withFeat = articles.filter((p) => predicate(p.t))
    const without = articles.filter((p) => !predicate(p.t))
    const med = (arr) => (arr.length ? [...arr].sort((a, b) => a - b)[Math.floor(arr.length / 2)] : null)
    const withMedian = med(withFeat.map(viewsOf))
    const withoutMedian = med(without.map(viewsOf))
    rows.push({
      feature: name,
      withN: withFeat.length,
      withoutN: without.length,
      sharePct: articles.length ? Math.round((withFeat.length / articles.length) * 1000) / 10 : 0,
      withMedian,
      withoutMedian,
      lift: withMedian != null && withoutMedian ? Math.round((withMedian / withoutMedian) * 100) / 100 : null,
    })
  }

  for (const [name, predicate] of Object.entries(FEATURES)) build(name, predicate)
  for (const [name, lo, hi] of LENGTH_BUCKETS) build(name, (t) => t.length >= lo && t.length < hi)

  const lens = titles.map((t) => t.length)
  rows.avgLen = lens.length ? Math.round(lens.reduce((a, b) => a + b, 0) / lens.length) : 0
  rows.medianLen = lens.length ? [...lens].sort((a, b) => a - b)[Math.floor(lens.length / 2)] : 0
  return rows
}

/**
 * @param {Array<{key: string, isOwn?: boolean, posts: array}>} channels
 * @returns {{set: array, byChannel: {[key]: array}, top: array}}
 */
export function headlineStats(channels, { days = 21, nowSec = Date.now() / 1000, topLimit = 10 } = {}) {
  const since = nowSec - days * 86400
  const maturityCutoff = nowSec - 16 * 3600
  const articlesOf = (posts) => posts.filter((p) => p.ty === 'article' && p.ts >= since && p.ts <= maturityCutoff && p.t)

  const all = []
  const byChannel = {}
  for (const ch of channels) {
    const arts = articlesOf(ch.posts)
    all.push(...arts)
    byChannel[ch.key] = { n: arts.length, features: featureRows(arts) }
  }

  const top = [...all]
    .sort((a, b) => b.v - a.v)
    .slice(0, topLimit)
    .map((p) => ({ title: p.t, views: p.v, url: p.u, ts: p.ts }))

  return { set: featureRows(all), byChannel, top }
}
