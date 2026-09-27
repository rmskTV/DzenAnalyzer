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

      <div class="grid2">
        <div class="card">
          <div class="card-head">
            <h3>Охваты по рубрикам: каналы × рубрики</h3>
            <select v-model="rubricMetric" class="own-switch">
              <option value="median">Медиана просмотров</option>
              <option value="posts">Число постов</option>
              <option value="share">Доля повестки, %</option>
            </select>
          </div>
          <canvas ref="rubricHeatCanvas" class="heat"></canvas>
          <p class="muted small">Медиана — по созревшим (16ч+) статьям (лог-шкала); доля — % постов канала в рубрике. В скобках n — постов за окно. Пустая ячейка — данных нет.</p>
        </div>
        <div class="card">
          <h3>Профиль повестки: доля рубрик</h3>
          <canvas ref="agendaChart"></canvas>
          <p class="muted small">Топ-6 рубрик набора по сумме долей, остальное — «прочее».</p>
        </div>
      </div>

      <div class="card">
        <h3>Белые пятна: рабочие рубрики конкурентов, где нас нет</h3>
        <p v-if="!whiteSpots.length" class="muted">Белых пятен нет — либо мы покрываем все рабочие рубрики конкурентов.</p>
        <table v-else>
          <thead><tr><th>Рубрика</th><th>У нас</th><th>У конкурентов</th><th>Медиана у конкурентов</th><th>Лучший канал</th><th></th></tr></thead>
          <tbody>
            <tr v-for="s in whiteSpots" :key="s.rubric">
              <td>{{ s.rubric }}</td>
              <td>{{ s.own_n }} постов</td>
              <td>{{ s.competitors_n }} постов</td>
              <td>{{ fmtViews(s.competitors_median) }}</td>
              <td>{{ s.best_channel?.title }} ({{ fmtViews(s.best_channel?.views_median) }}, n={{ s.best_channel?.n }})</td>
              <td><button class="link" @click="openWhiteSpot(s)">Смотреть топ →</button></td>
            </tr>
          </tbody>
        </table>
        <p class="muted small">Критерий: у нас ≤ 2 постов за окно, у конкурентов ≥ 10 и медиана просмотров выше нашей общей медианы.</p>
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

      <div ref="topPostsCard" class="card">
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
const rubricHeatCanvas = ref(null)
const agendaChart = ref(null)
const topPostsCard = ref(null)
const charts = []

const formatSource = ref('set')
const lengthSource = ref('set')
const heatTarget = ref('set')
const rubricMetric = ref('median')

const selectedCompetitor = ref(null)
const topPosts = ref([])
const topFilters = ref({ rubrics: [], formats: [] })
const topFormat = ref('')
const topRubric = ref('')
let pendingRubric = null

const compChannels = computed(() => competitive.value?.channels ?? [])
const compOwn = computed(() => compChannels.value.find((c) => c.is_own) ?? null)
const scopeRows = computed(() => compChannels.value.filter((c) => !c.is_own))
const whiteSpots = computed(() => competitive.value?.white_spots ?? [])
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
  drawRubricHeatmap()
  drawAgendaChart()
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

/** Матрица рубрик: активные строки + ячейки channel_id -> rubric -> row */
function rubricRows() {
  const data = competitive.value?.rubrics
  if (!data) return { names: [], cells: new Map() }
  const channels = competitive.value.channels
  const used = new Set()
  const cells = new Map()
  for (const ch of channels) {
    const rows = data[String(ch.id)] || []
    cells.set(ch.id, new Map(rows.map((r) => [r.rubric, r])))
    for (const row of rows) if (row.n > 0) used.add(row.rubric)
  }
  return { names: (data.names || []).filter((n) => used.has(n)), cells }
}

function fmtHeat(v) {
  if (v >= 1000000) return (v / 1000000).toFixed(1) + 'M'
  if (v >= 1000) return Math.round(v / 1000) + 'k'
  return String(Math.round(v))
}

