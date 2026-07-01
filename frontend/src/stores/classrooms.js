import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'

const initialFilters = {
  search: '',
  building: '',
  type: '',
}

function extractRows(payload) {
  return Array.isArray(payload?.data) ? payload.data : []
}

function extractMeta(payload) {
  return payload?.meta || null
}

function classroomLabel(classroom) {
  return [
    classroom?.number,
    classroom?.building ? `корп. ${classroom.building}` : '',
  ].filter(Boolean).join(' · ')
}

function cleanPayload(payload) {
  return {
    number: payload.number?.trim() || '',
    building: payload.building?.trim() || '',
    floor: payload.floor === '' || payload.floor === null ? null : Number(payload.floor),
    capacity: payload.capacity === '' || payload.capacity === null ? null : Number(payload.capacity),
    type: payload.type?.trim() || '',
    description: payload.description?.trim() || '',
  }
}

export const useClassroomsStore = defineStore('classrooms', () => {
  const classrooms = ref([])
  const scheduleLessons = ref([])
  const filters = ref({ ...initialFilters })
  const pagination = ref(null)
  const selectedId = ref(null)
  const loading = ref(false)
  const saving = ref(false)
  const error = ref('')
  const importSummary = ref(null)

  const selectedClassroom = computed(() => (
    classrooms.value.find((classroom) => Number(classroom.id) === Number(selectedId.value)) || null
  ))

  const buildingOptions = computed(() => {
    const buildings = new Set(classrooms.value.map((classroom) => classroom.building).filter(Boolean))

    return [...buildings].sort((a, b) => a.localeCompare(b, 'ru')).map((building) => ({
      label: building,
      value: building,
    }))
  })

  const typeOptions = computed(() => {
    const types = new Set(classrooms.value.map((classroom) => classroom.type).filter(Boolean))

    return [...types].sort((a, b) => a.localeCompare(b, 'ru')).map((type) => ({
      label: type,
      value: type,
    }))
  })

  const selectedClassroomLessons = computed(() => {
    const classroomId = Number(selectedId.value)

    if (!classroomId) {
      return []
    }

    return scheduleLessons.value.filter((lesson) => Number(lesson.classroom_id) === classroomId)
  })

  const filteredClassrooms = computed(() => {
    const search = filters.value.search.trim().toLowerCase()

    return classrooms.value.filter((classroom) => {
      const searchable = [
        classroomLabel(classroom),
        classroom.number,
        classroom.building,
        classroom.floor,
        classroom.capacity,
        classroom.type,
        classroom.description,
      ].filter(Boolean).join(' ').toLowerCase()
      const matchesSearch = !search || searchable.includes(search)
      const matchesBuilding = !filters.value.building || classroom.building === filters.value.building
      const matchesType = !filters.value.type || classroom.type === filters.value.type

      return matchesSearch && matchesBuilding && matchesType
    })
  })

  async function load() {
    loading.value = true
    error.value = ''

    try {
      const [classroomsPayload, lessonsPayload] = await Promise.all([
        api.list('classrooms'),
        api.list('schedule-lessons'),
      ])

      classrooms.value = extractRows(classroomsPayload)
      scheduleLessons.value = extractRows(lessonsPayload)
      pagination.value = extractMeta(classroomsPayload)

      if (selectedId.value && !selectedClassroom.value) {
        selectedId.value = null
      }
    } catch (err) {
      error.value = err.message || 'Не удалось загрузить аудитории'
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
        ? await api.update('classrooms', id, data)
        : await api.create('classrooms', data)

      await load()

      const savedId = response?.data?.id || id
      if (savedId) {
        selectedId.value = savedId
      }

      return response?.data || null
    } catch (err) {
      error.value = err.message || 'Не удалось сохранить аудиторию'
      throw err
    } finally {
      saving.value = false
    }
  }

  async function remove(classroom) {
    if (!classroom?.id) {
      return
    }

    loading.value = true
    error.value = ''

    try {
      await api.delete('classrooms', classroom.id)
      if (Number(selectedId.value) === Number(classroom.id)) {
        selectedId.value = null
      }
      await load()
    } catch (err) {
      error.value = err.message || 'Не удалось удалить аудиторию'
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
      const payload = await api.upload('/classrooms/import', formData)

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
      const blob = await api.download('/classrooms/export')
      const url = window.URL.createObjectURL(blob)
      const link = document.createElement('a')

      link.href = url
      link.download = 'classrooms.csv'
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

  function selectClassroom(classroom) {
    selectedId.value = classroom?.id || null
  }

  function selectClassroomById(id) {
    selectedId.value = id || null
  }

  return {
    classrooms,
    filteredClassrooms,
    scheduleLessons,
    filters,
    pagination,
    selectedId,
    selectedClassroom,
    selectedClassroomLessons,
    buildingOptions,
    typeOptions,
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
    selectClassroom,
    selectClassroomById,
  }
})
