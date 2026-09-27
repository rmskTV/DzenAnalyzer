import { test } from 'node:test'
import assert from 'node:assert/strict'
import { tokens, jaccard, buildEvents, duels, coverage, decayCurve } from '../src/lib/events.js'

const now = 1_800_000_000
const day = 86400
const post = (key, title, ts, v, lead = '') => ({ key, t: title, ld: lead, ts, v, c: 0, ty: 'article', u: `${key}-${ts}-${title}` })

test('токены: lower, ё→е, стоп-слова, одиночные символы', () => {
  const t = tokens({ t: 'В Красноярске ёлка!', ld: 'город' })
  assert.deepEqual(t, ['красноярске', 'елка', 'город'])
  assert.deepEqual(tokens({ t: 'как', ld: '' }), [])
})

test('жаккар на общих словах', () => {
  const a = ['красноярск', 'метро', 'тоннель']
  const b = ['красноярск', 'метро', 'построили']
  assert.ok(jaccard(a, b) > 0.3)
  assert.equal(jaccard([], ['a']), 0)
})

test('кластеризация: одна новость у двух каналов, delayMin от первого', () => {
  const channels = [
    { key: 'own', posts: [post('own', 'В Красноярске достроили тоннель метро', now - 2 * day, 100)] },
    {
      key: 'rival',
      posts: [post('rival', 'Тоннель метро в Красноярске достроили', now - 2 * day + 7200, 500)],
    },
    { key: 'other', posts: [post('other', 'Совсем другая новость про школу', now - 3 * day, 10)] },
  ]
  const clusters = buildEvents(channels, { days: 21, nowSec: now })
  assert.equal(clusters.length, 1)
  const cluster = clusters[0]
  assert.equal(cluster.nChannels, 2)
  assert.equal(cluster.members.length, 2)
  const rival = cluster.members.find((m) => m.key === 'rival')
  assert.equal(rival.delayMin, 120)

  const d = duels(clusters, 'own')
  assert.equal(d.duelsCount, 1)
  assert.equal(d.wins, 0)
  assert.equal(d.duels[0].ratio, 0.2)

  const cov = coverage(clusters, { own: { title: 'Мы', isOwn: true }, rival: { title: 'Конкурент' } })
  assert.equal(cov.find((r) => r.key === 'own').nEvents, 1)
  assert.equal(cov.find((r) => r.key === 'own').firstCount, 1)
  assert.equal(cov.find((r) => r.key === 'rival').medianDelayMin, 120)

  const decay = decayCurve(clusters, 'own')
  assert.equal(decay.length, 5)
  assert.equal(decay[0].bucket, '0–1 ч')
  assert.equal(decay[0].n, 1)
  assert.equal(decay[0].medianShare, 20, 'доля от лидера по просмотрам (лидер — конкурент с 500)')
  assert.equal(decay[1].n, 0)
})

test('посты одного канала не схлопываются между собой', () => {
  const channels = [
    {
      key: 'own',
      posts: [
        post('own', 'Медведи вышли к людям в пригороде', now - day, 10),
        post('own', 'Медведи вышли к людям в пригороде снова', now - day + 3600, 20),
      ],
    },
  ]
  assert.equal(buildEvents(channels, { days: 21, nowSec: now }).length, 0)
})