function drawRubricHeatmap() {
  const canvas = rubricHeatCanvas.value
  const { names, cells } = rubricRows()
  if (!canvas || !competitive.value || names.length === 0) return
  const channels = competitive.value.channels

  const W = (canvas.width = 760)
  const left = 130
  const headH = 34
  const rowH = Math.max(20, Math.min(30, 300 / Math.max(names.length, 1)))
  const H = (canvas.height = Math.ceil(headH + names.length * rowH + 6))
  const cw = (W - left - 8) / channels.length

  // значение и подпись текущего режима; n — всегда рядом, для контекста
  const metric = (row) => {
    if (!row) return null
    if (rubricMetric.value === 'median') return row.views_median
    if (rubricMetric.value === 'posts') return row.n
    return row.share
  }
  const label = (row) => {
    if (!row) return ''
    if (rubricMetric.value === 'median') return `${fmtHeat(row.views_median)} (n=${row.n})`
    if (rubricMetric.value === 'posts') return String(row.n)
    return `${Number(row.share).toFixed(1)}% (n=${row.n})`
  }

  const vals = []
  for (const ch of channels) {
    for (const name of names) {
      const v = metric(cells.get(ch.id)?.get(name))
      if (v != null && v > 0) vals.push(v)
    }
  }
  // медиана — лог-шкала (просмотры лог-нормальны), счёт/доля — sqrt от максимума
  const maxV = Math.max(1, ...vals)
  const lo = Math.log10(Math.max(Math.min(...(vals.length ? vals : [1])), 1))
  const hi = Math.log10(maxV)
  const intensity = (v) => {
    if (v == null || v <= 0) return null
    if (rubricMetric.value === 'median') {
      return Math.max(0.12, Math.min(1, (Math.log10(Math.max(v, 1)) - lo) / ((hi - lo) || 1)))
    }
    return Math.max(0.12, Math.min(1, Math.sqrt(v / maxV)))
  }

  const ctx = canvas.getContext('2d')
  ctx.clearRect(0, 0, W, H)
  ctx.font = '10px sans-serif'
  ctx.textBaseline = 'middle'

  for (let c = 0; c < channels.length; c++) {
    ctx.fillStyle = channels[c].is_own ? '#b91c1c' : '#64748b'
    ctx.fillText((channels[c].is_own ? '★ ' : '') + channels[c].title.slice(0, 12), left + c * cw + 2, headH / 2)
  }

  for (let r = 0; r < names.length; r++) {
    const y = headH + r * rowH
    ctx.fillStyle = '#475569'
    ctx.fillText(names[r].slice(0, 19), 6, y + rowH / 2)
    for (let c = 0; c < channels.length; c++) {
      const ch = channels[c]
      const row = cells.get(ch.id)?.get(names[r])
      const t = intensity(metric(row))
      const x = left + c * cw
      if (t == null) {
        ctx.fillStyle = 'rgba(148,163,184,0.15)'
        ctx.fillRect(x, y, cw - 2, rowH - 2)
        continue
      }
      const alpha = 0.15 + 0.85 * t
      ctx.fillStyle = ch.is_own ? `rgba(231,76,60,${alpha.toFixed(2)})` : `rgba(37,99,235,${alpha.toFixed(2)})`
      ctx.fillRect(x, y, cw - 2, rowH - 2)
      if (cw > 60) {
        ctx.fillStyle = t > 0.55 ? '#fff' : '#334155'
        ctx.fillText(label(row), x + 4, y + rowH / 2)
      }
    }
  }
}

/** Профиль повестки: 100% stacked bars — доля рубрик по каналам */
function drawAgendaChart() {
  const canvas = agendaChart.value
  const data = competitive.value?.rubrics
  if (!canvas || !data) return
  const channels = competitive.value.channels

  const shares = new Map() // rubric -> доля по каждому каналу
  channels.forEach((ch, idx) => {
    for (const row of data[String(ch.id)] || []) {
      if (!shares.has(row.rubric)) shares.set(row.rubric, channels.map(() => 0))
      shares.get(row.rubric)[idx] = row.share
    }
  })

  const ranked = [...shares.entries()]
    .map(([rubric, arr]) => ({ rubric, total: arr.reduce((a, b) => a + b, 0) }))
    .sort((a, b) => b.total - a.total)
  const named = ranked
    .filter((x) => x.rubric !== 'Без рубрики' && x.total > 0)
    .slice(0, 6)
    .map((x) => x.rubric)
  const other = channels.map((_, idx) =>
    Math.max(0, 100 - named.reduce((s, r) => s + (shares.get(r)?.[idx] || 0), 0)),
  )

  const palette = ['#e74c3c', '#3498db', '#2ecc71', '#f39c12', '#9b59b6', '#1abc9c']
  charts.push(new Chart(canvas, {
    type: 'bar',
    data: {
      labels: channels.map((c) => (c.is_own ? '★ ' : '') + c.title.slice(0, 16)),
      datasets: [
        ...named.map((r, i) => ({
          label: r,
          data: channels.map((_, idx) => shares.get(r)?.[idx] || 0),
          backgroundColor: palette[i % palette.length],
        })),
        { label: 'прочее', data: other, backgroundColor: '#cbd5e1' },
      ],
    },
    options: {
      indexAxis: 'y',
      responsive: true,
      plugins: {
        legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 10 } } },
        tooltip: { callbacks: { label: (c) => `${c.dataset.label}: ${Number(c.raw).toFixed(1)}%` } },
      },
      scales: {
        x: { stacked: true, max: 100, ticks: { callback: (v) => v + '%' } },
        y: { stacked: true },
      },
    },
  }))
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
  if (pendingRubric) {
    // белое пятно: рубрику выставляем после загрузки фильтров канала
    topRubric.value = pendingRubric
    pendingRubric = null
  }
  await loadTop()
}

async function openWhiteSpot(spot) {
  pendingRubric = spot.rubric
  if (selectedCompetitor.value === spot.best_channel.id) {
    topRubric.value = spot.rubric
    pendingRubric = null
    await loadTop()
  } else {
    selectedCompetitor.value = spot.best_channel.id
  }
  topPostsCard.value?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

watch(selectedOwn, loadAll)
watch(selectedCompetitor, onTopChannelChange)
watch(topFormat, loadTop)
watch(topRubric, loadTop)
watch(formatSource, () => nextTick().then(renderCharts))
watch(lengthSource, () => nextTick().then(renderCharts))
watch(heatTarget, drawSelectedHeatmap)
watch(rubricMetric, drawRubricHeatmap)

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
button.link { background: none; border: none; color: #1d4ed8; cursor: pointer; font-size: 13px; padding: 0; }
td.wrap { max-width: 520px; word-break: break-word; }
</style>
