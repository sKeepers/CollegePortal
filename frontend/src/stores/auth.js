import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'

export const useAuthStore = defineStore('auth', () => {
  const user = ref(null)
  const loading = ref(false)
  const initialized = ref(false)
  const error = ref('')

  const isAuthenticated = computed(() => Boolean(user.value && api.hasSession()))
  // Портал выдал пароль, своего человек ещё не заводил. Признак приходит и при входе,
  // и при восстановлении сессии, поэтому предложение переживает обновление страницы.
  const mustChangePassword = computed(() => Boolean(user.value?.must_change_password))
  const isAdmin = computed(() => user.value?.role?.code === 'admin')
  const permissions = computed(() => user.value?.permissions || user.value?.role?.permissions || [])
  const roleCodes = computed(() => {
    const codes = new Set()
    if (user.value?.role?.code) codes.add(user.value.role.code)
    ;(user.value?.roles || []).forEach((role) => { if (role?.code) codes.add(role.code) })
    return Array.from(codes)
  })

  function can(permission) {
    return isAdmin.value || permissions.value.includes(permission)
  }

  function hasRole(codes) {
    const allowed = Array.isArray(codes) ? codes : [codes]
    return allowed.some((code) => roleCodes.value.includes(code))
  }

  async function login(credentials) {
    loading.value = true
    error.value = ''

    try {
      // Токен в ответе больше не приходит: сервер поставил его в httpOnly cookie,
      // которую браузер подставит сам. Хранить здесь нечего.
      const payload = await api.login(credentials)
      user.value = payload.user
      initialized.value = true
    } catch (caught) {
      error.value = caught.message
      throw caught
    } finally {
      loading.value = false
    }
  }

  /**
   * Вход через внешний способ. Учётную запись он не создаёт: непривязанный аккаунт
   * получит отказ от сервера, и это правило слоя, а не особенность экрана.
   */
  async function loginWithProvider(provider, payload, staySignedIn = true) {
    loading.value = true
    error.value = ''

    try {
      const result = await api.loginWithProvider(provider, payload, staySignedIn)
      user.value = result.user
      initialized.value = true
    } catch (caught) {
      error.value = caught.message
      throw caught
    } finally {
      loading.value = false
    }
  }

  /**
   * Вход по коду из бота. Отдельная функция, а не флаг у `login`: у неё свои
   * два шага и свой текст ошибки, и смешивать их значило бы, что «неверный
   * пароль» однажды покажется человеку, который пароль не вводил.
   */
  async function loginWithCode(login, code, staySignedIn = true) {
    loading.value = true
    error.value = ''

    try {
      const result = await api.loginWithCode(login, code, staySignedIn)
      user.value = result.user
      initialized.value = true
    } catch (caught) {
      error.value = caught.message
      throw caught
    } finally {
      loading.value = false
    }
  }

  async function restore() {
    if (!api.hasSession()) {
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
      api.clearSession()
      user.value = null
    } finally {
      initialized.value = true
      loading.value = false
    }
  }

  /**
   * Человек завёл свой пароль. Отметку снял сервер, но перечитывать ради этого всю
   * учётную запись незачем — предложение должно исчезнуть сразу.
   */
  function passwordChanged() {
    if (user.value) user.value = { ...user.value, must_change_password: false }
  }

  async function logout() {
    loading.value = true
    error.value = ''

    try {
      await api.logout()
    } catch {
      // Even if the token is stale, local logout must complete.
    } finally {
      api.clearSession()
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
    mustChangePassword,
    passwordChanged,
    permissions,
    roleCodes,
    isAdmin,
    can,
    hasRole,
    login,
    loginWithProvider,
    loginWithCode,
    restore,
    logout,
  }
})
