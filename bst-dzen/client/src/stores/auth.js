import { defineStore } from 'pinia'
import { api } from '../api'

export const useAuthStore = defineStore('auth', {
  state: () => ({ user: null, loaded: false }),
  getters: {
    isAuthenticated: (state) => !!state.user,
    isAdmin: (state) => !!state.user?.is_admin,
  },
  actions: {
    async fetchUser() {
      try {
        this.user = await api.get('/auth/me')
      } catch {
        this.user = null
      } finally {
        this.loaded = true
      }
    },
    async login(email, password) {
      await api.csrf()
      this.user = await api.post('/auth/login', { email, password })
    },
    async logout() {
      try {
        await api.post('/auth/logout', {})
      } catch {
        // сессия могла уже истечь — всё равно уходим на логин
      }
      this.user = null
      window.location.href = '/login'
    },
  },
})
