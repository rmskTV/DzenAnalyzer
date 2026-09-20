<template>
  <div>
    <h1>Черновики</h1>

    <div class="card">
      <div class="filters">
        <select v-model="status">
          <option value="">все статусы</option>
          <option v-for="s in statuses" :key="s" :value="s">{{ s }}</option>
        </select>
        <span class="muted">{{ total }} записей</span>
      </div>

      <p v-if="loading" class="muted">Загрузка…</p>
      <p v-else-if="!rows.length" class="muted">
        Черновиков нет. Появятся после настройки LLM и запуска dzen:generate.
      </p>

      <table v-else>
        <thead>
          <tr><th>Создан</th><th>Тип</th><th>Рубрика</th><th>Заголовок</th><th>Приём</th><th>Статус</th><th></th></tr>
        </thead>
        <tbody>
          <tr v-for="d in rows" :key="d.id" :class="{ selected: current?.id === d.id }" @click="open(d)">
            <td>{{ d.created_at?.slice(5, 16).replace('T', ' ') }}</td>
            <td><span class="badge src">{{ d.source_type }}</span></td>
            <td>{{ d.rubric }}</td>
            <td>{{ d.title.slice(0, 70) }}</td>
            <td>{{ d.headline_pattern }}</td>
            <td><span :class="'badge st-' + d.status">{{ d.status }}</span></td>
            <td>{{ d.result_views ? Number(d.result_views).toLocaleString('ru-RU') : '' }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="current" class="card editor">
      <div class="editor-head">
        <h3>Редактура #{{ current.id }}</h3>
        <button class="btn" @click="current = null">закрыть</button>
      </div>

      <label>Заголовок (вариант {{ current.title_variant || '—' }}, приём: {{ current.headline_pattern }})</label>
      <input v-model="form.title" />

      <div v-if="variants.length" class="variants">
        <button v-for="(v, i) in variants" :key="i" class="btn var" @click="pickVariant(v, i)">
          {{ ['A', 'B', 'C'][i] }}: {{ v.slice(0, 60) }}
        </button>
      </div>

      <label>Текст</label>
      <textarea v-model="form.body" rows="16"></textarea>

      <label>Рубрика</label>
      <input v-model="form.rubric" />

      <div v-html="preview" class="preview"></div>

      <div class="actions">
        <button class="btn primary" @click="save">Сохранить</button>
        <button class="btn ok" @click="approve" :disabled="current.status !== 'generated' && current.status !== 'edited'">
          Одобрить
        </button>
        <button class="btn bad" @click="reject" :disabled="current.status === 'rejected'">Отклонить</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { api } from '../api'

const rows = ref([])
const total = ref(0)
const loading = ref(true)
const status = ref('')
const current = ref(null)
const form = ref({ title: '', body: '', rubric: '' })

const statuses = ['generated', 'edited', 'approved', 'queued', 'published', 'rejected']
const variants = computed(() => current.value?.experiment_tags?.titles || [])

const preview = computed(() => {
  const raw = form.value.body || ''
  return raw.startsWith('<') ? raw : raw.split(/\n{2,}/).map((p) => `<p>${p}</p>`).join('')
})

async function load() {
  loading.value = true
  const params = new URLSearchParams()
  if (status.value) params.set('status', status.value)
  const res = await api.get(`/drafts?${params}`)
  rows.value = res.data
  total.value = res.total
  loading.value = false
}

function open(d) {
  current.value = d
  form.value = { title: d.title, body: d.body, rubric: d.rubric }
}

function pickVariant(title, index) {
  form.value.title = title
  current.value.title_variant = ['A', 'B', 'C'][index]
}

async function save() {
  const payload = { ...form.value, title_variant: current.value.title_variant || null }
  current.value = await api.put(`/drafts/${current.value.id}`, payload)
  await load()
}

async function approve() {
  await save()
  current.value = await api.post(`/drafts/${current.value.id}/approve`)
  await load()
}

async function reject() {
  current.value = await api.post(`/drafts/${current.value.id}/reject`)
  await load()
}

onMounted(load)
</script>

<style scoped>
.filters { display: flex; gap: 12px; align-items: center; margin-bottom: 12px; }
tr.selected td { background: #eff6ff; }
tr { cursor: pointer; }
.editor-head { display: flex; justify-content: space-between; align-items: center; }
label { display: block; font-size: 12px; color: #64748b; margin: 12px 0 4px; }
input, textarea { width: 100%; font: inherit; padding: 8px; border: 1px solid #cbd5e1; border-radius: 8px; }
.variants { display: flex; flex-direction: column; gap: 6px; margin-top: 8px; }
.var { text-align: left; background: #f1f5f9; }
.preview { border-top: 1px dashed #cbd5e1; margin-top: 16px; padding-top: 12px; }
.actions { display: flex; gap: 8px; margin-top: 16px; }
.btn { padding: 8px 16px; border-radius: 8px; border: 1px solid #cbd5e1; background: #fff; cursor: pointer; }
.btn.primary { background: #2563eb; color: #fff; }
.btn.ok { background: #16a34a; color: #fff; }
.btn.bad { background: #dc2626; color: #fff; }
.btn:disabled { opacity: 0.4; }
.badge.src { background: #e0e7ff; color: #3730a3; }
.badge.st-generated { background: #fef3c7; color: #92400e; }
.badge.st-edited { background: #dbeafe; color: #1d4ed8; }
.badge.st-approved, .badge.st-queued { background: #dcfce7; color: #15803d; }
.badge.st-published { background: #a7f3d0; color: #065f46; }
.badge.st-rejected { background: #fee2e2; color: #b91c1c; }
</style>
