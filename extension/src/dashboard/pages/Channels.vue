<template>
  <div>
    <div class="card">
      <div class="row">
        <input
          v-model="newKey" type="text" placeholder="Ключ канала или ссылка: prmira, dzen.ru/gorodprima.ru"
          @keyup.enter="addAndFetch()"
        />
        <button class="primary" :disabled="busy || !newKey.trim()" @click="addAndFetch()">Добавить и собрать</button>
        <button class="ghost" :disabled="busy || rows.length === 0" @click="fetchAll()">Обновить всё</button>
        <span v-if="busy" class="muted">{{ busyLabel }}</span>
      </div>
      <div v-if="busy" class="progress"><div :style="{ width: progressPct + '%' }"></div></div>
      <p class="muted small">
        Сбор идёт из браузера по API Дзена (пауза 0.7с между страницами, ~15 сек на канал). История постов хранится 30 дней.
      </p>
    </div>

    <div class="card">
      <h3>Каналы ({{ rows.length }})</h3>
      <p v-if="!rows.length" class="muted">Пока пусто. Добавьте свой канал и 2–5 конкурентов.</p>
      <div v-for="r in rows" :key="r.key" class="chrow">
        <div>
          <div class="title">
            <span v-if="r.key === settings.own">★ </span>{{ r.title }}
            <span class="muted">({{ r.key }})</span>
            <span v-if="isCompetitor(r.key)" class="muted"> · конкурент</span>
          </div>
          <div class="muted small">
            {{ fmtSubs(r.subscribers) }} подписчиков · {{ r.posts }} постов в базе · собрано {{ fmtTime(r.fetchedAt) }}
            <template v-if="r.history.length > 1">
              · подписчики: <span :class="subsDelta(r) >= 0 ? 'pos' : 'neg'">{{ subsDelta(r) >= 0 ? '+' : '' }}{{ subsDelta(r) }} за {{ r.history.length }} дн.</span>
            </template>
          </div>
          <canvas v-if="r.history.length > 1" :ref="(el) => registerSpark(r.key, el)" width="220" height="36" style="max-height:none; margin-top:6px"></canvas>
        </div>
        <div class="row">
          <button class="ghost" :class="{ active: r.key === settings.own }" :disabled="busy" @click="setOwn(r.key)">
            {{ r.key === settings.own ? '★ Свой' : 'Сделать своим' }}
          </button>
          <button class="ghost" :class="{ active: isCompetitor(r.key) }" :disabled="busy" @click="toggleCompetitor(r.key)">
            {{ isCompetitor(r.key) ? '− Конкурент' : '+ Конкурент' }}
          </button>
          <button class="ghost" :disabled="busy" @click="fetchOne(r.key)">Собрать</button>
          <button class="ghost" :disabled="busy" @click="remove(r.key)">Удалить</button>
        </div>
      </div>
    </div>

    <div class="card" v-if="settings.own">
      <h3>Готово к анализу</h3>
      <div class="row">
        <span>Свой: <b>★ {{ ownTitle }}</b></span>
        <span class="muted">конкурентов: {{ settings.competitors.length }}</span>
        <button class="primary" @click="$emit('analysis')">Открыть анализ →</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, ref } from 'vue'
import { fetchChannel } from '../../lib/dzenApi.js'
import { getChannels, getSettings, saveSettings, upsertChannel, removeChannel } from '../../lib/store.js'

const emit = defineEmits(['updated', 'analysis'])

const rows = ref([])
const settings = ref({ own: null, competitors: [] })
const newKey = ref('')
const busy = ref(false)
const busyLabel = ref('')
const progressPct = ref(0)

const sparks = new Map()
const ownTitle = computed(() => rows.value.find((r) => r.key === settings.value.own)?.title ?? '')

function parseKey(raw) {
  let value = raw.trim()
  if (value.includes('dzen.ru')) {
    try {
      value = new URL(value.startsWith('http') ? value : 'https://' + value).pathname
    } catch {
      /* оставляем как есть */
    }
  }
  value = value.replace(/^\/+/, '').replace(/\/+$/, '')
  if (value.startsWith('channel/')) value = value.slice('channel/'.length)
  if (!value || value.includes('/')) return null
  const mode = /^[0-9a-f]{24}$/.test(value) ? 'id' : 'name'
  return { key: value, mode }
}

