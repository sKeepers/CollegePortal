import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'

export const useAuthStore = defineStore('auth', () => {
  const user = ref(null)
  const loading = ref(false)
  const initialized = ref(false)
  const error = ref('')

  const isAuthenticated = computed(() => Boolean(user.value && api.token()))
  const isAdmin = computed(() => user.value?.role?.code === 'admin')
  const permissions = computed(() => user.value?.role?.permissions || [])

  function can(permission) {
    return isAdmin.value || permissions.value.includes(permission)
  }

  async function login(credentials) {
    loading.value = true
    error.value = ''

    try {
      const payload = await api.login(credentials)
      api.setToken(payload.token)
      user.value = payload.user
      initialized.value = true
    } catch (caught) {
      error.value = caught.message
      throw caught
    } finally {
      loading.value = false
    }
  }

  async function restore() {
    if (!api.token()) {
      initialized.value = true
      user.value = null
      return
    }

    loading.value = true
    error.value = ''

    try {
      const payload = await api.me()
      user.value = payload.data
    } catch {
      api.clearToken()
      user.value = null
    } finally {
      initialized.value = true
      loading.value = false
    }
  }

  async function logout() {
    loading.value = true
    error.value = ''

    try {
      await api.logout()
    } catch {
      // Even if the token is stale, local logout must complete.
    } finally {
      api.clearToken()
      user.value = null
      initialized.value = true
      loading.value = false
    }
  }

  return {
    user,
    loading,
    initialized,
    error,
    isAuthenticated,
    permissions,
    isAdmin,
    can,
    login,
    restore,
    logout,
  }
})
