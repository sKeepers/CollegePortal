import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'

const initialFilters = {
  search: '',
  user_id: '',
  module: '',
  action: '',
  date_from: '',
  date_to: '',
}

function extractRows(payload) {
  return Array.isArray(payload?.data) ? payload.data : []
}

export const useAuditStore = defineStore('audit', () => {
  const logs = ref([])
  const users = ref([])
  const filters = ref({ ...initialFilters })
  const selectedId = ref(null)
  const loading = ref(false)
  const error = ref('')

  const selectedLog = computed(() => logs.value.find((log) => Number(log.id) === Number(selectedId.value)) || null)
  const userOptions = computed(() => users.value.map((user) => ({ label: `${user.name} · ${user.email}`, value: user.id })))
  const moduleOptions = computed(() => [...new Set(logs.value.map((log) => log.module).filter(Boolean))].sort().map((value) => ({ label: moduleLabel(value), value })))
  const actionOptions = computed(() => [...new Set(logs.value.map((log) => log.action).filter(Boolean))].sort().map((value) => ({ label: actionLabel(value), value })))

  async function load() {
    loading.value = true
    error.value = ''

    try {
      const [logsPayload, usersPayload] = await Promise.all([
        api.list('admin/audit', { ...filters.value, per_page: 200 }),
        api.list('admin/users', { per_page: 300 }),
      ])
      logs.value = extractRows(logsPayload)
      users.value = extractRows(usersPayload)

      if (selectedId.value && !selectedLog.value) {
        selectedId.value = null
      }
    } catch (err) {
      error.value = err.message || 'Не удалось загрузить журнал аудита'
    } finally {
      loading.value = false
    }
  }

  function resetFilters() {
    filters.value = { ...initialFilters }
  }

  return {
    logs,
    users,
    filters,
    selectedId,
    loading,
    error,
    selectedLog,
    userOptions,
    moduleOptions,
    actionOptions,
    load,
    resetFilters,
  }
})

export function moduleLabel(value) {
  const labels = {
    auth: 'Авторизация',
    users: 'Пользователи',
    roles: 'Роли',
    import: 'Импорт',
    digital_identity: 'Цифровые пропуска',
    demo_data: 'Демо-данные',
    settings: 'Настройки',
    reference_data: 'Справочники',
  }
  return labels[value] || value || '—'
}

export function actionLabel(value) {
  const labels = {
    login: 'Вход',
    logout: 'Выход',
    create: 'Создание',
    update: 'Редактирование',
    delete: 'Удаление',
    block: 'Блокировка',
    unblock: 'Разблокировка',
    assign_roles: 'Назначение ролей',
    preview: 'Предпросмотр импорта',
    validate: 'Проверка импорта',
    confirm: 'Подтверждение импорта',
    export_template: 'Скачивание шаблона',
    issue_qr: 'Выпуск QR',
    revoke_qr: 'Отзыв QR',
    create_demo: 'Создание демо',
    clear_demo: 'Очистка демо',
    reset_defaults: 'Сброс настроек',
    delete_item: 'Удаление элемента справочника',
    update_item: 'Редактирование элемента справочника',
    create_item: 'Создание элемента справочника',
    delete_catalog: 'Удаление справочника',
    update_catalog: 'Редактирование справочника',
    create_catalog: 'Создание справочника',
    import: 'Импорт',
    export: 'Экспорт',
  }
  return labels[value] || value || '—'
}
