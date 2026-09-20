<template>
  <div>
    <div class="head">
      <h1>Конкуренты</h1>
      <select v-model="selectedOwn" class="own-switch">
        <option v-for="c in ownChannels" :key="c.id" :value="c.id">★ {{ c.title }}</option>
      </select>
    </div>

    <p v-if="loading" class="muted">Загрузка…</p>
    <p v-else-if="error" style="color: #b91c1c">{{ error }}</p>

    <template v-else>
      <div class="kpis">
        <div class="card kpi">
          <div class="kpi-label">Медиана просмотров (16ч+)</div>
          <div class="kpi-value">{{ compOwn?.views_median ?? '—' }}</div>
          <div class="muted">лучший конкурент: {{ best('views_median')?.views_median ?? '—' }} ({{ best('views_median')?.title ?? '—' }})</div>
        </div>
        <div class="card kpi">
          <div class="kpi-label">Вовлечённость, комм/1000</div>
          <div class="kpi-value">{{ compOwn?.engagement ?? '—' }}</div>
          <div class="muted">{{ isBestEngagement ? 'лучший на рынке' : 'лучший: ' + (best('engagement')?.engagement ?? '—') }}</div>
        </div>
        <div class="card kpi">
          <div class="kpi-label">Публикаций/день</div>
          <div class="kpi-value">{{ compOwn?.posts_per_day ?? '—' }}</div>
          <div class="muted">лучший: {{ best('posts_per_day')?.posts_per_day ?? '—' }}</div>
        </div>
        <div class="card kpi">
          <div class="kpi-label">Событий / покрытие</div>
          <div class="kpi-value">{{ ev.total_events }}</div>
          <div class="muted">наш охват событий: {{ ownCoverage }}%</div>
        </div>
      </div>

      <div class="grid2">
        <div class="card">
          <h3>Охваты: медиана просмотров (лог)</h3>
          <canvas ref="viewsChart"></canvas>
        </div>
        <div class="card">
          <h3>Динамика публикаций (шт/день)</h3>
          <canvas ref="dynamicsChart"></canvas>
        </div>
        <div class="card">
          <div class="card-head">
            <h3>Охваты по форматам</h3>
            <select v-model="formatSource" class="own-switch">
              <option value="set">Набор в среднем</option>
              <option v-for="c in compChannels" :key="c.id" :value="String(c.id)">
                {{ c.is_own ? '★ ' : '' }}{{ c.title }}
              </option>
            </select>
          </div>
          <canvas ref="formatChart"></canvas>
        </div>
        <div class="card">
          <h3>Вовлечённость: комментариев на 1000 просмотров</h3>
          <canvas ref="engagementChart"></canvas>
        </div>
      </div>

      <div class="grid2">
        <div class="card">
          <div class="card-head">
            <h3>Длина статьи → медианный охват</h3>
            <select v-model="lengthSource" class="own-switch">
              <option value="set">Набор в среднем</option>
              <option v-for="c in compChannels" :key="c.id" :value="String(c.id)">
                {{ c.is_own ? '★ ' : '' }}{{ c.title }}
              </option>
            </select>
          </div>
          <canvas ref="lengthChart"></canvas>
          <p class="muted small">Интервалы времени чтения; под столбцом — количество материалов (n).</p>
        </div>
        <div class="card">
          <div class="card-head">
            <h3>Сетка публикаций: когда выходит контент (местное время канала)</h3>
            <select v-model="heatTarget" class="own-switch">
              <option value="set">Набор в среднем</option>
              <option v-for="g in competitive.publish_grid" :key="g.channel_id" :value="String(g.channel_id)">
                {{ g.is_own ? '★ ' : '' }}{{ g.title }}
              </option>
            </select>
          </div>
          <canvas ref="heatCanvas" class="heat"></canvas>
        </div>
      </div>

      <div class="card">
        <h3>Бенчмарк: наш канал против лучшего в наборе</h3>
        <table>
          <thead><tr><th>Метрика</th><th>Мы</th><th>Лучший конкурент</th><th>Значение</th><th>Отношение</th></tr></thead>
          <tbody>
            <tr v-for="b in competitive.benchmark" :key="b.metric">
              <td>{{ b.metric }}</td>
              <td><b>{{ b.own }}</b></td>
              <td>{{ b.best_channel }}</td>
              <td>{{ b.best }}</td>
              <td>{{ b.ratio != null ? '×' + b.ratio : '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="card">
        <h3>Покрытие и скорость по событиям</h3>
        <table>
          <thead><tr><th>Канал</th><th>Событий</th><th>Доля</th><th>Медиана отставания</th><th>Был первым</th></tr></thead>
          <tbody>
            <tr v-for="c in ev.coverage" :key="c.channel_id" :class="{ ownrow: c.is_own }">
              <td>{{ c.is_own ? '★ ' : '' }}{{ c.title }}</td>
              <td>{{ c.n_events }}</td>
              <td>{{ c.share }}%</td>
              <td>{{ c.median_delay_min }} мин</td>
              <td>{{ c.first_count }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="card">
        <h3>Дуэли инфоповодов ({{ ev.duels_count }}, побед {{ ev.wins }} — {{ winsRate }}%)</h3>
        <p v-if="!ev.duels.length" class="muted">Дуэлей нет.</p>
        <div v-for="d in ev.duels" :key="d.id" class="duel" :class="d.win ? 'won' : 'lost'">
          <div class="duel-head">
            <span class="badge" :class="d.win ? 'win' : 'lose'">{{ d.win ? 'ПОБЕДА' : 'ПРОИГРЫШ' }}</span>
            <span v-if="d.ratio != null" class="muted">охват ×{{ d.ratio }} от конкурента</span>
            <span class="muted">{{ fmtDate(d.first_published_at) }}</span>
          </div>
          <div class="post">
            <b>Мы</b> ({{ d.own.delay_min === 0 ? 'первый' : '+' + d.own.delay_min + ' мин' }}, {{ fmtViews(d.own.views) }}):
            <a v-if="d.own.url" :href="d.own.url" target="_blank">{{ d.own.title }}</a>
            <template v-else>{{ d.own.title }}</template>
          </div>
          <div v-if="d.best" class="post">
            <b>{{ d.best.channel }}</b> ({{ d.best.delay_min === 0 ? 'первый' : '+' + d.best.delay_min + ' мин' }}, {{ fmtViews(d.best.views) }}):
            <a v-if="d.best.url" :href="d.best.url" target="_blank">{{ d.best.title }}</a>
            <template v-else>{{ d.best.title }}</template>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-head">
          <h3>Топ постов каналов набора (включая наш)</h3>
          <div class="filters">
            <select v-model="selectedCompetitor" class="own-switch">
              <option v-for="c in topChannelsList" :key="c.channel_id" :value="c.channel_id">
                {{ c.is_own ? '★ ' : '' }}{{ c.title }}
              </option>
            </select>
            <select v-model="topFormat" class="own-switch">
              <option value="">Все форматы</option>
              <option v-for="f in topFilters.formats" :key="f.name" :value="f.name">
                {{ f.name }} ({{ f.n }})
              </option>
            </select>
            <select v-model="topRubric" class="own-switch">
              <option value="">Все рубрики</option>
              <option v-for="r in topFilters.rubrics" :key="r.name" :value="r.name">
                {{ r.name }} ({{ r.n }})
              </option>
            </select>
          </div>
        </div>
        <table v-if="topPosts.length">
          <thead><tr><th>Дата</th><th>Заголовок</th><th>Рубрика</th><th>Формат</th><th>Просмотры</th></tr></thead>
          <tbody>
            <tr v-for="p in topPosts" :key="p.id">
              <td>{{ p.published_at?.slice(0, 10) }}</td>
              <td class="wrap"><a v-if="p.url" :href="p.url" target="_blank">{{ p.title }}</a>
                <template v-else>{{ p.title }}</template></td>
              <td>{{ p.rubric || '—' }}</td>
              <td>{{ p.content_format || '—' }}</td>
              <td>{{ p.views }}</td>
            </tr>
          </tbody>
        </table>
        <p v-else class="muted">Нет постов по выбранным фильтрам.</p>
      </div>
    </template>
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import Chart from 'chart.js/auto'
import { api } from '../api'

const ownChannels = ref([])
const selectedOwn = ref(null)
const competitive = ref(null)
const ev = ref(null)
const loading = ref(true)
const error = ref(null)

const viewsChart = ref(null)
const dynamicsChart = ref(null)
const formatChart = ref(null)
const engagementChart = ref(null)
const lengthChart = ref(null)
const heatCanvas = ref(null)
const charts = []

const formatSource = ref('set')
const lengthSource = ref('set')
const heatTarget = ref('set')

const selectedCompetitor = ref(null)
const topPosts = ref([])
const topFilters = ref({ rubrics: [], formats: [] })
const topFormat = ref('')
const topRubric = ref('')

const compChannels = computed(() => competitive.value?.channels ?? [])
const compOwn = computed(() => compChannels.value.find((c) => c.is_own) ?? null)
const scopeRows = computed(() => compChannels.value.filter((c) => !c.is_own))
const ownCoverage = computed(() => ev.value?.coverage.find((c) => c.is_own)?.share ?? 0)
const winsRate = computed(() =>
  ev.value?.duels_count ? Math.round((ev.value.wins / ev.value.duels_count) * 100) : 0,
)
const topChannelsList = computed(() => {
  const cov = ev.value?.coverage ?? []
  const own = cov.find((c) => c.is_own)
  const rest = cov.filter((c) => !c.is_own)
  return own ? [own, ...rest] : rest
})

const isBestEngagement = computed(() => {
  const b = scopeRows.value.reduce((a, c) => (c.engagement > a.engagement ? c : a), scopeRows.value[0])
  return Boolean(compOwn.value && b && compOwn.value.engagement >= b.engagement)
})

function best(key) {
  const rows = scopeRows.value
  return rows.length ? rows.reduce((a, b) => (b[key] > a[key] ? b : a)) : null
}

function fmtDate(iso) {
  return iso?.slice(0, 16).replace('T', ' ') ?? ''
}

function fmtViews(v) {
  return Number(v ?? 0).toLocaleString('ru-RU')
}

const PALETTE = ['#e74c3c', '#3498db', '#2ecc71', '#f39c12', '#9b59b6', '#1abc9c']

async function loadAll() {
  loading.value = true
  error.value = null
  try {
    const [comp, events] = await Promise.all([
      api.get(`/competitive?days=21&own=${selectedOwn.value}`),
      api.get(`/events?own=${selectedOwn.value}&limit=300`),
    ])
    competitive.value = comp
    ev.value = events
    const first = topChannelsList.value[0]
    if (first && !topChannelsList.value.some((c) => c.channel_id === selectedCompetitor.value)) {
      selectedCompetitor.value = first.channel_id
    }
    loading.value = false
    await nextTick()
    renderCharts()
  } catch (e) {
    error.value = `Не удалось загрузить: ${e.message}`
    loading.value = false
  }
}

function renderCharts() {
  charts.forEach((c) => c?.destroy())
  charts.length = 0
  if (!competitive.value || !viewsChart.value) return

  const channels = competitive.value.channels

  charts.push(new Chart(viewsChart.value, {
    type: 'bar',
    data: {
      labels: channels.map((c) => (c.is_own ? '★ ' : '') + c.title.slice(0, 14)),
      datasets: [{ data: channels.map((c) => Math.max(c.views_median, 0.05)), backgroundColor: channels.map((c) => (c.is_own ? '#e74c3c' : '#3498db')) }],
    },
    options: {
      indexAxis: 'y', responsive: true,
      plugins: { legend: { display: false } },
      scales: { x: { type: 'logarithmic' } },
    },
  }))

  const byChannel = new Map()
  for (const d of competitive.value.dynamics) {
    for (const [channelId, n] of Object.entries(d.counts)) {
      if (!byChannel.has(+channelId)) byChannel.set(+channelId, [])
      byChannel.get(+channelId).push({ date: d.date, n })
    }
  }
  const allDates = [...new Set(competitive.value.dynamics.map((d) => d.date))].sort()
  charts.push(new Chart(dynamicsChart.value, {
    type: 'line',
    data: {
      labels: allDates.map((d) => d.slice(5)),
      datasets: channels.map((ch, i) => ({
        label: ch.title.slice(0, 16),
        data: allDates.map((date) => byChannel.get(ch.id)?.find((x) => x.date === date)?.n ?? 0),
        borderColor: ch.is_own ? '#e74c3c' : PALETTE[(i % (PALETTE.length - 1)) + 1],
        borderWidth: ch.is_own ? 3 : 1.5,
        pointRadius: 0,
        tension: 0.3,
      })),
    },
    options: {
      responsive: true,
      plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 10 } } } },
      scales: { y: { beginAtZero: true } },
    },
  }))

  // Охваты по форматам: один источник — набор в среднем или конкретный канал
  const formatData = formatSource.value === 'set'
    ? (competitive.value.formats?.set || [])
    : (competitive.value.formats?.[String(formatSource.value)] || [])
  const formatChannel = channels.find((c) => String(c.id) === formatSource.value)
  charts.push(new Chart(formatChart.value, {
    type: 'bar',
    data: {
      labels: formatData.map((f) => [f.format, `n=${f.n}`]),
      datasets: [{
        label: formatChannel ? formatChannel.title : 'Набор в среднем',
        data: formatData.map((f) => (f.views_median == null ? null : Math.max(f.views_median, 0.05))),
        backgroundColor: formatChannel?.is_own ? '#e74c3c' : '#3498db',
      }],
    },
    options: {
      responsive: true,
      plugins: { legend: { display: false } },
      scales: { y: { type: 'logarithmic', title: { display: true, text: 'медиана просмотров (лог)' } } },
    },
  }))

  charts.push(new Chart(engagementChart.value, {
    type: 'bar',
    data: {
      labels: channels.map((c) => (c.is_own ? '★ ' : '') + c.title.slice(0, 16)),
      datasets: [{ data: channels.map((c) => c.engagement), backgroundColor: channels.map((c) => (c.is_own ? '#e74c3c' : '#3498db')) }],
    },
    options: {
      responsive: true,
      plugins: { legend: { display: false } },
      scales: { y: { beginAtZero: true } },
    },
  }))

  // Длина статьи: один источник — набор в среднем или конкретный канал
  const lengthData = lengthSource.value === 'set'
    ? (competitive.value.length_set || [])
    : (competitive.value.length?.[String(lengthSource.value)] || [])
  const lengthChannel = channels.find((c) => String(c.id) === lengthSource.value)
  charts.push(new Chart(lengthChart.value, {
    type: 'bar',
    data: {
      labels: lengthData.map((b) => [`${b.bucket} мин`, `n=${b.n}`]),
      datasets: [{
        label: lengthChannel ? lengthChannel.title : 'Набор в среднем',
        data: lengthData.map((b) => b.views_median),
        backgroundColor: lengthChannel?.is_own ? '#e74c3c' : '#3498db',
      }],
    },
    options: {
      responsive: true,
      plugins: { legend: { display: false } },
      scales: { y: { beginAtZero: true, title: { display: true, text: 'медиана просмотров' } } },
    },
  }))

  drawSelectedHeatmap()
}

