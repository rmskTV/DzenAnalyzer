// Обёртка над chrome.storage.local: настройки + посты каналов + история подписчиков.
// Адаптер подменяется в тестах через useStorage().

const SETTINGS_KEY = 'settings'
const CHANNELS_KEY = 'channels'
const PRUNE_DAYS = 30

let adapter = null

/** Подменить хранилище (тесты) */
export function useStorage(fake) {
  adapter = fake
}

function area() {
  if (adapter) return adapter
  if (typeof chrome !== 'undefined' && chrome.storage?.local) return chrome.storage.local
  throw new Error('chrome.storage.local недоступен')
}

async function read(key) {
  const bag = await area().get(key)
  return bag?.[key] ?? null
}

async function write(key, value) {
  await area().set({ [key]: value })
}

export async function getSettings() {
  return (await read(SETTINGS_KEY)) ?? { own: null, competitors: [] }
}

export async function saveSettings(next) {
  await write(SETTINGS_KEY, {
    own: next.own ?? null,
    competitors: [...(next.competitors ?? [])],
  })
}

/** @returns {Promise<{[key]: {meta, postsFetchedAt, subscribersHistory, posts}}>} */
export async function getChannels() {
  return (await read(CHANNELS_KEY)) ?? {}
}

export async function getChannel(key) {
  return (await getChannels())[key] ?? null
}

/** Обрезать посты за пределами окна (по умолчанию 30 дней) */
export function prunePosts(posts, days = PRUNE_DAYS, nowSec = Date.now() / 1000) {
  const cutoff = nowSec - days * 86400
  return posts.filter((p) => p.ts >= cutoff)
}

/**
 * Записать результат сбора: посты мержатся по url, просмотры перезаписываются,
 * добавляется снапшот подписчиков на сегодня.
 */
export async function upsertChannel(key, { meta, posts }) {
  const channels = await getChannels()
  const prev = channels[key] ?? { subscribersHistory: [], posts: [] }
  const merged = new Map()
  for (const p of prev.posts ?? []) merged.set(p.u !== '' ? p.u : p.t, p)
  for (const p of posts) merged.set(p.u !== '' ? p.u : p.t, p)
  const allPosts = prunePosts([...merged.values()])

  const today = new Date().toISOString().slice(0, 10)
  const history = [...(prev.subscribersHistory ?? [])]
  if (meta?.subscribers) {
    const idx = history.findIndex((h) => h.d === today)
    const row = { d: today, n: meta.subscribers }
    if (idx >= 0) history[idx] = row
    else history.push(row)
  }

  channels[key] = {
    meta: meta ?? prev.meta ?? null,
    postsFetchedAt: Date.now(),
    subscribersHistory: history,
    posts: allPosts,
  }
  await write(CHANNELS_KEY, channels)
  return channels[key]
}

export async function removeChannel(key) {
  const channels = await getChannels()
  delete channels[key]
  await write(CHANNELS_KEY, channels)
  const settings = await getSettings()
  await saveSettings({
    own: settings.own === key ? null : settings.own,
    competitors: settings.competitors.filter((k) => k !== key),
  })
}

/** Данные скоупа: свой канал + конкуренты (для аналитики) */
export async function getScope() {
  const [settings, channels] = await Promise.all([getSettings(), getChannels()])
  const keys = [settings.own, ...settings.competitors].filter(Boolean)
  const scoped = {}
  for (const key of keys) {
    if (channels[key]) scoped[key] = channels[key]
  }
  return { settings, channels: scoped }
}
