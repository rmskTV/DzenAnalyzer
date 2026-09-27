import { test } from 'node:test'
import assert from 'node:assert/strict'
import { useStorage, getSettings, saveSettings, upsertChannel, getChannels, removeChannel, getScope, prunePosts } from '../src/lib/store.js'

function memoryStorage() {
  let bag = {}
  return {
    get: async (key) => {
      const keys = Array.isArray(key) ? key : [key]
      return Object.fromEntries(keys.filter((k) => k in bag).map((k) => [k, bag[k]]))
    },
    set: async (obj) => {
      bag = { ...bag, ...obj }
    },
    dump: () => bag,
  }
}

const now = Date.now() / 1000
const post = (ts, v) => ({ ts, v, t: `t${ts}`, u: `u${ts}`, ty: 'article' })

test('upsertChannel: мерж по url, снапшот подписчиков раз в день, обрезка 30 дней', async () => {
  const mem = memoryStorage()
  useStorage(mem)

  await upsertChannel('own', {
    meta: { title: 'Мы', subscribers: 100 },
    posts: [post(now - day, 10), post(now - 2 * day, 20)],
  })
  // повторный сбор: тот же url с новыми просмотрами + новый пост + старьё за окном
  await upsertChannel('own', {
    meta: { title: 'Мы', subscribers: 110 },
    posts: [post(now - day, 15), post(now - 3 * day, 30), post(now - 40 * day, 9999)],
  })

  const channels = await getChannels()
  const ch = channels.own
  assert.equal(ch.posts.length, 3, 'мерж по url + обрезка 30 дней')
  assert.equal(ch.posts.find((p) => p.ts === now - day).v, 15, 'просмотры обновились')
  assert.equal(ch.subscribersHistory.length, 1)
  assert.equal(ch.subscribersHistory[0].n, 110)

  await saveSettings({ own: 'own', competitors: ['own2'] })
  await removeChannel('own2')
  assert.deepEqual((await getSettings()).competitors, [])
  const scope = await getScope()
  assert.deepEqual(Object.keys(scope.channels), ['own'])
})

const day = 86400

test('prunePosts режет по окну', () => {
  const posts = [post(now - day, 1), post(now - 29 * day, 2), post(now - 31 * day, 3)]
  assert.equal(prunePosts(posts).length, 2)
})
