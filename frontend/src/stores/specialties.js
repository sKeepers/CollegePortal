import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'
import { loadReferences } from '../services/referenceLoader'

const initialFilters = {
  search: '',
  education_level: '',
}

function extractRows(payload) {
  return Array.isArray(payload?.data) ? payload.data : []
}

function extractMeta(payload) {
  return payload?.meta || null
}

export function specialtyTitle(specialty) {
  return [specialty?.code, specialty?.name].filter(Boolean).join(' · ')
}

/**
 * Код необязателен: при пустом значении его выдаёт AutoCodeService на бэкенде.
 * Поэтому пустую строку отправляем как есть, а не подставляем что-то своё.
 */
function cleanPayload(payload) {
  return {
    code: payload.code?.trim() || '',
    name: payload.name?.trim() || '',
    education_level: payload.education_level?.trim() || '',
    qualification: payload.qualification?.trim() || '',
    normative_study_years:
      payload.normative_study_years === '' || payload.normative_study_years === null
        ? null
        : Number(payload.normative_study_years),
    description: payload.description?.trim() || '',
  }
}

export const useSpecialtiesStore = defineStore('specialties', () => {
  const specialties = ref([])
  const programs = ref([])
  const filters = ref({ ...initialFilters })
  const pagination = ref(null)
  const selectedId = ref(null)
  const loading = ref(false)
  const saving = ref(false)
  const error = ref('')
  const importSummary = ref(null)

  const selectedSpecialty = computed(() => (
    specialties.value.find((specialty) => Number(specialty.id) === Number(selectedId.value)) || null
  ))

  const educationLevelOptions = computed(() => {
    const levels = new Set(specialties.value.map((specialty) => specialty.education_level).filter(Boolean))

    return [...levels].sort((a, b) => a.localeCompare(b, 'ru')).map((level) => ({ label: level, value: level }))
  })

  const selectedSpecialtyPrograms = computed(() => {
    const specialtyId = Number(selectedId.value)

    if (!specialtyId) {
      return []
    }

    return programs.value.filter((program) => Number(program.specialty_id) === specialtyId)
  })

  const filteredSpecialties = computed(() => {
    const search = filters.value.search.trim().toLowerCase()

    return specialties.value.filter((specialty) => {
      const searchable = [
        specialty.code,
        specialty.name,
        specialty.education_level,
        specialty.qualification,
        specialty.description,
      ].filter(Boolean).join(' ').toLowerCase()
      const matchesSearch = !search || searchable.includes(search)
      const matchesLevel = !filters.value.education_level || specialty.education_level === filters.value.education_level

      return matchesSearch && matchesLevel
    })
  })

  async function load() {
    loading.value = true
    error.value = ''

    try {
      // Программы — справочник карточки: показывают, что на специальности висит.
      // Отказ по ним не должен закрывать сам реестр специальностей.
      const { payloads } = await loadReferences({
        specialties: api.listAll('specialties'),
        programs: api.listAll('education-programs'),
      })

      specialties.value = extractRows(payloads.specialties)
      programs.value = extractRows(payloads.programs)
      pagination.value = extractMeta(payloads.specialties)

      if (selectedId.value && !selectedSpecialty.value) {
        selectedId.value = null
      }
    } catch (err) {
      error.value = err.message || 'Не удалось загрузить специальности'
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
        ? await api.update('specialties', id, data)
        : await api.create('specialties', data)

      await load()

      const savedId = response?.data?.id || id
      if (savedId) {
        selectedId.value = savedId
      }

      return response?.data || null
    } catch (err) {
      error.value = err.message || 'Не удалось сохранить специальность'
      throw err
    } finally {
      saving.value = false
    }
  }

  async function remove(specialty) {
    if (!specialty?.id) {
      return
    }

    loading.value = true
    error.value = ''

    try {
      await api.delete('specialties', specialty.id)
      if (Number(selectedId.value) === Number(specialty.id)) {
        selectedId.value = null
      }
      await load()
    } catch (err) {
      error.value = err.message || 'Не удалось удалить специальность'
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
      const payload = await api.upload('/specialties/import', formData)

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
      const blob = await api.download('/specialties/export')
      const url = window.URL.createObjectURL(blob)
      const link = document.createElement('a')

      link.href = url
      link.download = 'specialties.csv'
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

  function select(specialty) {
    selectedId.value = specialty?.id || null
  }

  function selectById(id) {
    selectedId.value = id || null
  }

  return {
    specialties,
    filteredSpecialties,
    programs,
    selectedSpecialtyPrograms,
    educationLevelOptions,
    filters,
    pagination,
    selectedId,
    selectedSpecialty,
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
