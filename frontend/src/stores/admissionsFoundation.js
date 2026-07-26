import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'

const initialFilters = {
  q: '',
  status: '',
  admission_year: '',
  source_id: '',
  has_choices: '',
}

function rows(payload) {
  return Array.isArray(payload?.data) ? payload.data : []
}

function meta(payload) {
  return payload?.meta || null
}

function normalizePagination(payload, fallback = {}) {
  const source = meta(payload) || {}

  return {
    current_page: Number(source.current_page || fallback.page || 1),
    per_page: Number(source.per_page || fallback.rowsPerPage || rows(payload).length || 20),
    total: Number(source.total || rows(payload).length || 0),
  }
}

export function personName(application) {
  return application?.applicant?.person?.full_name || application?.applicant?.display_name || 'ФИО не указано'
}

export function statusCode(application) {
  return application?.status?.code || application?.status || ''
}

export function statusLabel(application) {
  return application?.status?.name || statusCode(application) || 'Статус не указан'
}

export function sourceLabel(application) {
  return application?.source?.name || application?.source?.code || 'Источник не указан'
}

export function formatDate(value) {
  if (!value) return '—'
  const isoDate = String(value).match(/^(\d{4})-(\d{2})-(\d{2})/)
  if (isoDate) return `${isoDate[3]}.${isoDate[2]}.${isoDate[1]}`

  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleDateString('ru-RU')
}

export function formatDateTime(value) {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return formatDate(value)

  return date.toLocaleString('ru-RU', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

export function programName(program) {
  if (!program) return 'Программа не указана'

  return [
    program.name,
    program.specialty?.code,
    program.specialty?.name,
    program.study_form,
  ].filter(Boolean).join(' · ')
}

export function choiceProgramName(choice) {
  return programName(choice?.education_program)
}

export const useAdmissionsFoundationStore = defineStore('admissionsFoundation', () => {
  const applications = ref([])
  const choices = ref([])
  const selectedApplication = ref(null)
  const selectedId = ref(null)
  const filters = ref({ ...initialFilters })
  const pagination = ref({ current_page: 1, per_page: 20, total: 0 })
  const loading = ref(false)
  const detailsLoading = ref(false)
  const choicesLoading = ref(false)
  const error = ref('')
  const detailsError = ref('')
  const referenceCatalogs = ref({})

  const sortedChoices = computed(() => [...choices.value].sort((left, right) => Number(left.priority || 0) - Number(right.priority || 0)))
  const selectedPerson = computed(() => selectedApplication.value?.applicant?.person || null)
  const selectedHasChoices = computed(() => sortedChoices.value.length > 0)

  function requestParams(tableOptions = {}) {
    const rowsPerPage = Number(tableOptions.rowsPerPage ?? pagination.value.per_page ?? 20)

    return {
      q: filters.value.q,
      status: filters.value.status,
      admission_year: filters.value.admission_year,
      source_id: filters.value.source_id,
      has_choices: filters.value.has_choices,
      page: Number(tableOptions.page || pagination.value.current_page || 1),
      per_page: rowsPerPage,
    }
  }

  async function loadApplications(tableOptions = {}) {
    loading.value = true
    error.value = ''

    try {
      const payload = await api.list('admissions/applications', requestParams(tableOptions))
      applications.value = rows(payload)
      pagination.value = normalizePagination(payload, tableOptions)

      if (selectedId.value && !applications.value.some((item) => Number(item.id) === Number(selectedId.value))) {
        await loadApplication(selectedId.value)
      }
    } catch (err) {
      error.value = err.message || 'Не удалось загрузить заявления Admissions Foundation'
      applications.value = []
    } finally {
      loading.value = false
    }
  }

  async function loadApplication(id) {
    if (!id) {
      selectedId.value = null
      selectedApplication.value = null
      choices.value = []
      return null
    }

    detailsLoading.value = true
    detailsError.value = ''

    try {
      const payload = await api.list(`admissions/applications/${id}`)
      selectedApplication.value = payload?.data || null
      selectedId.value = selectedApplication.value?.id || id
      await loadChoices(selectedId.value)
      return selectedApplication.value
    } catch (err) {
      detailsError.value = err.status === 404
        ? 'Заявление Admissions Foundation не найдено'
        : err.message || 'Не удалось загрузить карточку заявления'
      selectedApplication.value = null
      choices.value = []
      throw err
    } finally {
      detailsLoading.value = false
    }
  }

  async function loadChoices(applicationId = selectedId.value) {
    if (!applicationId) {
      choices.value = []
      return []
    }

    choicesLoading.value = true

    try {
      const payload = await api.list(`admissions/applications/${applicationId}/choices`)
      choices.value = rows(payload)
      return choices.value
    } catch (err) {
      detailsError.value = err.message || 'Не удалось загрузить выбранные программы'
      choices.value = []
      throw err
    } finally {
      choicesLoading.value = false
    }
  }

  async function loadReferenceCatalog(code) {
    if (!code || referenceCatalogs.value[code]) {
      return referenceCatalogs.value[code] || null
    }

    try {
      const payload = await api.list(`admissions/reference/${code}`)
      const catalog = payload?.data || null
      referenceCatalogs.value = {
        ...referenceCatalogs.value,
        [code]: catalog,
      }

      return catalog
    } catch {
      referenceCatalogs.value = {
        ...referenceCatalogs.value,
        [code]: { code, items: [] },
      }

      return referenceCatalogs.value[code]
    }
  }

  async function loadReferences() {
    await Promise.all([
      loadReferenceCatalog('admission_application_statuses'),
      loadReferenceCatalog('admission_sources'),
    ])
  }

  async function selectApplication(application) {
    const id = application?.id || null
    selectedId.value = id
    selectedApplication.value = application || null

    if (id) {
      await loadApplication(id)
    } else {
      choices.value = []
    }
  }

  function setFilters(nextFilters) {
    filters.value = {
      ...filters.value,
      ...nextFilters,
    }
  }

  function resetFilters() {
    filters.value = { ...initialFilters }
  }

  function reset() {
    applications.value = []
    choices.value = []
    selectedApplication.value = null
    selectedId.value = null
    filters.value = { ...initialFilters }
    pagination.value = { current_page: 1, per_page: 20, total: 0 }
    loading.value = false
    detailsLoading.value = false
    choicesLoading.value = false
    error.value = ''
    detailsError.value = ''
    referenceCatalogs.value = {}
  }

  return {
    applications,
    choices,
    sortedChoices,
    selectedApplication,
    selectedPerson,
    selectedId,
    selectedHasChoices,
    filters,
    pagination,
    loading,
    detailsLoading,
    choicesLoading,
    error,
    detailsError,
    referenceCatalogs,
    loadApplications,
    loadApplication,
    loadChoices,
    loadReferences,
    selectApplication,
    setFilters,
    resetFilters,
    reset,
  }
})
