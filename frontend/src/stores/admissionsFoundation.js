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

function data(payload) {
  return payload?.data || null
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

export function referenceLabel(value) {
  return value?.name || value?.code || '—'
}

export function isRegistered(application) {
  return statusCode(application) === 'registered' || Boolean(application?.registered_at)
}

export const useAdmissionsFoundationStore = defineStore('admissionsFoundation', () => {
  const applications = ref([])
  const applicants = ref([])
  const people = ref([])
  const educationPrograms = ref([])
  const choices = ref([])
  const identityDocuments = ref([])
  const educationDocuments = ref([])
  const documentSet = ref(null)
  const readiness = ref(null)
  // Готовность к выгрузке в ФИС — отдельно от комплектности документов: там
  // правила приёмной комиссии, здесь требования схемы ФИС, и совпадают они не всегда.
  const fisReadiness = ref(null)
  const auditLogs = ref([])
  const selectedApplication = ref(null)
  const selectedId = ref(null)
  const filters = ref({ ...initialFilters })
  const pagination = ref({ current_page: 1, per_page: 20, total: 0 })
  const loading = ref(false)
  const detailsLoading = ref(false)
  const choicesLoading = ref(false)
  const documentsLoading = ref(false)
  const readinessLoading = ref(false)
  const fisReadinessLoading = ref(false)
  const auditLoading = ref(false)
  const applicantsLoading = ref(false)
  const peopleLoading = ref(false)
  const saving = ref(false)
  const error = ref('')
  const detailsError = ref('')
  const validationErrors = ref({})
  const referenceCatalogs = ref({})

  const sortedChoices = computed(() => [...choices.value].sort((left, right) => Number(left.priority || 0) - Number(right.priority || 0)))
  const selectedPerson = computed(() => selectedApplication.value?.applicant?.person || null)
  const selectedHasChoices = computed(() => sortedChoices.value.length > 0)
  const selectedRegistered = computed(() => isRegistered(selectedApplication.value))
  const currentIdentityDocuments = computed(() => identityDocuments.value.filter((document) => !document.replaced_at && !document.archived_at))
  const currentEducationDocuments = computed(() => educationDocuments.value.filter((document) => !document.replaced_at && !document.archived_at))

  function requestParams(tableOptions = {}) {
    // `rowsPerPage = 0` у таблицы означает «показать все», а сервер требует от
    // единицы до сотни и отвечает «per page: значение меньше допустимого».
    // Отказ приходил на пустом экране и выглядел поломкой портала, хотя это
    // размер страницы. `??` здесь не спасал: ноль — не `null`.
    const requested = Number(tableOptions.rowsPerPage ?? pagination.value.per_page)
    const rowsPerPage = Number.isFinite(requested) && requested >= 1
      ? Math.min(requested, 100)
      : 20

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
      error.value = err.message || 'Не удалось загрузить заявления приёмной комиссии'
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
      await Promise.all([
        loadChoices(selectedId.value),
        loadApplicationDocuments(selectedId.value),
        loadReadiness(selectedId.value),
        loadFisReadiness(selectedId.value),
        loadAudit(selectedId.value),
      ])
      return selectedApplication.value
    } catch (err) {
      detailsError.value = err.status === 404
        ? 'Заявление не найдено'
        : err.message || 'Не удалось загрузить карточку заявления'
      selectedApplication.value = null
      choices.value = []
      throw err
    } finally {
      detailsLoading.value = false
    }
  }

  async function searchApplicants(query = '', options = {}) {
    applicantsLoading.value = true

    try {
      const payload = await api.list('admissions/applicants', {
        q: query,
        per_page: options.per_page || 30,
        with_archived: options.with_archived ? 1 : undefined,
      })
      applicants.value = rows(payload)
      return applicants.value
    } catch (err) {
      detailsError.value = err.message || 'Не удалось загрузить абитуриентов'
      applicants.value = []
      return []
    } finally {
      applicantsLoading.value = false
    }
  }

  async function searchPeople(query = '') {
    peopleLoading.value = true

    try {
      // Подсказка по мере набора, а не список: здесь `list` с явным пределом,
      // и предел этот — не обрезанный список, а осознанная граница подсказки.
      // `listAll` тянул бы всех подходящих на каждое нажатие клавиши: замер на
      // стенде 29.08.2026 — «а» подходит 826 людям из 841, «ан» — 402, «ко» — 281.
      const payload = await api.list('people', { search: query, per_page: 30 })
      people.value = rows(payload)
      return people.value
    } catch (err) {
      detailsError.value = err.message || 'Не удалось загрузить людей'
      people.value = []
      return []
    } finally {
      peopleLoading.value = false
    }
  }

  async function createPerson(payload) {
    saving.value = true
    validationErrors.value = {}

    try {
      const person = data(await api.create('people', payload))
      await searchPeople('')
      return person
    } catch (err) {
      validationErrors.value = err.errors || {}
      throw err
    } finally {
      saving.value = false
    }
  }

  async function updatePerson(personId, payload) {
    saving.value = true
    validationErrors.value = {}

    try {
      const person = data(await api.update('people', personId, payload))
      if (selectedApplication.value?.applicant?.person?.id === person?.id) {
        selectedApplication.value = {
          ...selectedApplication.value,
          applicant: {
            ...selectedApplication.value.applicant,
            person,
          },
        }
      }
      await searchPeople('')
      return person
    } catch (err) {
      validationErrors.value = err.errors || {}
      throw err
    } finally {
      saving.value = false
    }
  }

  async function checkPersonDuplicates(payload) {
    validationErrors.value = {}

    try {
      return data(await api.post('people/duplicates/check', payload)) || { has_matches: false, matches_count: 0, matches: [] }
    } catch (err) {
      validationErrors.value = err.errors || {}
      throw err
    }
  }

  async function createApplicant(payload) {
    saving.value = true
    validationErrors.value = {}

    try {
      const applicant = data(await api.create('admissions/applicants', payload))
      await searchApplicants('', { with_archived: true })
      return applicant
    } catch (err) {
      validationErrors.value = err.errors || {}
      throw err
    } finally {
      saving.value = false
    }
  }

  async function updateApplicant(applicantId, payload) {
    saving.value = true
    validationErrors.value = {}

    try {
      const applicant = data(await api.update('admissions/applicants', applicantId, payload))
      if (selectedApplication.value?.applicant_id === applicant?.id) {
        selectedApplication.value = {
          ...selectedApplication.value,
          applicant,
        }
      }
      await searchApplicants('', { with_archived: true })
      return applicant
    } catch (err) {
      validationErrors.value = err.errors || {}
      throw err
    } finally {
      saving.value = false
    }
  }

  async function archiveApplicant(applicantId) {
    saving.value = true
    validationErrors.value = {}

    try {
      const applicant = data(await api.post(`admissions/applicants/${applicantId}/archive`, {}))
      if (selectedApplication.value?.applicant_id === applicant?.id) {
        selectedApplication.value = {
          ...selectedApplication.value,
          applicant,
        }
      }
      await searchApplicants('', { with_archived: true })
      return applicant
    } catch (err) {
      validationErrors.value = err.errors || {}
      throw err
    } finally {
      saving.value = false
    }
  }

  async function loadEducationPrograms() {
    try {
      const payload = await api.list('public/education-programs', { per_page: 200 })
      educationPrograms.value = rows(payload)
      return educationPrograms.value
    } catch {
      educationPrograms.value = []
      return []
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

  async function loadApplicationDocuments(applicationId = selectedId.value) {
    if (!applicationId) {
      documentSet.value = null
      identityDocuments.value = []
      educationDocuments.value = []
      return null
    }

    documentsLoading.value = true

    try {
      const application = selectedApplication.value?.id === applicationId
        ? selectedApplication.value
        : data(await api.list(`admissions/applications/${applicationId}`))
      const applicantId = application?.applicant_id
      const [setPayload, identityPayload, educationPayload] = await Promise.all([
        api.list(`admissions/applications/${applicationId}/documents`),
        applicantId ? api.list(`admissions/applicants/${applicantId}/identity-documents`, { with_archived: 1 }) : Promise.resolve({ data: [] }),
        applicantId ? api.list(`admissions/applicants/${applicantId}/education-documents`, { with_archived: 1 }) : Promise.resolve({ data: [] }),
      ])

      documentSet.value = data(setPayload)
      identityDocuments.value = rows(identityPayload)
      educationDocuments.value = rows(educationPayload)
      return documentSet.value
    } catch (err) {
      detailsError.value = err.message || 'Не удалось загрузить документы заявления'
      documentSet.value = null
      identityDocuments.value = []
      educationDocuments.value = []
      throw err
    } finally {
      documentsLoading.value = false
    }
  }

  async function loadReadiness(applicationId = selectedId.value) {
    if (!applicationId) {
      readiness.value = null
      return null
    }

    readinessLoading.value = true

    try {
      const payload = await api.list(`admissions/applications/${applicationId}/document-readiness`)
      readiness.value = data(payload)
      return readiness.value
    } catch (err) {
      readiness.value = null
      detailsError.value = err.message || 'Не удалось загрузить комплектность заявления'
      return null
    } finally {
      readinessLoading.value = false
    }
  }

  /**
   * Чего заявлению не хватает для выгрузки в ФИС.
   *
   * Отказ здесь не должен ломать карточку: ФИС — не единственное, ради чего её
   * открывают. Поэтому ошибка гасится в собственное состояние, а не в общее
   * `detailsError`, которое закрыло бы экран целиком.
   */
  async function loadFisReadiness(applicationId = selectedId.value) {
    if (!applicationId) {
      fisReadiness.value = null
      return null
    }

    fisReadinessLoading.value = true

    try {
      const payload = await api.list(`admissions/applications/${applicationId}/fis-readiness`)
      fisReadiness.value = data(payload)
      return fisReadiness.value
    } catch {
      fisReadiness.value = null
      return null
    } finally {
      fisReadinessLoading.value = false
    }
  }

  async function loadAudit(applicationId = selectedId.value) {
    if (!applicationId) {
      auditLogs.value = []
      return []
    }

    auditLoading.value = true

    try {
      const payload = await api.list('admin/audit', { module: 'Admissions', per_page: 100 })
      const id = Number(applicationId)
      auditLogs.value = rows(payload).filter((log) => (
        Number(log.entity_id) === id
        || Number(log.old_values?.id) === id
        || Number(log.new_values?.id) === id
        || Number(log.old_values?.application_id) === id
        || Number(log.new_values?.application_id) === id
      ))
      return auditLogs.value
    } catch {
      auditLogs.value = []
      return []
    } finally {
      auditLoading.value = false
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
      loadReferenceCatalog('applicant_statuses'),
      loadReferenceCatalog('admission_identity_document_types'),
      loadReferenceCatalog('admission_education_document_types'),
      loadReferenceCatalog('admission_document_verification_statuses'),
      loadReferenceCatalog('admission_document_file_categories'),
      loadReferenceCatalog('application_choice_statuses'),
      loadReferenceCatalog('base_education_types'),
      loadReferenceCatalog('education_levels'),
      loadReferenceCatalog('quota_types'),
    ])
  }

  async function createApplication(payload) {
    saving.value = true
    validationErrors.value = {}

    try {
      const created = data(await api.create('admissions/applications', payload))
      if (created?.id) {
        await loadApplication(created.id)
      }
      return created
    } catch (err) {
      validationErrors.value = err.errors || {}
      throw err
    } finally {
      saving.value = false
    }
  }

  async function updateApplication(applicationId, payload) {
    saving.value = true
    validationErrors.value = {}

    try {
      const updated = data(await api.update('admissions/applications', applicationId, payload))
      selectedApplication.value = updated
      await Promise.all([loadReadiness(applicationId), loadFisReadiness(applicationId), loadAudit(applicationId)])
      return updated
    } catch (err) {
      validationErrors.value = err.errors || {}
      throw err
    } finally {
      saving.value = false
    }
  }

  async function registerApplication(applicationId, confirmRequiredFields = true) {
    saving.value = true
    validationErrors.value = {}

    try {
      const registered = data(await api.post(`admissions/applications/${applicationId}/register`, { confirm_required_fields: confirmRequiredFields }))
      selectedApplication.value = registered
      await Promise.all([
        loadApplications(),
        loadApplicationDocuments(applicationId),
        loadReadiness(applicationId),
        loadFisReadiness(applicationId),
        loadAudit(applicationId),
      ])
      return registered
    } catch (err) {
      validationErrors.value = err.errors || {}
      throw err
    } finally {
      saving.value = false
    }
  }

  async function updateSnils(applicantId, snils) {
    if (!applicantId) return null
    saving.value = true
    validationErrors.value = {}

    try {
      const payload = await api.patch(`admissions/applicants/${applicantId}/snils`, { snils })
      if (selectedId.value) {
        await loadApplication(selectedId.value)
      }
      return data(payload)
    } catch (err) {
      validationErrors.value = err.errors || {}
      throw err
    } finally {
      saving.value = false
    }
  }

  async function createIdentityDocument(applicantId, payload, applicationId = selectedId.value) {
    saving.value = true
    validationErrors.value = {}

    try {
      const document = data(await api.create(`admissions/applicants/${applicantId}/identity-documents`, payload))
      if (applicationId && document?.id) {
        await assignIdentityDocument(applicationId, document.id)
      } else {
        await loadApplicationDocuments(applicationId)
      }
      return document
    } catch (err) {
      validationErrors.value = err.errors || {}
      throw err
    } finally {
      saving.value = false
    }
  }

  async function updateIdentityDocument(documentId, payload) {
    saving.value = true
    validationErrors.value = {}

    try {
      const document = data(await api.update('admissions/identity-documents', documentId, payload))
      await loadApplicationDocuments()
      await loadReadiness()
      await loadAudit()
      return document
    } catch (err) {
      validationErrors.value = err.errors || {}
      throw err
    } finally {
      saving.value = false
    }
  }

  async function createEducationDocument(applicantId, payload, applicationId = selectedId.value) {
    saving.value = true
    validationErrors.value = {}

    try {
      const document = data(await api.create(`admissions/applicants/${applicantId}/education-documents`, payload))
      if (applicationId && document?.id) {
        await assignEducationDocument(applicationId, document.id)
      } else {
        await loadApplicationDocuments(applicationId)
      }
      return document
    } catch (err) {
      validationErrors.value = err.errors || {}
      throw err
    } finally {
      saving.value = false
    }
  }

  async function updateEducationDocument(documentId, payload) {
    saving.value = true
    validationErrors.value = {}

    try {
      const document = data(await api.update('admissions/education-documents', documentId, payload))
      await loadApplicationDocuments()
      await loadReadiness()
      await loadAudit()
      return document
    } catch (err) {
      validationErrors.value = err.errors || {}
      throw err
    } finally {
      saving.value = false
    }
  }

  async function assignIdentityDocument(applicationId, documentId) {
    const set = data(await api.put(`admissions/applications/${applicationId}/identity-document`, { document_id: documentId }))
    documentSet.value = set
    await Promise.all([loadApplicationDocuments(applicationId), loadReadiness(applicationId), loadFisReadiness(applicationId), loadAudit(applicationId)])
    return set
  }

  async function assignEducationDocument(applicationId, documentId) {
    const set = data(await api.put(`admissions/applications/${applicationId}/education-document`, { document_id: documentId }))
    documentSet.value = set
    await Promise.all([loadApplicationDocuments(applicationId), loadReadiness(applicationId), loadFisReadiness(applicationId), loadAudit(applicationId)])
    return set
  }

  async function createChoice(applicationId, payload) {
    saving.value = true
    validationErrors.value = {}

    try {
      const choice = data(await api.create(`admissions/applications/${applicationId}/choices`, payload))
      await loadChoices(applicationId)
      return choice
    } catch (err) {
      validationErrors.value = err.errors || {}
      throw err
    } finally {
      saving.value = false
    }
  }

  async function updateChoice(choiceId, payload) {
    saving.value = true
    validationErrors.value = {}

    try {
      const choice = data(await api.update('admissions/choices', choiceId, payload))
      await loadChoices()
      return choice
    } catch (err) {
      validationErrors.value = err.errors || {}
      throw err
    } finally {
      saving.value = false
    }
  }

  async function deleteChoice(choiceId) {
    saving.value = true

    try {
      await api.delete('admissions/choices', choiceId)
      await loadChoices()
    } finally {
      saving.value = false
    }
  }

  async function uploadDocumentFile(kind, documentId, file, applicationId = selectedId.value, category = 'other') {
    const formData = new FormData()
    formData.append('file', file)
    formData.append('category', category || 'other')
    if (applicationId) formData.append('application_id', applicationId)

    const path = kind === 'education'
      ? `/admissions/education-documents/${documentId}/files`
      : `/admissions/identity-documents/${documentId}/files`

    const uploaded = data(await api.upload(path, formData))
    await Promise.all([loadApplicationDocuments(applicationId), loadReadiness(applicationId), loadFisReadiness(applicationId), loadAudit(applicationId)])
    return uploaded
  }

  async function deleteDocumentFile(fileId) {
    await api.delete('admissions/document-files', fileId)
    await Promise.all([loadApplicationDocuments(), loadReadiness(), loadAudit()])
  }

  async function downloadDocumentFile(file) {
    const blob = await api.download(`/admissions/document-files/${file.id}/download`)
    const url = window.URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = file.original_name || `document-${file.id}`
    link.click()
    window.URL.revokeObjectURL(url)
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
    applicants.value = []
    people.value = []
    educationPrograms.value = []
    choices.value = []
    identityDocuments.value = []
    educationDocuments.value = []
    documentSet.value = null
    readiness.value = null
    auditLogs.value = []
    selectedApplication.value = null
    selectedId.value = null
    filters.value = { ...initialFilters }
    pagination.value = { current_page: 1, per_page: 20, total: 0 }
    loading.value = false
    detailsLoading.value = false
    choicesLoading.value = false
    documentsLoading.value = false
    readinessLoading.value = false
    auditLoading.value = false
    applicantsLoading.value = false
    peopleLoading.value = false
    saving.value = false
    error.value = ''
    detailsError.value = ''
    validationErrors.value = {}
    referenceCatalogs.value = {}
  }

  return {
    applications,
    applicants,
    people,
    educationPrograms,
    choices,
    identityDocuments,
    educationDocuments,
    currentIdentityDocuments,
    currentEducationDocuments,
    documentSet,
    readiness,
    fisReadiness,
    fisReadinessLoading,
    loadFisReadiness,
    auditLogs,
    sortedChoices,
    selectedApplication,
    selectedPerson,
    selectedId,
    selectedHasChoices,
    selectedRegistered,
    filters,
    pagination,
    loading,
    detailsLoading,
    choicesLoading,
    documentsLoading,
    readinessLoading,
    auditLoading,
    applicantsLoading,
    peopleLoading,
    saving,
    error,
    detailsError,
    validationErrors,
    referenceCatalogs,
    loadApplications,
    loadApplication,
    searchApplicants,
    searchPeople,
    createPerson,
    updatePerson,
    checkPersonDuplicates,
    createApplicant,
    updateApplicant,
    archiveApplicant,
    loadEducationPrograms,
    loadChoices,
    loadApplicationDocuments,
    loadReadiness,
    loadAudit,
    loadReferences,
    createApplication,
    updateApplication,
    registerApplication,
    updateSnils,
    createIdentityDocument,
    updateIdentityDocument,
    createEducationDocument,
    updateEducationDocument,
    assignIdentityDocument,
    assignEducationDocument,
    createChoice,
    updateChoice,
    deleteChoice,
    uploadDocumentFile,
    deleteDocumentFile,
    downloadDocumentFile,
    selectApplication,
    setFilters,
    resetFilters,
    reset,
  }
})