async function reload() {
  const [channels, s] = await Promise.all([getChannels(), getSettings()])
  settings.value = s
  rows.value = Object.entries(channels)
    .map(([key, data]) => ({
      key,
      title: data.meta?.title ?? key,
      subscribers: data.meta?.subscribers ?? null,
      posts: data.posts.length,
      fetchedAt: data.postsFetchedAt,
      history: data.subscribersHistory ?? [],
    }))
    .sort((a, b) => (a.key === s.own ? -1 : 0) - (b.key === s.own ? -1 : 0))
  await nextTick()
  drawSparks()
}

function registerSpark(key, el) {
  if (el) sparks.set(key, el)
}

function drawSparks() {
  for (const r of rows.value) {
    if (r.history.length < 2) continue
    const canvas = sparks.get(r.key)
    if (!canvas) continue
    const ctx = canvas.getContext('2d')
    const values = r.history.map((h) => h.n)
    const min = Math.min(...values)
    const max = Math.max(...values)
    ctx.clearRect(0, 0, canvas.width, canvas.height)
    ctx.strokeStyle = '#1d4ed8'
    ctx.lineWidth = 1.5
    ctx.beginPath()
    values.forEach((v, i) => {
      const x = (i / (values.length - 1)) * (canvas.width - 8) + 4
      const y = canvas.height - 4 - ((v - min) / (max - min || 1)) * (canvas.height - 8)
      if (i === 0) ctx.moveTo(x, y)
      else ctx.lineTo(x, y)
    })
    ctx.stroke()
  }
}

function isCompetitor(key) {
  return settings.value.competitors.includes(key)
}

function subsDelta(r) {
  const h = r.history
  return h.length > 1 ? h[h.length - 1].n - h[0].n : 0
}

function fmtSubs(v) {
  return v == null ? '—' : Number(v).toLocaleString('ru-RU')
}

function fmtTime(ts) {
  return ts ? new Date(ts).toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '—'
}

async function collect(key, mode) {
  const res = await fetchChannel(key, {
    mode,
    days: 21,
    onProgress: (page, rowsOnPage, oldest) => {
      busyLabel.value = `${key}: стр. ${page}, ${rowsOnPage} постов${oldest ? `, до ${new Date(oldest * 1000).toLocaleDateString('ru-RU')}` : ''}`
    },
  })
  await upsertChannel(key, res)
  return res.posts.length
}

async function addAndFetch() {
  const parsed = parseKey(newKey.value)
  if (!parsed) {
    busyLabel.value = 'Не похоже на ключ канала'
    return
  }
  busy.value = true
  try {
    const n = await collect(parsed.key, parsed.mode)
    newKey.value = ''
    await reload()
    emitUpdated()
    busyLabel.value = `${parsed.key}: собрано ${n} постов`
  } catch (e) {
    busyLabel.value = `Ошибка: ${e.message}`
  } finally {
    busy.value = false
    progressPct.value = 0
  }
}

async function fetchOne(key) {
  busy.value = true
  try {
    await collect(key, /^[0-9a-f]{24}$/.test(key) ? 'id' : 'name')
    await reload()
    emitUpdated()
  } catch (e) {
    busyLabel.value = `Ошибка: ${e.message}`
  } finally {
    busy.value = false
  }
}

async function fetchAll() {
  const keys = rows.value.map((r) => r.key)
  busy.value = true
  try {
    for (let i = 0; i < keys.length; i++) {
      progressPct.value = Math.round((i / keys.length) * 100)
      busyLabel.value = `Обновление ${keys[i]} (${i + 1}/${keys.length})`
      await collect(keys[i], /^[0-9a-f]{24}$/.test(keys[i]) ? 'id' : 'name')
      await reload()
    }
    progressPct.value = 100
    emitUpdated()
  } catch (e) {
    busyLabel.value = `Ошибка: ${e.message}`
  } finally {
    busy.value = false
    setTimeout(() => (progressPct.value = 0), 1500)
  }
}

async function setOwn(key) {
  settings.value = { ...settings.value, own: key }
  await saveSettings(settings.value)
  emitUpdated()
}

async function toggleCompetitor(key) {
  const list = settings.value.competitors.filter((k) => k !== key)
  if (!isCompetitor(key)) list.push(key)
  settings.value = { ...settings.value, competitors: list }
  await saveSettings(settings.value)
  emitUpdated()
}

async function remove(key) {
  if (!confirm(`Удалить канал ${key} и его посты из базы расширения?`)) return
  busy.value = true
  try {
    await removeChannel(key)
    await reload()
    emitUpdated()
  } finally {
    busy.value = false
  }
}

function emitUpdated() {
  emit('updated')
}

onMounted(reload)
</script>
