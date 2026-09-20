<template>
  <div>
    <h1>Состояние системы</h1>

    <p v-if="loading" class="muted">Загрузка…</p>
    <p v-else-if="error" style="color: #b91c1c">{{ error }}</p>

    <template v-else>
      <div class="kpis">
        <div class="card kpi">
          <div class="kpi-label">Каналы</div>
          <div class="kpi-value">{{ s.channels.total }}</div>
          <div class="muted">своих: {{ s.channels.own }} · активных: {{ s.channels.active }}</div>
        </div>
        <div class="card kpi">
          <div class="kpi-label">Постов в БД</div>
          <div class="kpi-value">{{ s.db.posts.toLocaleString('ru') }}</div>
          <div class="muted">снапшотов: {{ s.db.snapshots.toLocaleString('ru') }}</div>
        </div>
        <div class="card kpi">
          <div class="kpi-label">Снапшотов сегодня</div>
          <div class="kpi-value">{{ snapshotsToday }}</div>
          <div class="muted">событий в базе: {{ s.db.event_clusters }}</div>
        </div>
        <div class="card kpi">
          <div class="kpi-label">Очередь</div>
          <div class="kpi-value">{{ s.queue.pending }}</div>
          <div class="muted" :style="s.queue.failed ? 'color:#b91c1c' : ''">
            проваленных джоб: {{ s.queue.failed }}
          </div>
        </div>
      </div>

      <div class="grid2">
        <div class="card">
          <h3>Конвейер контента</h3>
          <div class="chips">
            <span v-for="(n, st) in s.content.publications_by_status" :key="st" class="chip" :class="'st-' + st">
              {{ st }}: <b>{{ n }}</b>
            </span>
            <span v-if="!Object.keys(s.content.publications_by_status).length" class="muted">
              публикаций ещё нет
            </span>
          </div>
          <table class="mini">
            <tr><td>Сгенерировано сегодня</td><td>{{ s.content.generated_today }}</td></tr>
            <tr><td>В RSS-фиде (queued)</td><td>{{ s.content.queued_in_feed }}</td></tr>
            <tr><td>С результатами (tracked)</td><td>{{ s.content.tracked_results }}</td></tr>
          </table>
          <h3 style="margin-top:18px">LLM (Bothub)</h3>
          <table class="mini">
            <tr>
              <td>Статус</td>
              <td><span :class="s.llm.configured ? 'ok' : 'bad'">{{ s.llm.configured ? 'настроен' : 'НЕ настроен' }}</span></td>
            </tr>
            <tr><td>URL</td><td><code>{{ s.llm.base_url }}</code></td></tr>
            <tr><td>Модели</td><td>{{ Object.values(s.llm.models).join(', ') }}</td></tr>
          </table>
        </div>

        <div class="card">
          <h3>Ингест (парсеры)</h3>
          <table class="mini">
            <tr><td>Активных эндпоинтов</td><td>{{ s.ingest.parsers_configured }}</td></tr>
            <tr><td>Материалов всего</td><td>{{ s.ingest.materials_total }}</td></tr>
            <tr>
              <td>По статусам</td>
              <td>
                <span v-if="!Object.keys(s.ingest.by_status).length" class="muted">—</span>
                <span v-for="(n, st) in s.ingest.by_status" :key="st" class="chip">{{ st }}: <b>{{ n }}</b></span>
              </td>
            </tr>
          </table>
          <h3 style="margin-top:18px">Расписание (ежедневно)</h3>
          <table class="mini">
            <tr v-for="row in s.schedule" :key="row.command">
              <td><code>{{ row.command }}</code></td>
              <td class="muted">{{ row.irk }} Ирк ({{ row.utc }} UTC)</td>
            </tr>
          </table>
        </div>
      </div>

      <div class="grid2">
        <div class="card">
          <h3>Рубрики <span class="muted small">(созданные LLM отмечены)</span></h3>
          <table class="mini">
            <tbody>
              <tr v-for="r in s.taxonomy.rubrics" :key="r.name">
                <td>{{ r.name }}</td>
                <td v-if="r.created_by === 'llm'"><span class="badge llm">LLM</span></td>
                <td v-else></td>
                <td class="num">{{ r.n }}</td>
              </tr>
              <tr v-if="s.taxonomy.unclassified">
                <td class="muted">без классификации</td><td></td>
                <td class="num">{{ s.taxonomy.unclassified }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="card">
          <h3>Форматы <span class="muted small">(evergreen — не привязан к дате)</span></h3>
          <table class="mini">
            <tbody>
              <tr v-for="f in s.taxonomy.formats" :key="f.name">
                <td>{{ f.name }}</td>
                <td v-if="f.is_evergreen"><span class="badge eg">evergreen</span></td>
                <td v-else></td>
                <td class="num">{{ f.n }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="card">
        <h3>Сбор данных по каналам</h3>
        <table>
          <thead>
            <tr><th>Канал</th><th>Режим</th><th>Свой</th><th>Активен</th><th>Последний сбор</th><th>Постов</th><th>Снапшотов сегодня</th></tr>
          </thead>
          <tbody>
            <tr v-for="c in s.collection" :key="c.id" :class="{ ownrow: c.is_own }">
              <td>{{ c.is_own ? '★ ' : '' }}{{ c.title }}</td>
              <td><code>{{ c.mode }}</code></td>
              <td>{{ c.is_own ? 'да' : '—' }}</td>
              <td><span :class="c.is_active ? 'ok' : 'muted'">{{ c.is_active ? 'да' : 'нет' }}</span></td>
              <td :style="isStale(c.last_crawled_at) ? 'color:#b45309' : ''">{{ fmt(c.last_crawled_at) }}</td>
              <td>{{ c.posts }}</td>
              <td>{{ c.snapshots_today }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { api } from '../api'

const s = ref(null)
const loading = ref(true)
const error = ref(null)

const snapshotsToday = computed(() =>
  s.value?.collection.reduce((acc, c) => acc + c.snapshots_today, 0) ?? 0,
)

function fmt(iso) {
  return iso ? iso.slice(0, 16).replace('T', ' ') + ' UTC' : 'никогда'
}

function isStale(iso) {
  if (!iso) return true
  return Date.now() - new Date(iso).getTime() > 26 * 3600 * 1000
}

onMounted(async () => {
  try {
    s.value = await api.get('/system-status')
  } catch (e) {
    error.value = `Не удалось загрузить статус: ${e.message}`
  } finally {
    loading.value = false
  }
})
</script>

<style scoped>
.kpis { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 16px; }
.kpi-label { font-size: 12px; color: #64748b; text-transform: uppercase; letter-spacing: 0.04em; }
.kpi-value { font-size: 26px; font-weight: 700; margin: 4px 0; }
.grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; }
h3 { font-size: 15px; margin: 0 0 12px; }
table.mini td { padding: 4px 10px 4px 0; font-size: 14px; border-bottom: 1px dashed #eef2f7; }
.ownrow { background: #fef2f2; }
.chips { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 12px; }
.chip { background: #f1f5f9; border-radius: 999px; padding: 3px 10px; font-size: 12px; }
.chip.st-queued, .chip.st-published { background: #dcfce7; }
.chip.st-generated { background: #fef3c7; }
.chip.st-rejected { background: #fee2e2; }
.ok { color: #15803d; font-weight: 600; }
.bad { color: #b91c1c; font-weight: 600; }
.small { font-size: 12px; font-weight: 400; }
.num { text-align: right; font-variant-numeric: tabular-nums; }
.badge.llm { background: #fef3c7; color: #92400e; padding: 1px 8px; border-radius: 999px; font-size: 11px; }
.badge.eg { background: #dbeafe; color: #1d4ed8; padding: 1px 8px; border-radius: 999px; font-size: 11px; }
</style>
