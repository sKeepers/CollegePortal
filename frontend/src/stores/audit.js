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

  /**
   * Журнал листается на сервере, а не на месте.
   *
   * Раньше хранилище забирало 200 строк и отдавало их таблице целиком: подпись
   * читалась «1 - 20 из 200», хотя записей в журнале 16 977 (замер на стенде
   * 29.08.2026). Оператор идёт в журнал искать, что произошло, и двести он
   * прочтёт как «больше ничего не было» — ошибка в сторону «ничего не было»
   * здесь дороже, чем в сторону «мало данных».
   */
  const pagination = ref({ page: 1, per_page: 20, total: 0 })
  const direction = ref('desc')

  // Значения для полей отбора приходят с сервера по всей таблице: собранные из
  // выданной страницы, они называли бы модулями те, что попали в двадцать строк.
  const options = ref({ modules: [], actions: [] })
  const filters = ref({ ...initialFilters })
  const selectedId = ref(null)
  const loading = ref(false)
  const error = ref('')

  const selectedLog = computed(() => logs.value.find((log) => Number(log.id) === Number(selectedId.value)) || null)
  const userOptions = computed(() => users.value.map((user) => ({ label: `${user.name} · ${user.email}`, value: user.id })))
  const moduleOptions = computed(() => options.value.modules.map((value) => ({ label: moduleLabel(value), value })))
  const actionOptions = computed(() => options.value.actions.map((value) => ({ label: actionLabel(value), value })))

  async function load(params = {}) {
    loading.value = true
    error.value = ''

    const page = Number(params.page ?? pagination.value.page) || 1
    const perPage = Number(params.per_page ?? pagination.value.per_page) || 20

    if (params.direction) {
      direction.value = params.direction === 'asc' ? 'asc' : 'desc'
    }

    try {
      const [logsPayload, usersPayload] = await Promise.all([
        api.list('admin/audit', {
          ...filters.value, page, per_page: perPage, direction: direction.value,
        }),
        // Список пользователей нужен целиком: он наполняет поле отбора, и
        // страница из него означала бы, что часть авторов выбрать нельзя.
        api.listAll('admin/users').catch(() => null),
      ])

      logs.value = extractRows(logsPayload)
      users.value = extractRows(usersPayload)

      const meta = logsPayload?.meta ?? {}
      pagination.value = {
        page: Number(meta.current_page) || page,
        per_page: Number(meta.per_page) || perPage,
        total: Number(meta.total) || logs.value.length,
      }

      options.value = {
        modules: Array.isArray(logsPayload?.options?.modules) ? logsPayload.options.modules : [],
        actions: Array.isArray(logsPayload?.options?.actions) ? logsPayload.options.actions : [],
      }

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
    pagination.value = { ...pagination.value, page: 1 }
  }

  return {
    logs,
    users,
    filters,
    pagination,
    options,
    direction,
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
    Exports: 'Выгрузки',
    FIS: 'ФИС ГИА',
    FRDO: 'ФРДО',
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
    csv_exported: 'Выгрузка файла',
    package_exported_json: 'Выгрузка пакета (JSON)',
  }
  return labels[value] || value || '—'
}
