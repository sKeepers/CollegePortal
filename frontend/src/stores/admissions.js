import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'

const initialFilters = {
  search: '',
  status: '',
  specialtyId: '',
  educationProgramId: '',
  completeness: '',
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
  { label: 'Полный комплект', value: 'complete' },
  { label: 'Неполный комплект', value: 'incomplete' },
  { label: 'Без документов', value: 'empty' },
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
  const total = Number(application?.documents_total_count ?? documents.length ?? 0)
  const received = Number(application?.documents_received_count ?? documents.filter((document) => document.is_received).length ?? 0)

  return { total, received }
}

export function documentsCompleteness(application) {
  const { total, received } = documentCounts(application)

  if (!total) {
    return 'empty'
  }

  return received >= total ? 'complete' : 'incomplete'
}

export function documentsCompletenessLabel(application) {
  const { total, received } = documentCounts(application)

  if (!total) {
    return 'Документы не заведены'
  }

  return received >= total ? `Полный комплект: ${received}/${total}` : `Неполный комплект: ${received}/${total}`
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

export const useAdmissionsStore = defineStore('admissions', () => {
  const applications = ref([])
  const educationPrograms = ref([])
  const groups = ref([])
  const filters = ref({ ...initialFilters })
  const pagination = ref(null)
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

  const quickQueues = computed(() => {
    const total = applications.value.length
    const byStatus = (status) => applications.value.filter((application) => application.status === status).length
    const incomplete = applications.value.filter((application) => documentsCompleteness(application) === 'incomplete').length
    const ready = applications.value.filter((application) => application.status === 'accepted' && documentsCompleteness(application) === 'complete').length

    return [
      { key: 'new', label: 'Новые', value: byStatus('new'), status: 'new', completeness: '', tone: 'info' },
      { key: 'incomplete', label: 'Неполный комплект', value: incomplete, status: '', completeness: 'incomplete', tone: 'warning' },
      { key: 'ready', label: 'Готовы к зачислению', value: ready, status: 'accepted', completeness: 'complete', tone: 'success' },
      { key: 'enrolled', label: 'Зачислены', value: byStatus('enrolled'), status: 'enrolled', completeness: '', tone: 'success' },
      { key: 'rejected', label: 'Отклонены', value: byStatus('rejected'), status: 'rejected', completeness: '', tone: 'danger' },
      { key: 'all', label: 'Всего', value: total, status: '', completeness: '', tone: 'neutral' },
    ]
  })

  const filteredApplications = computed(() => {
    const search = normalize(filters.value.search)

    return applications.value.filter((application) => {
      const program = application.education_program
      const specialty = program?.specialty
      const searchable = [
        applicantName(application),
        application.phone,
        application.email,
        application.comment,
        programLabel(program),
        specialtyLabel(specialty),
      ].filter(Boolean).join(' ').toLowerCase()

      const matchesSearch = !search || searchable.includes(search)
      const matchesStatus = !filters.value.status || application.status === filters.value.status
      const matchesProgram = !filters.value.educationProgramId || Number(application.education_program_id) === Number(filters.value.educationProgramId)
      const matchesSpecialty = !filters.value.specialtyId || Number(program?.specialty_id || specialty?.id) === Number(filters.value.specialtyId)
      const matchesCompleteness = !filters.value.completeness || documentsCompleteness(application) === filters.value.completeness
      const matchesDate = !filters.value.submittedDate || application.submitted_at === filters.value.submittedDate

      return matchesSearch && matchesStatus && matchesProgram && matchesSpecialty && matchesCompleteness && matchesDate
    })
  })

  async function load() {
    loading.value = true
    error.value = ''

    try {
      const [applicationsPayload, programsPayload, groupsPayload] = await Promise.all([
        api.list('applicant-applications'),
        api.list('education-programs'),
        api.list('groups'),
      ])

      applications.value = extractRows(applicationsPayload)
      educationPrograms.value = extractRows(programsPayload)
      groups.value = extractRows(groupsPayload)
      pagination.value = extractMeta(applicationsPayload)

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
    updateDocument,
    setFilters,
    resetFilters,
    selectApplication,
    selectApplicationById,
  }
})
