<template>
  <div>
    <h1>Правила рерайта</h1>

    <div class="card">
      <h3>Win-rate приёмов заголовков (наши опубликованные посты)</h3>
      <table v-if="patternStats.length">
        <thead><tr><th>Приём</th><th>Постов</th><th>Медиана vpd</th><th>Максимум</th></tr></thead>
        <tbody>
          <tr v-for="p in patternStats" :key="p.pattern">
            <td>{{ p.pattern }}</td><td>{{ p.n }}</td><td>{{ p.median_vpd }}</td><td>{{ p.max_vpd }}</td>
          </tr>
        </tbody>
      </table>
      <p v-else class="muted">Статистика появится после первых опубликованных постов (dzen:track).</p>
    </div>

    <p v-if="loading" class="muted">Загрузка…</p>
    <div v-else>
      <div v-for="v in versions" :key="v.id" class="card">
        <div class="vhead">
          <div>
            <b>{{ v.title }}</b>
            <span class="badge" :class="v.layer === 'global' ? 'lg' : 'lc'">{{ v.layer }}</span>
            <span v-if="v.is_active" class="badge act">активна</span>
          </div>
          <button v-if="!v.is_active" class="btn" @click="activate(v)">активировать</button>
        </div>
        <p class="muted">{{ v.created_at?.slice(0, 16).replace('T', ' ') }}
          <template v-if="v.summary"> — {{ v.summary }}</template>
        </p>
        <pre>{{ v.content }}</pre>
      </div>
      <p v-if="!versions.length" class="muted">
        Версий нет. Еженедельное предложение — dzen:rules (или вручную после накопления статистики).
      </p>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { api } from '../api'

const versions = ref([])
const patternStats = ref([])
const loading = ref(true)

async function load() {
  const res = await api.get('/rules?own=1')
  versions.value = res.versions
  patternStats.value = res.pattern_stats
  loading.value = false
}

async function activate(v) {
  await api.post(`/rules/${v.id}/activate`)
  await load()
}

onMounted(load)
</script>

<style scoped>
.vhead { display: flex; justify-content: space-between; align-items: center; }
.badge { margin-left: 8px; }
.lg { background: #e0e7ff; color: #3730a3; }
.lc { background: #ffedd5; color: #9a3412; }
.act { background: #dcfce7; color: #15803d; }
pre { background: #f8fafc; padding: 12px; border-radius: 8px; white-space: pre-wrap; font-size: 13px; }
.btn { padding: 6px 14px; border-radius: 8px; border: 1px solid #cbd5e1; background: #fff; cursor: pointer; }
</style>
