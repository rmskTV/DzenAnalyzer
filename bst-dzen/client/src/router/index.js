import { createRouter, createWebHistory } from 'vue-router'

export const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/', name: 'dashboard', component: () => import('../pages/Dashboard.vue'), meta: { title: 'Состояние системы' } },
    { path: '/channels', name: 'channels', component: () => import('../pages/Channels.vue'), meta: { title: 'Каналы' } },
    { path: '/competitors', name: 'competitors', component: () => import('../pages/Competitors.vue'), meta: { title: 'Конкуренты' } },
    { path: '/drafts', name: 'drafts', component: () => import('../pages/Drafts.vue'), meta: { title: 'Черновики' } },
    { path: '/rules', name: 'rules', component: () => import('../pages/Rules.vue'), meta: { title: 'Правила' } },
    { path: '/settings', name: 'settings', component: () => import('../pages/Settings.vue'), meta: { title: 'Настройки' } },
  ],
})

router.afterEach((to) => {
  document.title = `${to.meta.title ?? ''} — БСТ Дзен`
})

export default router
