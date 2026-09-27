<template>
  <div v-if="auth.loaded && auth.isAuthenticated" class="app">
    <aside class="sidebar">
      <div class="logo">БСТ · Дзен</div>
      <nav>
        <RouterLink v-for="r in visibleRoutes" :key="r.path" :to="r.path">
          {{ r.meta?.title }}
        </RouterLink>
      </nav>
      <div class="user">
        <div class="user-email" :title="auth.user?.email">{{ auth.user?.email }}</div>
        <button class="logout" @click="auth.logout()">Выход</button>
      </div>
    </aside>
    <main class="content">
      <RouterView />
    </main>
  </div>
  <RouterView v-else />
</template>

<script setup>
import { computed } from 'vue'
import { RouterLink, RouterView } from 'vue-router'
import { router } from './router'
import { useAuthStore } from './stores/auth'

const auth = useAuthStore()

const visibleRoutes = computed(() =>
  router.options.routes.filter(
    (r) => !r.meta?.public && (auth.isAdmin || !r.meta?.admin),
  ),
)
</script>

<style>
* { box-sizing: border-box; }
body { margin: 0; font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; background: #f4f6f8; color: #1e293b; }
.app { display: flex; min-height: 100vh; }
.sidebar { width: 220px; background: #0f172a; color: #cbd5e1; padding: 20px 12px; display: flex; flex-direction: column; }
.logo { font-weight: 700; color: #fff; margin-bottom: 24px; padding: 0 12px; }
.sidebar nav { display: flex; flex-direction: column; gap: 4px; flex: 1; }
.sidebar nav a { color: #94a3b8; text-decoration: none; padding: 8px 12px; border-radius: 8px; font-size: 14px; }
.sidebar nav a:hover { background: #1e293b; color: #e2e8f0; }
.sidebar nav a.router-link-active { background: #2563eb; color: #fff; }
.user { padding: 12px 12px 0; border-top: 1px solid #1e293b; }
.user-email { font-size: 12px; color: #94a3b8; margin-bottom: 8px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.logout { width: 100%; padding: 6px 0; border-radius: 8px; border: 1px solid #334155; background: transparent; color: #cbd5e1; cursor: pointer; font-size: 13px; }
.logout:hover { background: #1e293b; color: #fff; }
.content { flex: 1; padding: 24px 32px; }
h1 { font-size: 22px; margin-top: 0; }
.card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; margin-bottom: 16px; }
table { width: 100%; border-collapse: collapse; font-size: 14px; }
th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #e2e8f0; }
th { color: #64748b; font-weight: 600; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 12px; }
.badge.own { background: #fee2e2; color: #b91c1c; }
.badge.on { background: #dcfce7; color: #15803d; }
.badge.off { background: #f1f5f9; color: #64748b; }
.muted { color: #64748b; }
</style>
