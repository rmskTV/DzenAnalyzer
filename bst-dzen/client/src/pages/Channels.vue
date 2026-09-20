<template>
  <div>
    <h1>Каналы</h1>
    <div class="card">
      <p v-if="loading" class="muted">Загрузка…</p>
      <p v-else-if="error" style="color: #b91c1c">{{ error }}</p>
      <table v-else>
        <thead>
          <tr>
            <th>Канал</th>
            <th>Ключ Дзена</th>
            <th>Роль</th>
            <th>Активен</th>
            <th>Подписчики</th>
            <th>Конкурентов</th>
            <th>Таймзона</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="ch in channels" :key="ch.id">
            <td>{{ ch.title }}</td>
            <td><code>{{ ch.dzen_key }}</code></td>
            <td>
              <span v-if="ch.is_own" class="badge own">мой</span>
              <span v-else class="muted">—</span>
            </td>
            <td>
              <span :class="ch.is_active ? 'badge on' : 'badge off'">
                {{ ch.is_active ? 'да' : 'нет' }}
              </span>
            </td>
            <td>{{ ch.subscribers.toLocaleString('ru') }}</td>
            <td>{{ ch.competitors_count }}</td>
            <td>{{ ch.timezone }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { api } from '../api'

const channels = ref([])
const loading = ref(true)
const error = ref(null)

onMounted(async () => {
  try {
    channels.value = await api.get('/channels')
  } catch (e) {
    error.value = `Не удалось загрузить каналы: ${e.message}`
  } finally {
    loading.value = false
  }
})
</script>
