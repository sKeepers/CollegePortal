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
  integrations: 'Интеграции',
  branding: 'Брендинг',
}

const groupOrder = ['general', 'academic', 'attendance', 'admissions', 'graduation', 'identity', 'integrations', 'branding']

async function putSettings(payload) {
  const response = await fetch(`${api.baseUrl}/admin/settings`, {
    method: 'PUT',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(api.token() ? { Authorization: `Bearer ${api.token()}` } : {}),
    },
    body: JSON.stringify(payload),
  })
  const data = await response.json().catch(() => ({}))
  if (!response.ok) throw new Error(data.message || 'Настройки не сохранены')
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

  async function save() {
    saving.value = true
    error.value = ''
    try {
      const settings = flattenGroups(groups.value).map((setting) => ({
        group: setting.group,
        key: setting.key,
        value: editable.value[`${setting.group}.${setting.key}`],
      }))
      const payload = await putSettings({ settings })
      groups.value = payload?.data || {}
      hydrateEditable()
      lastMessage.value = payload?.message || 'Настройки сохранены'
      await loadPublic()
      return payload
    } catch (err) {
      error.value = err.message || 'Не удалось сохранить настройки'
      throw err
    } finally {
      saving.value = false
    }
  }

  async function resetToDefaults() {
    saving.value = true
    error.value = ''
    try {
      const payload = await putSettings({ reset_to_defaults: true })
      groups.value = payload?.data || {}
      hydrateEditable()
      lastMessage.value = payload?.message || 'Настройки сброшены'
      await loadPublic()
      return payload
    } catch (err) {
      error.value = err.message || 'Не удалось сбросить настройки'
      throw err
    } finally {
      saving.value = false
    }
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
    orderedGroups,
    load,
    loadPublic,
    save,
    resetToDefaults,
    publicValue,
  }
})

export { groupLabels, groupOrder }
