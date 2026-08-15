import { ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'
import { useAuthStore } from './auth'

export const useAccountStore = defineStore('account', () => {
  const account = ref(null)
  const identities = ref([])
  const availableProviders = ref([])
  const loading = ref(false)
  const saving = ref(false)
  const error = ref('')

  async function load() {
    loading.value = true
    error.value = ''
    try {
      account.value = (await api.list('account'))?.data || null
    } catch (err) {
      error.value = err.message || 'Не удалось загрузить учётную запись'
      throw err
    } finally {
      loading.value = false
    }
  }

  // Почта и телефон принадлежат человеку, а не учётной записи: сервер запишет их
  // в личную карточку, а копии в карточках преподавателя и студента получит зеркалом.
  async function saveContacts(payload) {
    saving.value = true
    error.value = ''
    try {
      const updated = (await api.patch('account/contacts', payload))?.data
      if (updated) account.value = { ...account.value, ...updated }
      return updated
    } catch (err) {
      error.value = err.message || 'Не удалось сохранить контакты'
      throw err
    } finally {
      saving.value = false
    }
  }

  // Способы входа: Telegram, MAX и что появится дальше. Пустой `available` означает,
  // что привязывать пока нечего — слой готов, провайдеров ещё нет.
  async function loadIdentities() {
    const payload = await api.list('account/identities')
    identities.value = payload?.data || []
    availableProviders.value = payload?.available || []
  }

  // Уведомления: каналы, галочки и одноразовый код привязки.
  const notifications = ref(null)

  async function loadNotifications() {
    notifications.value = (await api.list('account/notifications'))?.data || null
  }

  async function setNotification(event, channel, enabled) {
    saving.value = true
    error.value = ''
    try {
      notifications.value = (await api.post('account/notifications', { event, channel, enabled }))?.data || notifications.value
    } catch (err) {
      error.value = err.message || 'Не удалось изменить подписку'
      throw err
    } finally {
      saving.value = false
    }
  }

  async function requestLinkCode() {
    saving.value = true
    error.value = ''
    try {
      return (await api.post('account/notifications/link-code'))?.data || null
    } catch (err) {
      error.value = err.message || 'Не удалось получить код привязки'
      throw err
    } finally {
      saving.value = false
    }
  }

  async function linkIdentity(provider, payload, currentPassword) {
    saving.value = true
    error.value = ''
    try {
      await api.post('account/identities', { provider, payload, current_password: currentPassword })
      await loadIdentities()
    } catch (err) {
      error.value = err.message || 'Не удалось привязать способ входа'
      throw err
    } finally {
      saving.value = false
    }
  }

  async function unlinkIdentity(id, currentPassword) {
    saving.value = true
    error.value = ''
    try {
      await api.delete('account/identities', `${id}`, { current_password: currentPassword })
      await loadIdentities()
    } catch (err) {
      error.value = err.message || 'Не удалось отвязать способ входа'
      throw err
    } finally {
      saving.value = false
    }
  }

  async function changePassword(payload) {
    saving.value = true
    error.value = ''
    try {
      const result = await api.post('account/password', payload)
      // Свой пароль заведён — предложение убираем сразу, и здесь, и в меню входа.
      // Сервер отметку уже снял, перечитывать ради этого учётную запись незачем.
      if (account.value) account.value = { ...account.value, must_change_password: false }
      useAuthStore().passwordChanged()
      return result
    } catch (err) {
      error.value = err.message || 'Не удалось изменить пароль'
      throw err
    } finally {
      saving.value = false
    }
  }

  return {
    account, identities, availableProviders, notifications, loading, saving, error,
    load, saveContacts, changePassword, loadIdentities, linkIdentity, unlinkIdentity,
    loadNotifications, setNotification, requestLinkCode,
  }
})
