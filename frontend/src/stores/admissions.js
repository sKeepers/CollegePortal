import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'

const initialFilters = {
  search: '',
  status: '',
  specialtyId: '',
  educationProgramId: '',
  documentsStatus: '',
  submittedDate: '',
}

export const STATUS_OPTIONS = [
  { label: 'Новое', value: 'new', tone: 'info' },
  { label: 'Принято', value: 'accepted', tone: 'success' },
  { label: 'Требуется уточнение', value: 'needs_clarification', tone: 'warning' },
  { label: 'Зачислен', value: 'enrolled', tone: 'success' },
  { label: 'Отклонено', value: 'rejected', tone: 'danger' },
]

export const EDUCATION_BASE_OPTIONS = [
  { label: 'После 9 класса', value: 'after_9' },
  { label: 'После 11 класса', value: 'after_11' },
]

export const COMPLETENESS_OPTIONS = [
  { label: 'Все', value: '' },
  { label: 'Без документов', value: 'no_documents' },
  { label: 'Неполный комплект', value: 'incomplete' },
  { label: 'Полный комплект', value: 'complete' },
]

function extractRows(payload) {
  return Array.isArray(payload?.data) ? payload.data : []
}

function extractMeta(payload) {
  return payload?.meta || null
}

export function applicantName(application) {
  return [application?.last_name, application?.first_name, application?.middle_name].filter(Boolean).join(' ')
}

export function statusLabel(status) {
  return STATUS_OPTIONS.find((option) => option.value === status)?.label || status || '—'
}

export function statusTone(status) {
  return STATUS_OPTIONS.find((option) => option.value === status)?.tone || 'neutral'
}

export function educationBaseLabel(value) {
  return EDUCATION_BASE_OPTIONS.find((option) => option.value === value)?.label || value || '—'
}

export function specialtyLabel(specialty) {
  return [specialty?.code, specialty?.name].filter(Boolean).join(' · ')
}

export function programLabel(program) {
  return [
    program?.name,
    specialtyLabel(program?.specialty),
    program?.study_form,
    program?.year_start,
  ].filter(Boolean).join(' · ')
}

function documentCounts(application) {
  const documents = Array.isArray(application?.documents) ? application.documents : []
  const required = Number(application?.required_documents_count ?? application?.documents_total_count ?? documents.length ?? 6)
  const received = Number(application?.documents_count ?? application?.documents_received_count ?? documents.filter((document) => document.is_received).length ?? 0)
  const missing = Number(application?.documents_missing_count ?? Math.max(0, required - received))

  return { required, received, missing }
}

export function documentsCompleteness(application) {
  if (application?.documents_status) {
    return application.documents_status
  }

  const { required, received } = documentCounts(application)

  if (received === 0) {
    return 'no_documents'
  }

  return received >= required ? 'complete' : 'incomplete'
}

export function formatDate(value) {
  if (!value) {
    return '—'
  }

  const isoDate = String(value).match(/^(\d{4})-(\d{2})-(\d{2})/)

  if (isoDate) {
    return `${isoDate[3]}.${isoDate[2]}.${isoDate[1]}`
  }

  const date = new Date(value)

  if (Number.isNaN(date.getTime())) {
    return String(value)
  }

  return date.toLocaleDateString('ru-RU')
}