function heatmapGrid() {
  const grids = competitive.value?.publish_grid || []
  if (heatTarget.value === 'set') {
    if (grids.length === 0) return null
    const avg = Array.from({ length: 7 }, () => Array(24).fill(0))
    for (const g of grids) {
      for (let d = 0; d < 7; d++) {
        for (let h = 0; h < 24; h++) avg[d][h] += g.grid[d][h]
      }
    }
    for (let d = 0; d < 7; d++) {
      for (let h = 0; h < 24; h++) avg[d][h] = Math.round((avg[d][h] / grids.length) * 10) / 10
    }
    return avg
  }
  return grids.find((g) => String(g.channel_id) === heatTarget.value)?.grid ?? null
}

function drawSelectedHeatmap() {
  const grid = heatmapGrid()
  if (grid) drawHeatmap(heatCanvas.value, grid)
}

function drawHeatmap(canvas, grid) {
  if (!canvas) return
  const ctx = canvas.getContext('2d')
  const W = (canvas.width = 720)
  const H = (canvas.height = 250)
  const left = 34, top = 6, bottom = 22
  const cw = (W - left - 8) / 24
  const chh = (H - top - bottom) / 7
  const days = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс']
  const max = Math.max(1, ...grid.flat())

  ctx.clearRect(0, 0, W, H)
  ctx.font = '10px sans-serif'
  ctx.textBaseline = 'middle'
  for (let d = 0; d < 7; d++) {
    ctx.fillStyle = '#64748b'
    ctx.fillText(days[d], 8, top + d * chh + chh / 2)
    for (let h = 0; h < 24; h++) {
      const v = grid[d][h]
      const alpha = v === 0 ? 0.04 : 0.15 + 0.85 * Math.sqrt(v / max)
      ctx.fillStyle = `rgba(37, 99, 235, ${alpha.toFixed(2)})`
      ctx.fillRect(left + h * cw, top + d * chh, cw - 1, chh - 1)
      if (h % 3 === 0) {
        ctx.fillStyle = '#94a3b8'
        ctx.fillText(String(h).padStart(2, '0'), left + h * cw + cw / 2 - 6, H - 9)
      }
    }
  }
}

