// Клиент неофициального API dzen.ru/api/web/v1/channel-more.
// Порт bst-dzen/api/app/Services/Dzen/{DzenApiClient,DzenCrawler}.php.

const API_URL = 'https://dzen.ru/api/web/v1/channel-more'
const BASE_PARAMS = { sort_type: 'regular', country_code: 'ru', clid: '1400', lang: 'ru' }
const PAGE_PAUSE_MS = 700
const MAX_PAGES = 200

/** tab floor-контейнера -> тип публикации */
const TAB_TYPES = { longs: 'video_long', shorts: 'short' }

/** прямые item type из API -> тип публикации */
const ITEM_TYPES = { gif: 'video_long', short_video: 'short', short_video_compact: 'short' }

async function fetchPage(extra, fetchImpl) {
  const url = API_URL + '?' + new URLSearchParams({ ...BASE_PARAMS, ...Object.fromEntries(Object.entries(extra).filter(([, v]) => v != null)) })
  const res = await fetchImpl(url, { credentials: 'include' })
  if (res.status === 429 || res.status === 403) {
    throw new Error(`Дзен отклонил запрос: HTTP ${res.status} (троттлинг?)`)
  }
  if (!res.ok) {
    throw new Error(`HTTP ${res.status} от Дзен API`)
  }
  // API иногда отдаёт сырые control-символы внутри строк — JSON.parse такое не ест
  const text = await res.text()
  return JSON.parse(text.replace(/[\x00-\x1f]+/g, ' '))
}

/** Блок source item'а несёт мету канала (название, подписчики) */
function captureMeta(item, metaRef) {
  if (metaRef.meta) return
  const subscribers = Number(item.source?.subscribers ?? 0)
  if (subscribers > 0) {
    metaRef.meta = {
      title: String(item.source.title ?? '').trim(),
      subscribers,
    }
  }
}

function makeRow(item, ty) {
  const lead = String(item.text ?? '').trim().slice(0, 200)
  const url = String(item.shareLink || item.link || '').split('?')[0]
  return {
    ty,
    t: String(item.title ?? '').trim(),
    ld: lead || null,
    u: url,
    ts: item.publicationDate ? Number(item.publicationDate) : 0,
    v: Number(item.views ?? 0),
    c: Number(item.socialInfo?.commentCount ?? 0),
    s: Number(item.timeToReadSeconds || item.video?.duration || 0),
  }
}

/** @return {{rows: array, meta: object|null}} публикации страницы + мета канала (если встретилась) */
export function extractRows(payload, metaRef = { meta: null }) {
  const rows = []
  for (const item of payload?.items ?? []) {
    if (!item || typeof item !== 'object') continue
    captureMeta(item, metaRef)

    const type = String(item.type ?? '')
    if (type.startsWith('channel_') && item.items) {
      const kind = TAB_TYPES[item.tab] ?? type
      for (const video of item.items) {
        if (video && typeof video === 'object') {
          captureMeta(video, metaRef)
          const row = makeRow(video, kind)
          if (row.t !== '') rows.push(row)
        }
      }
      continue
    }

    const kind = type === 'article' ? 'article' : (ITEM_TYPES[type] ?? type)
    const row = makeRow(item, kind)
    if (row.t !== '') rows.push(row)
  }
  return rows
}

/** next_page_id из more.link, либо null */
export function nextPageId(payload) {
  const link = String(payload?.more?.link ?? '')
  if (!link) return null
  try {
    const next = new URL(link, 'https://dzen.ru').searchParams.get('next_page_id')
    return next && next !== '' ? next : null
  } catch {
    return null
  }
}

function dedup(rows) {
  const unique = new Map()
  for (const r of rows) unique.set(r.u !== '' ? r.u : r.t, r)
  return [...unique.values()]
}

/**
 * Цикл пагинации: стоп — пустая страница, нет next_page_id, 2 подряд страницы без дат
 * или страница ЦЕЛИКОМ ушла за окно (лента не строго хронологична: закреплённые
 * посты со старыми датами не должны обрывать обход — фикс из bst-dzen).
 */
async function paginate(fetchByCursor, initialCursor, { days, onProgress }) {
  const cutoff = Math.floor(Date.now() / 1000) - days * 86400
  const rows = []
  const metaRef = { meta: null }
  let undatedPages = 0
  let cursor = initialCursor

  for (let page = 1; page <= MAX_PAGES; page++) {
    const payload = await fetchByCursor(cursor)
    const pageRows = extractRows(payload, metaRef)
    for (const r of pageRows) {
      if (r.ts >= cutoff) rows.push(r)
    }

    const dated = pageRows.map((r) => r.ts).filter((t) => t > 0)
    const oldest = dated.length ? Math.min(...dated) : 0
    const newest = dated.length ? Math.max(...dated) : 0
    undatedPages = dated.length ? 0 : undatedPages + 1

    if (onProgress) onProgress(page, pageRows.length, oldest)

    await new Promise((resolve) => setTimeout(resolve, PAGE_PAUSE_MS))

    const next = nextPageId(payload)
    if (pageRows.length === 0 || (newest && newest < cutoff) || !next || undatedPages >= 2) break
    cursor = next
  }

  return { meta: metaRef.meta, posts: dedup(rows) }
}

/**
 * Собрать публикации канала за последние days дней.
 * @param {string} key имя канала (dzen.ru/<key>) или 24-hex id
 * @param {{mode?: 'name'|'id', days?: number, onProgress?: Function, fetchImpl?: Function}} opts
 * @returns {Promise<{meta: {title: string, subscribers: number}|null, posts: array}>}
 */
export async function fetchChannel(key, { mode = 'name', days = 21, onProgress, fetchImpl = fetch } = {}) {
  if (mode === 'id') {
    const all = []
    let meta = null
    for (const tab of ['articles', 'longs', 'shorts']) {
      const nowMs = Date.now()
      const res = await paginate(
        (cursor) => fetchPage({ channel_id: key, tab, next_page_id: cursor }, fetchImpl),
        `-1/${nowMs}`,
        { days, onProgress },
      )
      all.push(...res.posts)
      meta = meta || res.meta
    }
    return { meta, posts: dedup(all) }
  }

  const nowMs = Date.now()
  const cursor = `articles/-1/${nowMs},longs/-1/${nowMs},shorts/3/${nowMs}`
  return paginate(
    (next) => fetchPage({ channel_name: key, next_page_id: next }, fetchImpl),
    cursor,
    { days, onProgress },
  )
}

/** Лёгкий запрос одной страницы — только мета канала (для плашки) */
export async function fetchChannelMeta(key, { mode = 'name', fetchImpl = fetch } = {}) {
  const nowMs = Date.now()
  const extra = mode === 'id'
    ? { channel_id: key, tab: 'articles', next_page_id: `-1/${nowMs}` }
    : { channel_name: key, next_page_id: `articles/-1/${nowMs},longs/-1/${nowMs},shorts/3/${nowMs}` }
  const payload = await fetchPage(extra, fetchImpl)
  const metaRef = { meta: null }
  extractRows(payload, metaRef)
  return metaRef.meta
}
