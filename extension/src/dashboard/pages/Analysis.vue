<template>
  <div>
    <p v-if="error" style="color: #b91c1c">{{ error }}</p>

    <div v-if="!settings.own" class="card">
      <p class="muted">
        Не выбран свой канал: откройте вкладку «Каналы», добавьте канал и нажмите «Сделать своим».
        Также добавьте 2–5 конкурентов — без них события и дуэли не рассчитаются.
      </p>
    </div>

    <template v-else>
      <div class="row" style="margin-bottom: 16px">
        <select v-model="days" @change="recompute()">
          <option v-for="d in [7, 14, 21, 28]" :key="d" :value="d">{{ d }} дней</option>
        </select>
        <span class="muted">окно анализа; свой канал: <b>★ {{ ownRow?.title }}</b></span>
      </div>

      <div class="kpis">
        <div class="card kpi">
          <div class="kpi-label">Медиана просмотров (16ч+)</div>
          <div class="kpi-value">{{ fmt(ownRow?.viewsMedian) }}</div>
          <div class="muted">лучший конкурент: {{ fmt(best('viewsMedian')?.viewsMedian) }} ({{ best('viewsMedian')?.title }})</div>
        </div>
        <div class="card kpi">
          <div class="kpi-label">Вовлечённость, комм/1000</div>
          <div class="kpi-value">{{ ownRow?.engagement ?? '—' }}</div>
          <div class="muted">лучший: {{ fmt(best('engagement')?.engagement) }}</div>
        </div>
        <div class="card kpi">
          <div class="kpi-label">Публикаций/день</div>
          <div class="kpi-value">{{ ownRow?.postsPerDay ?? '—' }}</div>
          <div class="muted">лучший: {{ fmt(best('postsPerDay')?.postsPerDay) }}</div>
        </div>
        <div class="card kpi">
          <div class="kpi-label">Событий / покрытие</div>
          <div class="kpi-value">{{ ev.totalEvents }}</div>
          <div class="muted">наш охват: {{ ownCoverage }}%</div>
        </div>
      </div>

      <div class="grid2">
        <div class="card">
          <h3>Охваты: медиана просмотров (лог)</h3>
          <canvas ref="medianChart"></canvas>
        </div>
        <div class="card">
          <h3>Динамика публикаций (шт/день)</h3>
          <canvas ref="dynamicsChart"></canvas>
        </div>
        <div class="card">
          <h3>Медиана просмотров по типам</h3>
          <canvas ref="typesChart"></canvas>
        </div>
        <div class="card">
          <div class="card-head">
            <h3>Сетка публикаций своего канала (местное время)</h3>
          </div>
          <canvas ref="heatCanvas" class="heat"></canvas>
        </div>
      </div>

      <div class="grid2">
        <div class="card">
          <h3>Затухание инфоповода: доля от лидера vs задержка</h3>
          <canvas ref="decayChart"></canvas>
          <p class="muted small">Медианная доля просмотров нашего поста от лидера события, по корзинам опоздания.</p>
        </div>
        <div class="card">
          <h3>Бенчмарк: мы против лучшего</h3>
          <table>
            <thead><tr><th>Метрика</th><th>Мы</th><th>Лучший</th><th>Канал</th><th>×</th></tr></thead>
            <tbody>
              <tr v-for="b in ov.benchmark" :key="b.metric">
                <td>{{ b.metric }}</td>
                <td><b>{{ fmt(b.own) }}</b></td>
                <td>{{ fmt(b.best) }}</td>
                <td>{{ b.bestChannel }}</td>
                <td>{{ b.ratio != null ? '×' + b.ratio : '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="card">
        <h3>Покрытие и скорость по событиям</h3>
        <table>
          <thead><tr><th>Канал</th><th>Событий</th><th>Доля</th><th>Медиана отставания</th><th>Был первым</th></tr></thead>
          <tbody>
            <tr v-for="c in cov" :key="c.key" :class="{ ownrow: c.isOwn }">
              <td>{{ c.isOwn ? '★ ' : '' }}{{ c.title }}</td>
              <td>{{ c.nEvents }}</td>
              <td>{{ c.share }}%</td>
              <td>{{ c.medianDelayMin }} мин</td>
              <td>{{ c.firstCount }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="card">
        <div class="card-head">
          <h3>Заголовки: признак → медиана охвата (lift)</h3>
          <select v-model="liftSource">
            <option value="set">Набор в среднем</option>
            <option v-for="ch in ov.channels" :key="ch.key" :value="ch.key">
              {{ ch.isOwn ? '★ ' : '' }}{{ ch.title }}
            </option>
          </select>
        </div>
        <table>
          <thead><tr><th>Признак</th><th>С признаком</th><th>Медиана с</th><th>Медиана без</th><th>Lift</th></tr></thead>
          <tbody>
            <tr v-for="r in liftRows" :key="r.feature">
              <td>{{ r.feature }}</td>
              <td>{{ r.withN }} ({{ r.sharePct }}%)</td>
              <td>{{ fmt(r.withMedian) }}</td>
              <td>{{ fmt(r.withoutMedian) }}</td>
              <td>
                <span v-if="r.lift != null" :class="r.lift >= 1.1 ? 'lift-pos' : r.lift <= 0.9 ? 'lift-neg' : ''">×{{ r.lift }}</span>
                <span v-else class="muted">—</span>
              </td>
            </tr>
          </tbody>
        </table>
        <p class="muted small">
          Только созревшие (16ч+) статьи. Длина заголовка: {{ liftRows.avgLen }} симв. в среднем, медиана {{ liftRows.medianLen }}.
        </p>
        <h3 style="margin-top: 16px">Топ заголовков набора</h3>
        <table>
          <tbody>
            <tr v-for="t in headlines.top" :key="t.url + t.ts">
              <td class="wrap">
                <a v-if="t.url" :href="t.url" target="_blank">{{ t.title }}</a>
                <template v-else>{{ t.title }}</template>
              </td>
              <td>{{ fmt(t.views) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="card">
        <h3>Дуэли инфоповодов ({{ ev.duelsCount }}, побед {{ ev.wins }} — {{ winsRate }}%)</h3>
        <p v-if="!ev.duels.length" class="muted">
          Дуэлей нет: нужны конкуренты в базе с пересекающимися инфоповодами (кнопка «+ Конкурент» на вкладке «Каналы»).
        </p>
        <div v-for="d in ev.duels" :key="d.id" class="duel" :class="d.win ? 'won' : 'lost'">
          <div class="duel-head">
            <span class="badge" :class="d.win ? 'win' : 'lose'">{{ d.win ? 'ПОБЕДА' : 'ПРОИГРЫШ' }}</span>
            <span v-if="d.ratio != null" class="muted">охват ×{{ d.ratio }} от конкурента</span>
            <span class="muted">{{ fmtDate(d.firstTs) }}</span>
          </div>
          <div>
            <b>Мы</b> ({{ d.own.delayMin === 0 ? 'первый' : '+' + d.own.delayMin + ' мин' }}, {{ fmt(d.own.post.v) }}):
            <a v-if="d.own.post.u" :href="d.own.post.u" target="_blank">{{ d.own.post.t }}</a>
            <template v-else>{{ d.own.post.t }}</template>
          </div>
          <div v-if="d.best">
            <b>{{ channelTitle(d.best.key) }}</b> ({{ d.best.delayMin === 0 ? 'первый' : '+' + d.best.delayMin + ' мин' }}, {{ fmt(d.best.post.v) }}):
            <a v-if="d.best.post.u" :href="d.best.post.u" target="_blank">{{ d.best.post.t }}</a>
            <template v-else>{{ d.best.post.t }}</template>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import Chart from 'chart.js/auto'
import { getScope } from '../../lib/store.js'
import { overview } from '../../lib/metrics.js'
import { buildEvents, duels, coverage, decayCurve } from '../../lib/events.js'
import { headlineStats } from '../../lib/headlines.js'

const settings = ref({ own: null, competitors: [] })
const days = ref(21)
const error = ref(null)
const liftSource = ref('set')

const ov = ref({ channels: [], dynamics: [], benchmark: [] })
const ev = ref({ totalEvents: 0, duelsCount: 0, wins: 0, duels: [] })
const cov = ref([])
const decay = ref([])
const headlines = ref({ set: [], byChannel: {}, top: [] })

const medianChart = ref(null)
const dynamicsChart = ref(null)
const typesChart = ref(null)
const decayChart = ref(null)
const heatCanvas = ref(null)
const charts = []

const ownRow = computed(() => ov.value.channels.find((c) => c.isOwn))
const ownCoverage = computed(() => cov.value.find((c) => c.isOwn)?.share ?? 0)
const winsRate = computed(() => (ev.value.duelsCount ? Math.round((ev.value.wins / ev.value.duelsCount) * 100) : 0))
const liftRows = computed(() =>
  liftSource.value === 'set' ? headlines.value.set : headlines.value.byChannel[liftSource.value]?.features ?? [],
)

function best(key) {
  const rows = ov.value.channels.filter((c) => !c.isOwn)
  return rows.length ? rows.reduce((a, b) => (b[key] > a[key] ? b : a)) : null
}

function channelTitle(key) {
  return ov.value.channels.find((c) => c.key === key)?.title ?? key
}

function fmt(v) {
  if (v == null) return '—'
  return Number(v).toLocaleString('ru-RU', { maximumFractionDigits: 2 })
}

function fmtDate(ts) {
  return new Date(ts * 1000).toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
}

function recompute() {
  if (!scopeChannels.length) return
  const opts = { days: days.value }
  ov.value = overview(scopeChannels, opts)

  const clusters = buildEvents(scopeChannels, opts)
  ev.value = duels(clusters, settings.value.own)
  const meta = Object.fromEntries(scopeChannels.map((c) => [c.key, { title: c.meta?.title ?? c.key, isOwn: c.isOwn }]))
  cov.value = coverage(clusters, meta)
  decay.value = decayCurve(clusters, settings.value.own)
  headlines.value = headlineStats(scopeChannels, opts)

  nextTick(renderCharts)
}

function renderCharts() {
  charts.forEach((c) => c?.destroy())
  charts.length = 0
  const channels = ov.value.channels
  const palette = ['#e74c3c', '#3498db', '#2ecc71', '#f39c12', '#9b59b6', '#1abc9c']

  charts.push(new Chart(medianChart.value, {
    type: 'bar',
    data: {
      labels: channels.map((c) => (c.isOwn ? '★ ' : '') + c.title.slice(0, 14)),
      datasets: [{
        data: channels.map((c) => Math.max(c.viewsMedian, 0.05)),
        backgroundColor: channels.map((c) => (c.isOwn ? '#e74c3c' : '#3498db')),
      }],
    },
    options: { indexAxis: 'y', responsive: true, plugins: { legend: { display: false } }, scales: { x: { type: 'logarithmic' } } },
  }))

  const byChannel = new Map()
  for (const d of ov.value.dynamics) {
    for (const [key, n] of Object.entries(d.counts)) {
      if (!byChannel.has(key)) byChannel.set(key, [])
      byChannel.get(key).push({ date: d.date, n })
    }
  }
  const allDates = [...new Set(ov.value.dynamics.map((d) => d.date))].sort()
  charts.push(new Chart(dynamicsChart.value, {
    type: 'line',
    data: {
      labels: allDates.map((d) => d.slice(5)),
      datasets: channels.map((ch, i) => ({
        label: ch.title.slice(0, 16),
        data: allDates.map((date) => byChannel.get(ch.key)?.find((x) => x.date === date)?.n ?? 0),
        borderColor: ch.isOwn ? '#e74c3c' : palette[(i % (palette.length - 1)) + 1],
        borderWidth: ch.isOwn ? 3 : 1.5,
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

  const typeKeys = [['article', 'Статьи'], ['short', 'Шортсы'], ['video_long', 'Видео']]
  charts.push(new Chart(typesChart.value, {
    type: 'bar',
    data: {
      labels: channels.map((c) => (c.isOwn ? '★ ' : '') + c.title.slice(0, 12)),
      datasets: typeKeys.map(([ty, label], i) => ({
        label,
        data: channels.map((c) => (c.types[ty].viewsMedian == null ? null : Math.max(c.types[ty].viewsMedian, 0.05))),
        backgroundColor: palette[i],
      })),
    },
    options: {
      responsive: true,
      plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 10 } } } },
      scales: { y: { type: 'logarithmic' } },
    },
  }))

  charts.push(new Chart(decayChart.value, {
    type: 'bar',
    data: {
      labels: decay.value.map((d) => `${d.bucket} (n=${d.n})`),
      datasets: [{ data: decay.value.map((d) => d.medianShare), backgroundColor: '#3498db' }],
    },
    options: {
      responsive: true,
      plugins: { legend: { display: false } },
      scales: { y: { beginAtZero: true, max: 100, title: { display: true, text: '% от лидера' } } },
    },
  }))

  drawHeatmap(heatCanvas.value, ownRow.value?.grid)
}

function drawHeatmap(canvas, grid) {
  if (!canvas || !grid) return
  const ctx = canvas.getContext('2d')
  const W = (canvas.width = 720)
  const H = (canvas.height = 250)
  const left = 34
  const top = 6
  const bottom = 22
  const cw = (W - left - 8) / 24
  const chh = (H - top - bottom) / 7
  const daysRu = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс']
  const max = Math.max(1, ...grid.flat())

  ctx.clearRect(0, 0, W, H)
  ctx.font = '10px sans-serif'
  ctx.textBaseline = 'middle'
  for (let d = 0; d < 7; d++) {
    ctx.fillStyle = '#64748b'
    ctx.fillText(daysRu[d], 8, top + d * chh + chh / 2)
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

let scopeChannels = []

onMounted(async () => {
  try {
    const scope = await getScope()
    settings.value = scope.settings
    scopeChannels = Object.entries(scope.channels).map(([key, data]) => ({
      key,
      isOwn: key === scope.settings.own,
      meta: data.meta,
      posts: data.posts,
    }))
    if (settings.value.own && scopeChannels.length) {
      recompute()
    }
  } catch (e) {
    error.value = `Не удалось прочитать базу: ${e.message}`
  }
})

onBeforeUnmount(() => charts.forEach((c) => c?.destroy()))
</script>
