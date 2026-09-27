<template>
  <div class="login">
    <form class="card" @submit.prevent="submit">
      <h1>БСТ · Дзен</h1>
      <p class="muted">Вход в систему анализа каналов</p>

      <label>
        Email
        <input v-model="email" type="email" autocomplete="username" required autofocus />
      </label>
      <label>
        Пароль
        <input v-model="password" type="password" autocomplete="current-password" required />
      </label>

      <p v-if="error" class="error">{{ error }}</p>

      <button class="btn" type="submit" :disabled="loading">
        {{ loading ? 'Вход…' : 'Войти' }}
      </button>
    </form>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const email = ref('')
const password = ref('')
const error = ref('')
const loading = ref(false)

async function submit() {
  loading.value = true
  error.value = ''
  try {
    await auth.login(email.value, password.value)
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : null
    await router.push(redirect || (auth.isAdmin ? '/' : '/competitors'))
  } catch (e) {
    error.value = parseError(e)
  } finally {
    loading.value = false
  }
}

function parseError(e) {
  try {
    const body = JSON.parse(e.message.slice(e.message.indexOf(':') + 1))
    return body.errors?.email?.[0] || body.message || 'Не удалось войти'
  } catch {
    return 'Не удалось войти'
  }
}
</script>

<style scoped>
.login {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #0f172a;
}
.card {
  width: 360px;
  display: flex;
  flex-direction: column;
  gap: 14px;
}
h1 { margin: 0; }
label {
  display: flex;
  flex-direction: column;
  gap: 6px;
  font-size: 14px;
  color: #64748b;
}
input {
  padding: 10px 12px;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  font-size: 15px;
}
input:focus { outline: 2px solid #2563eb; border-color: #2563eb; }
.error { color: #b91c1c; font-size: 14px; margin: 0; }
.btn {
  padding: 10px;
  border: none;
  border-radius: 8px;
  background: #2563eb;
  color: #fff;
  font-size: 15px;
  cursor: pointer;
}
.btn:disabled { opacity: 0.6; cursor: default; }
</style>
