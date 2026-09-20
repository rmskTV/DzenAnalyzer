<template>
  <div>
    <h1>Настройки</h1>

    <p v-if="loading" class="muted">Загрузка…</p>
    <template v-else>
      <div class="card">
        <h3>Публикация по типам контента</h3>
        <p class="muted">publish — автоматически в RSS-фид; draft — только через одобрение редактора; hold — не публиковать.</p>
        <table>
          <thead><tr><th>Тип</th><th>Режим</th></tr></thead>
          <tbody>
            <tr v-for="(mode, key) in settings.publish_modes" :key="key">
              <td>{{ key }}</td>
              <td>
                <select v-model="settings.publish_modes[key]" @change="save('publish_modes')">
                  <option>publish</option><option>draft</option><option>hold</option>
                </select>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="card">
        <h3>Конвейер контента</h3>
        <label>Дневная норма генерации</label>
        <input type="number" v-model.number="settings.content.daily_limit" @change="save('content')" min="1" max="20" />
        <label>Максимум публикаций в сутки</label>
        <input type="number" v-model.number="settings.content.max_per_day" @change="save('content')" min="1" max="30" />
      </div>

      <div class="card">
        <h3>Парсеры (HTTP-источники материалов)</h3>
        <p class="muted">
          Эндпоинты должны отдавать JSON-массив:
          <code>[{"external_id","url","title","text","published_at"}]</code>
        </p>
        <div v-for="(p, i) in settings.parsers" :key="i" class="parser-row">
          <input v-model="p.name" placeholder="имя (bst_site)" />
          <input v-model="p.url" placeholder="https://..." />
          <label class="inline"><input type="checkbox" v-model="p.active" /> активен</label>
          <button class="btn" @click="settings.parsers.splice(i, 1)">удалить</button>
        </div>
        <button class="btn" @click="settings.parsers.push({ name: '', url: '', active: true })">+ парсер</button>
        <button class="btn primary" @click="save('parsers')">сохранить парсеры</button>
      </div>

      <div class="card">
        <h3>Модели LLM по операциям (Bothub)</h3>
        <p class="muted">Пусто = модель по умолчанию из .env (LLM_MODEL_DEFAULT). API-ключ — только в .env.</p>
        <div class="parser-row" v-for="op in ['default', 'classify', 'rewrite', 'evergreen', 'rules']" :key="op">
          <span class="opname">{{ op }}</span>
          <input v-model="settings.llm.models[op]" placeholder="например, deepseek-chat" />
        </div>
        <button class="btn primary" @click="save('llm')">сохранить модели</button>
      </div>

      <p v-if="saved" class="oknote">Сохранено ✓</p>
    </template>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { api } from '../api'

const settings = ref(null)
const loading = ref(true)
const saved = ref(false)

async function load() {
  settings.value = await api.get('/settings')
  loading.value = false
}

async function save(key) {
  await api.put('/settings', { key, value: settings.value[key] })
  saved.value = true
  setTimeout(() => (saved.value = false), 2000)
}

onMounted(load)
</script>

<style scoped>
h3 { margin-top: 0; }
label { display: block; font-size: 12px; color: #64748b; margin: 10px 0 4px; }
label.inline { display: inline-flex; align-items: center; gap: 6px; margin: 0; }
input { padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 6px; min-width: 200px; }
.parser-row { display: flex; gap: 8px; align-items: center; margin-bottom: 8px; flex-wrap: wrap; }
.opname { width: 90px; color: #64748b; font-size: 14px; }
.btn { padding: 6px 14px; border-radius: 8px; border: 1px solid #cbd5e1; background: #fff; cursor: pointer; margin-right: 8px; }
.btn.primary { background: #2563eb; color: #fff; }
.oknote { color: #15803d; }
</style>