async function loadTopFilters() {
  topFormat.value = ''
  topRubric.value = ''
  topFilters.value = { rubrics: [], formats: [] }
  if (!selectedCompetitor.value) return
  topFilters.value = await api.get(`/channels/${selectedCompetitor.value}/posts/filters?days=21`)
}

async function loadTop() {
  if (!selectedCompetitor.value) return
  const params = new URLSearchParams({ sort: 'views', days: '21', limit: '15' })
  if (topFormat.value) params.set('format', topFormat.value)
  if (topRubric.value) params.set('rubric', topRubric.value)
  topPosts.value = await api.get(`/channels/${selectedCompetitor.value}/posts?${params}`)
}

async function onTopChannelChange() {
  await loadTopFilters()
  await loadTop()
}

watch(selectedOwn, loadAll)
watch(selectedCompetitor, onTopChannelChange)
watch(topFormat, loadTop)
watch(topRubric, loadTop)
watch(formatSource, () => nextTick().then(renderCharts))
watch(lengthSource, () => nextTick().then(renderCharts))
watch(heatTarget, drawSelectedHeatmap)

onMounted(async () => {
  try {
    const channels = await api.get('/channels')
    ownChannels.value = channels.filter((c) => c.is_own)
    if (ownChannels.value.length === 0) {
      error.value = 'Нет каналов с отметкой «мой» — отметьте на экране «Каналы»'
      loading.value = false
      return
    }
    selectedOwn.value = ownChannels.value[0].id
  } catch (e) {
    error.value = `Не удалось загрузить каналы: ${e.message}`
    loading.value = false
  }
})
</script>