export function formatDateTime(value) {
  if (!value) {
    return '—'
  }

  const date = new Date(value)

  if (Number.isNaN(date.getTime())) {
    return formatDate(value)
  }

  return date.toLocaleString('ru-RU', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

export function documentsCompletenessLabel(application) {
  const { required, received } = documentCounts(application)
  const status = documentsCompleteness(application)

  if (status === 'no_documents') {
    return `Без документов: ${received}/${required}`
  }

  return status === 'complete'
    ? `Полный комплект: ${received}/${required}`
    : `Неполный комплект: ${received}/${required}`
}

function cleanPayload(payload) {
  return {
    education_program_id: Number(payload.education_program_id),
    last_name: payload.last_name?.trim() || '',
    first_name: payload.first_name?.trim() || '',
    middle_name: payload.middle_name?.trim() || null,
    birth_date: payload.birth_date || null,
    phone: payload.phone?.trim() || null,
    email: payload.email?.trim() || null,
    education_base: payload.education_base || 'after_9',
    status: payload.status || 'new',
    submitted_at: payload.submitted_at || new Date().toISOString().slice(0, 10),
    comment: payload.comment?.trim() || null,
  }
}

function normalize(value) {
  return String(value || '').trim().toLowerCase()
}

function apiFilters(filters) {
  return {
    search: filters.search,
    status: filters.status,
    specialtyId: filters.specialtyId,
    educationProgramId: filters.educationProgramId,
    documents_status: filters.documentsStatus || filters.completeness,
    submittedDate: filters.submittedDate,
  }
}

export const useAdmissionsStore = defineStore('admissions', () => {
  const applications = ref([])
  const educationPrograms = ref([])
  const groups = ref([])
  const filters = ref({ ...initialFilters })
  const pagination = ref(null)
  const emptyStats = () => ({ total: 0, new: 0, no_documents: 0, incomplete: 0, complete: 0, documents_provided: 0, ready: 0, recommended: 0, enrolled: 0, rejected: 0 })
  const stats = ref(emptyStats())
  const selectedId = ref(null)
  const loading = ref(false)
  const saving = ref(false)
  const error = ref('')
  const importSummary = ref(null)

  const selectedApplication = computed(() => (
    applications.value.find((application) => Number(application.id) === Number(selectedId.value)) || null
  ))

  const educationProgramOptions = computed(() => educationPrograms.value.map((program) => ({
    label: programLabel(program) || `Программа #${program.id}`,
    value: program.id,
    specialty_id: program.specialty_id,
  })))

  const specialtyOptions = computed(() => {
    const specialties = new Map()

    educationPrograms.value.forEach((program) => {
      if (program.specialty) {
        specialties.set(program.specialty.id, {
          label: specialtyLabel(program.specialty) || `Специальность #${program.specialty.id}`,
          value: program.specialty.id,
        })
      }
    })

    return [...specialties.values()].sort((a, b) => a.label.localeCompare(b.label, 'ru'))
  })

  const groupOptions = computed(() => groups.value.map((group) => ({
    label: [group.name, group.course ? `${group.course} курс` : '', group.education_program?.study_form].filter(Boolean).join(' · '),
    value: group.id,
  })))

  const selectedApplicationDocuments = computed(() => (
    Array.isArray(selectedApplication.value?.documents) ? selectedApplication.value.documents : []
  ))

  const selectedApplicationEvents = computed(() => (
    Array.isArray(selectedApplication.value?.events) ? selectedApplication.value.events : []
  ))

  const quickQueues = computed(() => [
    { key: 'new', label: 'Новые', value: stats.value.new || 0, status: 'new', documentsStatus: '', tone: 'info' },
    { key: 'no_documents', label: 'Без документов', value: stats.value.no_documents || 0, status: '', documentsStatus: 'no_documents', tone: 'danger' },
    { key: 'incomplete', label: 'Неполный комплект', value: stats.value.incomplete || 0, status: '', documentsStatus: 'incomplete', tone: 'warning' },
    { key: 'complete', label: 'Полный комплект', value: stats.value.complete || 0, status: '', documentsStatus: 'complete', tone: 'success' },
    { key: 'documents_provided', label: 'Получение подтверждено', value: stats.value.documents_provided || 0, status: '', documentsStatus: '', tone: 'neutral' },
    { key: 'ready', label: 'Готовы к зачислению', value: stats.value.ready || 0, status: 'ready_for_enrollment', documentsStatus: '', tone: 'success' },
    { key: 'recommended', label: 'Рекомендованы', value: stats.value.recommended || 0, status: '', documentsStatus: '', tone: 'info' },
    { key: 'enrolled', label: 'Зачислены', value: stats.value.enrolled || 0, status: 'enrolled', documentsStatus: '', tone: 'success' },
    { key: 'rejected', label: 'Отклонены', value: stats.value.rejected || 0, status: 'rejected', documentsStatus: '', tone: 'danger' },
    { key: 'all', label: 'Всего', value: stats.value.total || 0, status: '', documentsStatus: '', tone: 'neutral' },
  ])

  const filteredApplications = computed(() => applications.value)

  async function load(tableOptions = {}) {
    loading.value = true
    error.value = ''

    try {
      const rowsPerPage = Number(tableOptions.rowsPerPage ?? 50)
      const query = {
        ...apiFilters(filters.value),
        page: rowsPerPage === 0 ? '' : Number(tableOptions.page || 1),
        per_page: rowsPerPage,
      }
      const [applicationsPayload, statsPayload, programsPayload, groupsPayload] = await Promise.all([
        api.list('applicant-applications', query),
        api.list('admissions/stats', apiFilters(filters.value)),
        api.list('education-programs'),
        api.list('groups'),
      ])

      applications.value = extractRows(applicationsPayload)
      stats.value = statsPayload?.data || emptyStats()
      educationPrograms.value = extractRows(programsPayload)
      groups.value = extractRows(groupsPayload)
      pagination.value = extractMeta(applicationsPayload) || {
        total: applications.value.length,
        per_page: rowsPerPage,
        current_page: 1,
      }

      if (selectedId.value && !selectedApplication.value) {
        selectedId.value = null
      }
    } catch (err) {
      error.value = err.message || 'Не удалось загрузить заявления абитуриентов'
    } finally {
      loading.value = false
    }
  }

  async function save(payload, id = null) {
    saving.value = true
    error.value = ''

    try {
      const data = cleanPayload(payload)
      const response = id
        ? await api.update('applicant-applications', id, data)
        : await api.create('applicant-applications', data)

      await load()

      const savedId = response?.data?.id || id
      if (savedId) {
        selectedId.value = savedId
      }

      return response?.data || null
    } catch (err) {
      error.value = err.message || 'Не удалось сохранить заявление'
      throw err
    } finally {
      saving.value = false
    }
  }

  async function remove(application) {
    if (!application?.id) {
      return
    }

    loading.value = true
    error.value = ''

    try {
      await api.delete('applicant-applications', application.id)
      if (Number(selectedId.value) === Number(application.id)) {
        selectedId.value = null
      }
      await load()
    } catch (err) {
      error.value = err.message || 'Не удалось удалить заявление'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function importCsv(file) {
    if (!file) {
      return null
    }

    loading.value = true
    error.value = ''
    importSummary.value = null

    try {
      const formData = new FormData()
      formData.append('file', file)
      const payload = await api.upload('/applicant-applications/import', formData)

      importSummary.value = payload?.data || null
      await load()

      return importSummary.value
    } catch (err) {
      error.value = err.message || 'Не удалось импортировать CSV'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function exportCsv() {
    error.value = ''

    try {
      const blob = await api.download('/applicant-applications/export')
      const url = window.URL.createObjectURL(blob)
      const link = document.createElement('a')

      link.href = url
      link.download = 'applicant-applications.csv'
      link.click()
      window.URL.revokeObjectURL(url)
    } catch (err) {
      error.value = err.message || 'Не удалось экспортировать CSV'
      throw err
    }
  }

  async function enroll(application, payload) {
    if (!application?.id) {
      return null
    }

    saving.value = true
    error.value = ''

    try {
      const response = await api.create(`applicant-applications/${application.id}/enroll`, {
        group_id: Number(payload.group_id),
        enrollment_date: payload.enrollment_date,
      })

      await load()
      selectedId.value = response?.data?.application?.id || application.id

      return response?.data || null
    } catch (err) {
      error.value = err.message || 'Не удалось зачислить абитуриента'
      throw err
    } finally {
      saving.value = false
    }
  }

  async function loadDocuments(application) {
    if (!application?.id) return null
    saving.value = true
    error.value = ''
    try {
      const payload = await api.list(`admissions/${application.id}/documents`)
      const index = applications.value.findIndex((item) => Number(item.id) === Number(application.id))
      if (index >= 0) {
        applications.value[index] = {
          ...applications.value[index],
          documents: payload?.data || [],
          documents_count: payload?.meta?.documents_count ?? applications.value[index].documents_count,
          required_documents_count: payload?.meta?.required_documents_count ?? applications.value[index].required_documents_count,
          documents_missing_count: payload?.meta?.documents_missing_count ?? applications.value[index].documents_missing_count,
          documents_complete: payload?.meta?.documents_complete ?? applications.value[index].documents_complete,
          documents_status: payload?.meta?.documents_status ?? applications.value[index].documents_status,
        }
      }
      return payload
    } catch (err) {
      error.value = err.message || 'Не удалось загрузить документы'
      throw err
    } finally {
      saving.value = false
    }
  }

  async function receiveDocument(application, document, payload = {}) {
    if (!application?.id || !document?.type) return null
    saving.value = true
    error.value = ''
    try {
      const response = await api.create(`admissions/${application.id}/documents/${document.type}/receive`, payload)
      await loadDocuments(application)
      return response?.data || null
    } catch (err) {
      error.value = err.message || 'Не удалось отметить документ'
      throw err
    } finally {
      saving.value = false
    }
  }

  async function uploadDocument(application, document, file) {
    if (!application?.id || !document?.type || !file) return null
    saving.value = true
    error.value = ''
    try {
      const formData = new FormData()
      formData.append('file', file)
      const response = await api.upload(`/admissions/${application.id}/documents/${document.type}/upload`, formData)
      await loadDocuments(application)
      return response?.data || null
    } catch (err) {
      error.value = err.message || 'Не удалось загрузить файл документа'
      throw err
    } finally {
      saving.value = false
    }
  }

  async function verifyDocument(application, document) {
    if (!application?.id || !document?.id) return null
    saving.value = true
    error.value = ''
    try {
      const response = await api.create(`admissions/${application.id}/documents/${document.id}/verify`, {})
      await loadDocuments(application)
      return response?.data || null
    } catch (err) {
      error.value = err.message || 'Не удалось подтвердить документ'
      throw err
    } finally {
      saving.value = false
    }
  }

  async function rejectDocument(application, document, reason) {
    if (!application?.id || !document?.id) return null
    saving.value = true
    error.value = ''
    try {
      const response = await api.create(`admissions/${application.id}/documents/${document.id}/reject`, { rejection_reason: reason || 'Документ требует исправления' })
      await loadDocuments(application)
      return response?.data || null
    } catch (err) {
      error.value = err.message || 'Не удалось отклонить документ'
      throw err
    } finally {
      saving.value = false
    }
  }

  async function downloadDocumentFile(application, doc, file) {
    if (!application?.id || !doc?.id || !file?.id) return null
    const blob = await api.download(`/admissions/${application.id}/documents/${doc.id}/files/${file.id}/download`)
    const url = window.URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = file.original_name || 'document'
    link.click()
    window.URL.revokeObjectURL(url)
    return blob
  }

  async function deleteDocumentFile(application, document, file) {
    if (!application?.id || !document?.id || !file?.id) return null
    saving.value = true
    error.value = ''
    try {
      await api.delete(`admissions/${application.id}/documents/${document.id}/files`, file.id)
      await loadDocuments(application)
    } catch (err) {
      error.value = err.message || 'Не удалось удалить файл документа'
      throw err
    } finally {
      saving.value = false
    }
  }

  async function updateDocument(application, document, payload) {
    if (!application?.id || !document?.type) {
      return null
    }

    saving.value = true
    error.value = ''

    try {
      const response = await api.update(`applicant-applications/${application.id}/documents`, document.type, payload)
      await load()
      selectedId.value = response?.data?.id || application.id

      return response?.data || null
    } catch (err) {
      error.value = err.message || 'Не удалось обновить документ'
      throw err
    } finally {
      saving.value = false
    }
  }


  async function previewBulk(request) {
    saving.value = true
    error.value = ''
    try {
      const payload = await api.create('admissions/bulk/preview', request)
      return payload?.data || null
    } catch (err) {
      error.value = err.message || 'Не удалось подготовить массовую операцию'
      throw err
    } finally {
      saving.value = false
    }
  }

  async function applyBulk(request) {
    saving.value = true
    error.value = ''
    try {
      if (request.action === 'export_selected') {
        const blob = await api.postDownload('/admissions/bulk/apply', request)
        const url = window.URL.createObjectURL(blob)
        const link = document.createElement('a')
        link.href = url
        link.download = 'admissions-selected.csv'
        link.click()
        window.URL.revokeObjectURL(url)
        return { action: 'export_selected' }
      }
      const payload = await api.create('admissions/bulk/apply', request)
      await load()
      return payload?.data || null
    } catch (err) {
      error.value = err.message || 'Не удалось выполнить массовую операцию'
      throw err
    } finally {
      saving.value = false
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

  function selectApplication(application) {
    selectedId.value = application?.id || null
  }

  function selectApplicationById(id) {
    selectedId.value = id || null
  }

  return {
    applications,
    filteredApplications,
    educationPrograms,
    educationProgramOptions,
    specialtyOptions,
    groupOptions,
    filters,
    pagination,
    stats,
    selectedId,
    selectedApplication,
    selectedApplicationDocuments,
    selectedApplicationEvents,
    quickQueues,
    loading,
    saving,
    error,
    importSummary,
    load,
    save,
    remove,
    importCsv,
    exportCsv,
    enroll,
    loadDocuments,
    receiveDocument,
    uploadDocument,
    verifyDocument,
    rejectDocument,
    downloadDocumentFile,
    deleteDocumentFile,
    updateDocument,
    previewBulk,
    applyBulk,
    setFilters,
    resetFilters,
    selectApplication,
    selectApplicationById,
  }
})
