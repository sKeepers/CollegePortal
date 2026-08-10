import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'
import { useAuthStore } from './auth'

function rows(payload) {
  return Array.isArray(payload?.data) ? payload.data : []
}

function meta(payload) {
  return payload?.meta || null
}

function cleanEmployee(payload) {
  return {
    person_id: payload.person_id || null,
    last_name: payload.last_name?.trim() || undefined,
    first_name: payload.first_name?.trim() || undefined,
    middle_name: payload.middle_name?.trim() || null,
    email: payload.email?.trim() || null,
    phone: payload.phone?.trim() || null,
    snils: payload.snils?.trim() || null,
    employee_number: payload.employee_number?.trim() || '',
    status: payload.status || 'active',
    employment_type: payload.employment_type || 'full_time',
    hired_at: payload.hired_at || null,
    dismissed_at: payload.dismissed_at || null,
    primary_department_id: payload.primary_department_id || null,
    primary_position_id: payload.primary_position_id || null,
    workload_rate: Number(payload.workload_rate || 1),
    is_teacher: Boolean(payload.is_teacher),
    comment: payload.comment?.trim() || null,
  }
}

export const useHrStore = defineStore('hr', () => {
  const employees = ref([])
  const departments = ref([])
  const positions = ref([])
  const people = ref([])
  const filters = ref({ search: '', status: '', department_id: '', position_id: '', employment_type: '', is_teacher: '', working: '' })
  const pagination = ref(null)
  const selectedId = ref(null)
  const loading = ref(false)
  const saving = ref(false)
  const error = ref('')

  const calendar = ref({ periods: [], summary: {} })
  const affectedLessons = ref([])
  const candidates = ref([])
  const replacementPreview = ref(null)

  async function loadCalendar(params = {}) {
    loading.value = true
    error.value = ''
    try {
      const payload = await api.list('hr/calendar', params)
      calendar.value = payload?.data || { periods: [], summary: {} }
      return calendar.value
    } catch (err) {
      error.value = err.message || 'Не удалось загрузить кадровый календарь'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function previewPeriod(employeeId, payload) {
    return api.create(`hr/employees/${employeeId}/status-periods/preview`, payload)
  }

  async function applyPeriod(employeeId, payload) {
    const response = await api.create(`hr/employees/${employeeId}/status-periods/apply`, payload)
    await loadEmployees()
    return response?.data || response
  }

  async function cancelPeriod(periodId, reason = '') {
    const response = await api.create(`hr/status-periods/${periodId}/cancel`, { reason })
    await loadEmployees()
    return response?.data || response
  }

  async function loadAffectedLessons(periodId) {
    const payload = await api.list(`hr/status-periods/${periodId}/affected-lessons`)
    affectedLessons.value = rows(payload)
    return affectedLessons.value
  }

  async function loadReplacementCandidates(scheduleEntryId, employeeId) {
    const payload = await api.list(`hr/replacements/candidates/${scheduleEntryId}/${employeeId}`)
    candidates.value = rows(payload)
    return candidates.value
  }

  async function previewReplacements(items) {
    replacementPreview.value = await api.create('hr/replacements/preview', { items })
    return replacementPreview.value
  }

  async function applyReplacements(items) {
    const response = await api.create('hr/replacements/apply', { items })
    replacementPreview.value = null
    return response?.data || response
  }

  const selectedEmployee = computed(() => employees.value.find((item) => Number(item.id) === Number(selectedId.value)) || null)
  const departmentOptions = computed(() => departments.value.map((item) => ({ label: item.name, value: item.id })))
  const positionOptions = computed(() => positions.value.map((item) => ({ label: item.name, value: item.id })))
  const personOptions = computed(() => people.value.map((item) => ({ label: item.full_name, value: item.id, person: item })))

  async function loadEmployees(params = {}) {
    loading.value = true
    error.value = ''
    try {
      const payload = await api.list('employees', { ...filters.value, ...params })
      employees.value = rows(payload)
      pagination.value = meta(payload)
      if (selectedId.value && !selectedEmployee.value) selectedId.value = null
      if (!selectedId.value && employees.value.length) selectedId.value = employees.value[0].id
    } catch (err) {
      error.value = err.message || 'Не удалось загрузить сотрудников'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function loadDictionaries() {
    const [depPayload, posPayload] = await Promise.all([api.list('departments'), api.list('positions')])
    // Реестр людей закрыт кадрам намеренно: там же студенты и абитуриенты.
    // Раньше запрос уходил всё равно и всегда отвечал 403 — подсказка «выбрать
    // существующего человека» молча оставалась пустой. Дубли это не создаёт:
    // существующего человека находит HrService при сохранении карточки.
    const peoplePayload = useAuthStore().can('people.view')
      ? await api.list('people', { per_page: 100 }).catch(() => ({ data: [] }))
      : { data: [] }
    departments.value = rows(depPayload)
    positions.value = rows(posPayload)
    people.value = rows(peoplePayload)
  }

  async function load() {
    await loadDictionaries()
    await loadEmployees()
  }

  async function saveEmployee(payload, id = null) {
    saving.value = true
    error.value = ''
    try {
      const response = id ? await api.update('employees', id, cleanEmployee(payload)) : await api.create('employees', cleanEmployee(payload))
      await loadEmployees()
      selectedId.value = response?.data?.id || id || selectedId.value
      return response?.data || null
    } catch (err) {
      error.value = err.message || 'Не удалось сохранить сотрудника'
      throw err
    } finally {
      saving.value = false
    }
  }

  async function dismissEmployee(employee) {
    if (!employee?.id) return
    saving.value = true
    try {
      await api.delete('employees', employee.id)
      await loadEmployees()
    } finally {
      saving.value = false
    }
  }

  async function issueDigitalPass(employee, expiresAt = null) {
    if (!employee?.id) return null
    saving.value = true
    error.value = ''
    try {
      const response = await api.create(`employees/${employee.id}/digital-pass`, { expires_at: expiresAt || null })
      return response?.data || null
    } catch (err) {
      error.value = err.message || 'Не удалось выпустить цифровой пропуск сотрудника'
      throw err
    } finally {
      saving.value = false
    }
  }

  async function saveDepartment(payload, id = null) {
    saving.value = true
    try {
      const data = { code: payload.code?.trim() || null, name: payload.name?.trim() || '', description: payload.description?.trim() || null, is_active: payload.is_active !== false }
      const response = id ? await api.update('departments', id, data) : await api.create('departments', data)
      await loadDictionaries()
      return response?.data || null
    } finally {
      saving.value = false
    }
  }

  async function removeDepartment(item) {
    if (!item?.id) return
    await api.delete('departments', item.id)
    await loadDictionaries()
  }

  async function savePosition(payload, id = null) {
    saving.value = true
    try {
      const data = { code: payload.code?.trim() || null, name: payload.name?.trim() || '', category: payload.category?.trim() || null, description: payload.description?.trim() || null, is_active: payload.is_active !== false }
      const response = id ? await api.update('positions', id, data) : await api.create('positions', data)
      await loadDictionaries()
      return response?.data || null
    } finally {
      saving.value = false
    }
  }

  async function removePosition(item) {
    if (!item?.id) return
    await api.delete('positions', item.id)
    await loadDictionaries()
  }

  async function addAssignment(employeeId, payload) {
    await api.create(`employees/${employeeId}/assignments`, payload)
    await loadEmployees()
  }

  async function addStatusPeriod(employeeId, payload) {
    await api.create(`employees/${employeeId}/status-periods`, payload)
    await loadEmployees()
  }

  return {
    employees,
    departments,
    positions,
    people,
    filters,
    pagination,
    selectedId,
    loading,
    saving,
    error,
    selectedEmployee,
    departmentOptions,
    positionOptions,
    personOptions,
    load,
    loadEmployees,
    loadDictionaries,
    saveEmployee,
    dismissEmployee,
    issueDigitalPass,
    saveDepartment,
    removeDepartment,
    savePosition,
    removePosition,
    addAssignment,
    addStatusPeriod,
    calendar,
    affectedLessons,
    candidates,
    replacementPreview,
    loadCalendar,
    previewPeriod,
    applyPeriod,
    cancelPeriod,
    loadAffectedLessons,
    loadReplacementCandidates,
    previewReplacements,
    applyReplacements,
  }
})
