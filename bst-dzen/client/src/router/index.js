import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'

export const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/login', name: 'login', component: () => import('../pages/Login.vue'), meta: { title: 'Вход', public: true } },
    { path: '/', name: 'dashboard', component: () => import('../pages/Dashboard.vue'), meta: { title: 'Состояние системы', admin: true } },
    { path: '/channels', name: 'channels', component: () => import('../pages/Channels.vue'), meta: { title: 'Каналы', admin: true } },
    { path: '/competitors', name: 'competitors', component: () => import('../pages/Competitors.vue'), meta: { title: 'Конкуренты' } },
    { path: '/drafts', name: 'drafts', component: () => import('../pages/Drafts.vue'), meta: { title: 'Черновики', admin: true } },
    { path: '/rules', name: 'rules', component: () => import('../pages/Rules.vue'), meta: { title: 'Правила', admin: true } },
    { path: '/settings', name: 'settings', component: () => import('../pages/Settings.vue'), meta: { title: 'Настройки', admin: true } },
  ],
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()

  if (!auth.loaded) {
    await auth.fetchUser()
  }

  if (!to.meta.public && !auth.isAuthenticated) {
    return { name: 'login', query: to.fullPath !== '/' ? { redirect: to.fullPath } : {} }
  }

  if (to.name === 'login' && auth.isAuthenticated) {
    return auth.isAdmin ? { path: '/' } : { path: '/competitors' }
  }

  if (to.meta.admin && !auth.isAdmin) {
    return { path: '/competitors' }
  }
})

router.afterEach((to) => {
  document.title = `${to.meta.title ?? ''} — БСТ Дзен`
})

export default router
