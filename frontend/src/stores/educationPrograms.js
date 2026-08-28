import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'
import { loadReferences } from '../services/referenceLoader'

const initialFilters = {
  search: '',
  specialty_id: '',
  year_start: '',
  study_form: '',
  active_only: false,
}

export const STUDY_FORM_OPTIONS = ['Очная', 'Очно-заочная', 'Заочная']

function extractRows(payload) {
  return Array.isArray(payload?.data) ? payload.data : []
}

function extractMeta(payload) {
  return payload?.meta || null
}

export function programTitle(program) {
  return [program?.name, program?.year_start].filter(Boolean).join(' · ')
}

function cleanPayload(payload) {
  return {
    specialty_id: payload.specialty_id ? Number(payload.specialty_id) : null,
    name: payload.name?.trim() || '',
    year_start: payload.year_start === '' || payload.year_start === null ? null : Number(payload.year_start),
    study_form: payload.study_form?.trim() || '',
    study_years:
      payload.study_years === '' || payload.study_years === null ? null : Number(payload.study_years),
    is_active: Boolean(payload.is_active),
    description: payload.description?.trim() || '',
  }
}

export const useEducationProgramsStore = defineStore('educationPrograms', () => {
  const programs = ref([])
  const specialties = ref([])
  const groups = ref([])
  const filters = ref({ ...initialFilters })
  const pagination = ref(null)
  const selectedId = ref(null)
  const loading = ref(false)
  const saving = ref(false)
  const error = ref('')
  const importSummary = ref(null)

  const selectedProgram = computed(() => (
    programs.value.find((program) => Number(program.id) === Number(selectedId.value)) || null
  ))

  const specialtyOptions = computed(() => specialties.value.map((specialty) => ({
    label: [specialty.code, specialty.name].filter(Boolean).join(' · '),
    value: specialty.id,
  })))

  const yearOptions = computed(() => {
    const years = new Set(programs.value.map((program) => program.year_start).filter(Boolean))

    return [...years].sort((a, b) => b - a).map((year) => ({ label: String(year), value: year }))
  })

  const studyFormOptions = computed(() => {
    const forms = new Set([
      ...STUDY_FORM_OPTIONS,
      ...programs.value.map((program) => program.study_form).filter(Boolean),
    ])

    return [...forms].map((form) => ({ label: form, value: form }))
  })

  const selectedProgramGroups = computed(() => {
    const programId = Number(selectedId.value)

    if (!programId) {
      return []
    }

    return groups.value.filter((group) => Number(group.education_program_id) === programId)
  })

  const filteredPrograms = computed(() => {
    const search = filters.value.search.trim().toLowerCase()

    return programs.value.filter((program) => {
      const searchable = [
        program.name,
        program.study_form,
        program.year_start,
        program.specialty?.code,
        program.specialty?.name,
        program.description,
      ].filter(Boolean).join(' ').toLowerCase()

      const matchesSearch = !search || searchable.includes(search)
      const matchesSpecialty = !filters.value.specialty_id
        || Number(program.specialty_id) === Number(filters.value.specialty_id)
      const matchesYear = !filters.value.year_start
        || Number(program.year_start) === Number(filters.value.year_start)
      const matchesForm = !filters.value.study_form || program.study_form === filters.value.study_form
      const matchesActive = !filters.value.active_only || program.is_active

      return matchesSearch && matchesSpecialty && matchesYear && matchesForm && matchesActive
    })
  })

  async function load() {
    loading.value = true
    error.value = ''

    try {
      // Группы — справочник карточки: показывают, кто по программе учится.
      // Отказ по ним не должен закрывать реестр программ.
      const { payloads } = await loadReferences({
        programs: api.listAll('education-programs'),
        specialties: api.listAll('specialties'),
        groups: api.listAll('groups', { per_page: 200 }),
      })

      programs.value = extractRows(payloads.programs)
      specialties.value = extractRows(payloads.specialties)
      groups.value = extractRows(payloads.groups)
      pagination.value = extractMeta(payloads.programs)

      if (selectedId.value && !selectedProgram.value) {
        selectedId.value = null
      }
    } catch (err) {
      error.value = err.message || 'Не удалось загрузить образовательные программы'
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
        ? await api.update('education-programs', id, data)
        : await api.create('education-programs', data)

      await load()

      const savedId = response?.data?.id || id
      if (savedId) {
        selectedId.value = savedId
      }

      return response?.data || null
    } catch (err) {
      error.value = err.message || 'Не удалось сохранить образовательную программу'
      throw err
    } finally {
      saving.value = false
    }
  }

  async function remove(program) {
    if (!program?.id) {
      return
    }

    loading.value = true
    error.value = ''

    try {
      await api.delete('education-programs', program.id)
      if (Number(selectedId.value) === Number(program.id)) {
        selectedId.value = null
      }
      await load()
    } catch (err) {
      error.value = err.message || 'Не удалось удалить образовательную программу'
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
      const payload = await api.upload('/education-programs/import', formData)

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
      const blob = await api.download('/education-programs/export')
      const url = window.URL.createObjectURL(blob)
      const link = document.createElement('a')

      link.href = url
      link.download = 'education-programs.csv'
      link.click()
      window.URL.revokeObjectURL(url)
    } catch (err) {
      error.value = err.message || 'Не удалось экспортировать CSV'
      throw err
    }
  }

  function setFilters(nextFilters) {
    filters.value = { ...filters.value, ...nextFilters }
  }

  function resetFilters() {
    filters.value = { ...initialFilters }
  }

  function select(program) {
    selectedId.value = program?.id || null
  }

  function selectById(id) {
    selectedId.value = id || null
  }

  return {
    programs,
    filteredPrograms,
    specialties,
    groups,
    selectedProgramGroups,
    specialtyOptions,
    yearOptions,
    studyFormOptions,
    filters,
    pagination,
    selectedId,
    selectedProgram,
    loading,
    saving,
    error,
    importSummary,
    load,
    save,
    remove,
    importCsv,
    exportCsv,
    setFilters,
    resetFilters,
    select,
    selectById,
  }
})
