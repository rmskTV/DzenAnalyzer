import { test } from 'node:test'
import assert from 'node:assert/strict'
import { headlineStats } from '../src/lib/headlines.js'

const now = 1_800_000_000
const day = 86400
const art = (title, v) => ({ t: title, v, ts: now - 2 * day, ty: 'article', u: `u-${title}` })

test('lift-таблица: вопросные заголовки против обычных', () => {
  const channels = [
    {
      key: 'own',
      posts: [
        art('Почему в городе отменили маршруты?', 900),
        art('Что нового в школе?', 1100),
        art('Отменены несколько маршрутов', 100),
        art('В школе ремонт', 100),
        art('Город: сводка новостей', 300),
      ],
    },
  ]
  const stats = headlineStats(channels, { days: 21, nowSec: now })
  const question = stats.set.find((r) => r.feature === 'вопрос ?')
  assert.equal(question.withN, 2)
  assert.equal(question.withoutN, 3)
  assert.equal(question.withMedian, 1100)
  assert.equal(question.withoutMedian, 100)
  assert.equal(question.lift, 11)

  const colon = stats.set.find((r) => r.feature === 'двоеточие')
  assert.equal(colon.withN, 1)

  const digits = stats.set.find((r) => r.feature === 'цифры')
  assert.equal(digits.withN, 0)
  assert.equal(digits.lift, null)

  assert.equal(stats.top[0].views, 1100)
  assert.ok(stats.set.avgLen > 0)
})

test('незрелые и не-статьи в расчёт не попадают', () => {
  const channels = [
    {
      key: 'own',
      posts: [
        art('Заголовок зрелый', 500),
        { ...art('Незрелый пост', 9999), ts: now - 100 },
        { ...art('Шортс', 9999), ty: 'short' },
      ],
    },
  ]
  const stats = headlineStats(channels, { days: 21, nowSec: now })
  assert.equal(stats.set.find((r) => r.feature === 'вопрос ?').withN + stats.set.find((r) => r.feature === 'вопрос ?').withoutN, 1)
})
