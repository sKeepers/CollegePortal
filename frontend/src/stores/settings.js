import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'

const groupLabels = {
  general: 'Общие',
  academic: 'Учебный процесс',
  attendance: 'Посещаемость',
  admissions: 'Приемная комиссия',
  graduation: 'Выпуск',
  identity: 'Идентификация',
  hr: 'Отдел кадров',
  integrations: 'Интеграции',
  branding: 'Брендинг',
}

const groupOrder = ['general', 'academic', 'attendance', 'hr', 'admissions', 'graduation', 'identity', 'integrations', 'branding']

async function putSettings(payload) {
  const response = await api.authFetch(`${api.baseUrl}/admin/settings`, {
    method: 'PUT',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
    },
    body: JSON.stringify(payload),
  })
  const data = await response.json().catch(() => ({}))
  if (!response.ok) {
    const error = new Error(data.message || 'Настройки не сохранены')
    // Не ошибка, а требуемый шаг: экран должен предложить подтверждение.
    error.requiresProductionConfirmation = data.requires_production_confirmation === true
    throw error
  }
  return data
}

function flattenGroups(groups) {
  return Object.entries(groups || {}).flatMap(([group, items]) =>
    (items || []).map((item) => ({ ...item, group })),
  )
}

function normalizeEditableValue(setting) {
  if (setting.type === 'integer') return Number(setting.value ?? 0)
  return setting.value ?? ''
}

export const useSettingsStore = defineStore('settings', () => {
  const groups = ref({})
  const editable = ref({})
  const publicSettings = ref({})
  const loading = ref(false)
  const saving = ref(false)
  const publicLoading = ref(false)
  const publicLoaded = ref(false)
  const error = ref('')
  const lastMessage = ref('')
  // Какое действие ждёт подтверждения на production: 'save', 'reset' или ничего.
  const pendingProductionAction = ref('')

  const orderedGroups = computed(() => groupOrder
    .filter((key) => Array.isArray(groups.value[key]))
    .map((key) => ({ key, label: groupLabels[key] || key, items: groups.value[key] })))

  function hydrateEditable() {
    editable.value = Object.fromEntries(flattenGroups(groups.value).map((setting) => [
      `${setting.group}.${setting.key}`,
      normalizeEditableValue(setting),
    ]))
  }

  async function load() {
    loading.value = true
    error.value = ''
    try {
      const payload = await api.list('admin/settings')
      groups.value = payload?.data || {}
      hydrateEditable()
    } catch (err) {
      error.value = err.message || 'Не удалось загрузить настройки'
    } finally {
      loading.value = false
    }
  }

  async function loadPublic() {
    publicLoading.value = true
    try {
      const payload = await api.list('settings/public')
      publicSettings.value = payload?.data || {}
      publicLoaded.value = true
    } finally {
      publicLoading.value = false
    }
  }

  async function save(confirmProduction = false) {
    saving.value = true
    error.value = ''
    try {
      const settings = flattenGroups(groups.value).map((setting) => ({
        group: setting.group,
        key: setting.key,
        value: editable.value[`${setting.group}.${setting.key}`],
      }))
      const payload = await putSettings({ settings, ...(confirmProduction ? { confirm_production: true } : {}) })
      groups.value = payload?.data || {}
      hydrateEditable()
      pendingProductionAction.value = ''
      lastMessage.value = payload?.message || 'Настройки сохранены'
      await loadPublic()
      return payload
    } catch (err) {
      if (err.requiresProductionConfirmation) {
        pendingProductionAction.value = 'save'
        return null
      }
      error.value = err.message || 'Не удалось сохранить настройки'
      throw err
    } finally {
      saving.value = false
    }
  }

  async function resetToDefaults(confirmProduction = false) {
    saving.value = true
    error.value = ''
    try {
      const payload = await putSettings({ reset_to_defaults: true, ...(confirmProduction ? { confirm_production: true } : {}) })
      groups.value = payload?.data || {}
      hydrateEditable()
      pendingProductionAction.value = ''
      lastMessage.value = payload?.message || 'Настройки сброшены'
      await loadPublic()
      return payload
    } catch (err) {
      if (err.requiresProductionConfirmation) {
        pendingProductionAction.value = 'reset'
        return null
      }
      error.value = err.message || 'Не удалось сбросить настройки'
      throw err
    } finally {
      saving.value = false
    }
  }

  /**
   * Подтверждение того действия, которое запрос уже попытался выполнить.
   * Первый запрос ничего не меняет — он останавливается на проверке.
   */
  async function confirmProductionAction() {
    const action = pendingProductionAction.value
    pendingProductionAction.value = ''
    if (action === 'save') return save(true)
    if (action === 'reset') return resetToDefaults(true)
    return null
  }

  function cancelProductionAction() {
    pendingProductionAction.value = ''
  }

  function publicValue(group, key, fallback = null) {
    return publicSettings.value?.[group]?.[key] ?? fallback
  }

  return {
    groups,
    editable,
    publicSettings,
    loading,
    saving,
    publicLoading,
    publicLoaded,
    error,
    lastMessage,
    pendingProductionAction,
    orderedGroups,
    load,
    loadPublic,
    save,
    resetToDefaults,
    confirmProductionAction,
    cancelProductionAction,
    publicValue,
  }
})

export { groupLabels, groupOrder }
