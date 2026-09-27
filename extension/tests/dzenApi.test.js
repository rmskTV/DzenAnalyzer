import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { extractRows, nextPageId, fetchChannel } from '../src/lib/dzenApi.js'

const loadFixture = (name) =>
  JSON.parse(readFileSync(new URL(`./fixtures/${name}`, import.meta.url), 'utf8').replace(/[\x00-\x1f]+/g, ' '))

const mkItem = (title, tsSec, extra = {}) => ({
  type: 'article',
  title,
  publicationDate: tsSec,
  views: 100,
  shareLink: `https://dzen.ru/a/${title}`,
  ...extra,
})

test('nextPageId достаёт курсор из more.link', () => {
  const payload = { more: { link: 'https://dzen.ru/api/x?clid=1400&next_page_id=articles%2F-1%2F123%2Clongs%2F-1%2F123' } }
  assert.equal(nextPageId(payload), 'articles/-1/123,longs/-1/123')
  assert.equal(nextPageId({ more: { link: '' } }), null)
  assert.equal(nextPageId({}), null)
})

test('extractRows на реальной фикстуре: строки + мета канала', () => {
  const payload = loadFixture('gorodprima_p1.json')
  const metaRef = { meta: null }
  const rows = extractRows(payload, metaRef)
  assert.ok(rows.length >= 15, `rows: ${rows.length}`)
  assert.ok(metaRef.meta?.subscribers > 0, 'subscribers captured')
  assert.ok(metaRef.meta.title.length > 0, 'title captured')
  for (const r of rows) {
    assert.ok(r.t.length > 0)
    assert.equal(typeof r.ts, 'number')
    assert.equal(typeof r.v, 'number')
  }
})

test('закреплённый старый пост не обрывает пагинацию (регресс)', async () => {
  const day = 86400
  const now = Math.floor(Date.now() / 1000)
  const page1 = { items: [mkItem('fresh1', now - day), mkItem('fresh2', now - 2 * day), mkItem('pinned-old', now - 40 * day)] }
  const page2 = { items: [mkItem('mid1', now - 3 * day), mkItem('mid2', now - 4 * day), mkItem('mid3', now - 5 * day)] }
  const page3 = { items: [mkItem('old1', now - 30 * day), mkItem('old2', now - 31 * day)] } // целиком за окном

  const pages = [
    { ...page1, more: { link: '?next_page_id=p2' } },
    { ...page2, more: { link: '?next_page_id=p3' } },
    page3,
  ]
  const urls = []
  const fetchImpl = async (url) => {
    urls.push(url)
    return { ok: true, status: 200, text: async () => JSON.stringify(pages.shift()) }
  }

  const { posts } = await fetchChannel('test', { days: 21, fetchImpl })
  assert.deepEqual(
    posts.map((p) => p.t).sort(),
    ['fresh1', 'fresh2', 'mid1', 'mid2', 'mid3'],
    'посты середины окна со страницы 2 не потерялись, pinned-old отброшен',
  )
  assert.equal(urls.length, 3)
})

test('останова нет, пока на странице есть хоть один пост в окне', async () => {
  const now = Math.floor(Date.now() / 1000)
  const day = 86400
  const page1 = {
    items: [mkItem('old-pin', now - 40 * day), mkItem('fresh', now - day)],
    more: { link: '?next_page_id=p2' },
  }
  const page2 = { items: [mkItem('old-only', now - 30 * day)] } // нет next -> стоп
  const pages = [page1, page2]
  const fetchImpl = async () => ({ ok: true, status: 200, text: async () => JSON.stringify(pages.shift()) })
  const { posts } = await fetchChannel('test', { days: 21, fetchImpl })
  assert.deepEqual(posts.map((p) => p.t), ['fresh'])
})

test('fetchChannel по реальным фикстурам: две страницы и остановка', async () => {
  const p1 = readFileSync(new URL('./fixtures/gorodprima_p1.json', import.meta.url), 'utf8')
  const p2 = readFileSync(new URL('./fixtures/gorodprima_p2.json', import.meta.url), 'utf8')
  const pages = [p1, p2, '{"items":[]}']
  const fetchImpl = async () => ({ ok: true, status: 200, text: async () => pages.shift() })
  const { meta, posts } = await fetchChannel('gorodprima.ru', { days: 21, fetchImpl })
  assert.ok(meta.subscribers > 0)
  assert.ok(posts.length >= 30, `posts: ${posts.length}`)
  const urls = posts.map((p) => p.u)
  assert.equal(new Set(urls).size, urls.length, 'дедуп по url между страницами')
})
