import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'
import { loadReferences } from '../services/referenceLoader'

const initialFilters = {
  search: '',
  course: '',
  education_program_id: '',
}

function extractRows(payload) {
  return Array.isArray(payload?.data) ? payload.data : []
}

function extractMeta(payload) {
  return payload?.meta || null
}

function teacherName(teacher) {
  return [teacher?.last_name, teacher?.first_name, teacher?.middle_name].filter(Boolean).join(' ')
}

function programLabel(program) {
  return [
    program?.name,
    program?.specialty?.code,
    program?.year_start,
    program?.study_form,
  ].filter(Boolean).join(' · ')
}

function cleanPayload(payload) {
  return {
    name: payload.name?.trim() || '',
    specialty: payload.specialty?.trim() || '',
    education_program_id: payload.education_program_id ? Number(payload.education_program_id) : null,
    year_start: payload.year_start ? Number(payload.year_start) : null,
    curator_id: payload.curator_id ? Number(payload.curator_id) : null,
  }
}

export const useGroupsStore = defineStore('groups', () => {
  const groups = ref([])
  const teachers = ref([])
  const educationPrograms = ref([])
  const filters = ref({ ...initialFilters })
  const pagination = ref(null)
  const selectedId = ref(null)
  const loading = ref(false)
  const saving = ref(false)
  const error = ref('')
  const importSummary = ref(null)

  const selectedGroup = computed(() => (
    groups.value.find((group) => Number(group.id) === Number(selectedId.value)) || null
  ))

  const teacherOptions = computed(() => teachers.value.map((teacher) => ({
    label: teacherName(teacher),
    value: teacher.id,
    teacher,
  })))

  const educationProgramOptions = computed(() => educationPrograms.value.map((program) => ({
    label: programLabel(program),
    value: program.id,
    program,
  })))

  const courseOptions = computed(() => {
    const courses = new Set(groups.value.map((group) => group.course).filter(Boolean))
    return [...courses].sort((a, b) => Number(a) - Number(b)).map((course) => ({
      label: `${course} курс`,
      value: course,
    }))
  })

  const filteredGroups = computed(() => {
    const search = filters.value.search.trim().toLowerCase()

    return groups.value.filter((group) => {
      const programText = programLabel(group.education_program).toLowerCase()
      const curatorText = teacherName(group.curator).toLowerCase()
      const matchesSearch = !search
        || group.name?.toLowerCase().includes(search)
        || group.specialty?.toLowerCase().includes(search)
        || programText.includes(search)
        || curatorText.includes(search)
      const matchesCourse = !filters.value.course || Number(group.course) === Number(filters.value.course)
      const matchesProgram = !filters.value.education_program_id
        || Number(group.education_program_id) === Number(filters.value.education_program_id)

      return matchesSearch && matchesCourse && matchesProgram
    })
  })

  async function load() {
    loading.value = true
    error.value = ''

    try {
      // Группы — сам экран, справочники — его выпадающие списки: отсутствие права
      // на справочник обязано оставить список пустым, а не закрыть экран.
      const { payloads } = await loadReferences({
        groups: api.listAll('groups', { per_page: 200 }),
        programs: api.listAll('education-programs'),
        teachers: api.listAll('teachers', { active_only: 1 }),
      })

      groups.value = extractRows(payloads.groups)
      educationPrograms.value = extractRows(payloads.programs)
      teachers.value = extractRows(payloads.teachers)
      pagination.value = extractMeta(payloads.groups)

      if (selectedId.value && !selectedGroup.value) {
        selectedId.value = null
      }
    } catch (err) {
      error.value = err.message || 'Не удалось загрузить группы'
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
        ? await api.update('groups', id, data)
        : await api.create('groups', data)

      await load()

      const savedId = response?.data?.id || id
      if (savedId) {
        selectedId.value = savedId
      }

      return response?.data || null
    } catch (err) {
      error.value = err.message || 'Не удалось сохранить группу'
      throw err
    } finally {
      saving.value = false
    }
  }

  async function remove(group) {
    if (!group?.id) {
      return
    }

    loading.value = true
    error.value = ''

    try {
      await api.delete('groups', group.id)
      if (Number(selectedId.value) === Number(group.id)) {
        selectedId.value = null
      }
      await load()
    } catch (err) {
      error.value = err.message || 'Не удалось удалить группу'
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
      const payload = await api.upload('/groups/import', formData)

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
      const blob = await api.download('/groups/export')
      const url = window.URL.createObjectURL(blob)
      const link = document.createElement('a')

      link.href = url
      link.download = 'groups.csv'
      link.click()
      window.URL.revokeObjectURL(url)
    } catch (err) {
      error.value = err.message || 'Не удалось экспортировать CSV'
      throw err
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

  function selectGroup(group) {
    selectedId.value = group?.id || null
  }

  function selectGroupById(id) {
    selectedId.value = id || null
  }

  return {
    groups,
    filteredGroups,
    teachers,
    educationPrograms,
    filters,
    pagination,
    selectedId,
    selectedGroup,
    teacherOptions,
    educationProgramOptions,
    courseOptions,
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
    selectGroup,
    selectGroupById,
  }
})