<style scoped>
.head { display: flex; justify-content: space-between; align-items: center; }
.card-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
.card-head h3 { margin: 0; }
.own-switch { padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; }
.filters { display: flex; gap: 8px; }
.kpis { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 16px; }
.kpi-label { font-size: 12px; color: #64748b; text-transform: uppercase; letter-spacing: 0.04em; }
.kpi-value { font-size: 24px; font-weight: 700; margin: 4px 0; }
.grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; }
.ownrow { background: #fef2f2; }
h3 { font-size: 15px; margin: 0 0 12px; }
canvas { max-height: 300px; }
canvas.heat { width: 100%; height: auto; max-height: none; border: 1px solid #e2e8f0; border-radius: 8px; }
.small { font-size: 12px; margin: 8px 0 0; }
.duel { border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px 14px; margin-bottom: 10px; }
.duel.won { border-color: #86efac; background: #f0fdf4; }
.duel.lost { border-color: #fca5a5; background: #fef2f2; }
.duel-head { display: flex; gap: 14px; align-items: center; margin-bottom: 6px; font-size: 13px; }
.badge.win { background: #16a34a; color: #fff; padding: 2px 8px; border-radius: 999px; font-size: 11px; }
.badge.lose { background: #dc2626; color: #fff; padding: 2px 8px; border-radius: 999px; font-size: 11px; }
.post { font-size: 14px; margin: 2px 0; }
.post a { color: #1d4ed8; text-decoration: none; }
td.wrap { max-width: 520px; word-break: break-word; }
</style>
