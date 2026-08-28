import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'

const initialFilters = {
  search: '',
  department: '',
  teacher_id: '',
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

function cleanPayload(payload) {
  return {
    name: payload.name?.trim() || '',
    code: payload.code?.trim() || '',
    department: payload.department?.trim() || '',
    description: payload.description?.trim() || '',
    teacher_ids: Array.isArray(payload.teacher_ids) ? payload.teacher_ids.filter(Boolean) : [],
  }
}

export const useSubjectsStore = defineStore('subjects', () => {
  const subjects = ref([])
  const teachers = ref([])
  const scheduleLessons = ref([])
  const filters = ref({ ...initialFilters })
  const pagination = ref(null)
  const selectedId = ref(null)
  const loading = ref(false)
  const saving = ref(false)
  const error = ref('')
  const importSummary = ref(null)

  const selectedSubject = computed(() => (
    subjects.value.find((subject) => Number(subject.id) === Number(selectedId.value)) || null
  ))

  const departmentOptions = computed(() => {
    const departments = new Set(subjects.value.map((subject) => subject.department).filter(Boolean))

    return [...departments].sort((a, b) => a.localeCompare(b, 'ru')).map((department) => ({
      label: department,
      value: department,
    }))
  })

  const teacherOptions = computed(() => teachers.value.map((teacher) => ({
    label: teacherName(teacher) || `Преподаватель #${teacher.id}`,
    value: teacher.id,
  })))

  const selectedSubjectTeachers = computed(() => {
    const subject = selectedSubject.value

    if (!subject) {
      return []
    }

    if (Array.isArray(subject.teachers)) {
      return subject.teachers
    }

    return []
  })

  const selectedSubjectLessons = computed(() => {
    const subjectId = Number(selectedId.value)

    if (!subjectId) {
      return []
    }

    return scheduleLessons.value.filter((lesson) => Number(lesson.subject_id) === subjectId)
  })

  const filteredSubjects = computed(() => {
    const search = filters.value.search.trim().toLowerCase()

    return subjects.value.filter((subject) => {
      const searchable = [
        subject.name,
        subject.code,
        subject.department,
        subject.description,
        ...(Array.isArray(subject.teachers) ? subject.teachers.map(teacherName) : []),
      ].filter(Boolean).join(' ').toLowerCase()
      const matchesSearch = !search || searchable.includes(search)
      const matchesDepartment = !filters.value.department || subject.department === filters.value.department
      const matchesTeacher = !filters.value.teacher_id || (
        Array.isArray(subject.teachers)
          && subject.teachers.some((teacher) => Number(teacher.id) === Number(filters.value.teacher_id))
      )

      return matchesSearch && matchesDepartment && matchesTeacher
    })
  })

  async function load() {
    loading.value = true
    error.value = ''

    try {
      const [subjectsPayload, teachersPayload, lessonsPayload] = await Promise.all([
        api.list('subjects'),
        api.list('teachers', { per_page: 500 }),
        api.list('schedule-lessons'),
      ])

      subjects.value = extractRows(subjectsPayload)
      teachers.value = extractRows(teachersPayload)
      scheduleLessons.value = extractRows(lessonsPayload)
      pagination.value = extractMeta(subjectsPayload)

      if (selectedId.value && !selectedSubject.value) {
        selectedId.value = null
      }
    } catch (err) {
      error.value = err.message || 'Не удалось загрузить дисциплины'
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
        ? await api.update('subjects', id, data)
        : await api.create('subjects', data)

      await load()

      const savedId = response?.data?.id || id
      if (savedId) {
        selectedId.value = savedId
      }

      return response?.data || null
    } catch (err) {
      error.value = err.message || 'Не удалось сохранить дисциплину'
      throw err
    } finally {
      saving.value = false
    }
  }

  async function remove(subject) {
    if (!subject?.id) {
      return
    }

    loading.value = true
    error.value = ''

    try {
      await api.delete('subjects', subject.id)
      if (Number(selectedId.value) === Number(subject.id)) {
        selectedId.value = null
      }
      await load()
    } catch (err) {
      error.value = err.message || 'Не удалось удалить дисциплину'
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
      const payload = await api.upload('/subjects/import', formData)

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
      const blob = await api.download('/subjects/export')
      const url = window.URL.createObjectURL(blob)
      const link = document.createElement('a')

      link.href = url
      link.download = 'subjects.csv'
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

  function selectSubject(subject) {
    selectedId.value = subject?.id || null
  }

  function selectSubjectById(id) {
    selectedId.value = id || null
  }

  return {
    subjects,
    filteredSubjects,
    teachers,
    scheduleLessons,
    filters,
    pagination,
    selectedId,
    selectedSubject,
    selectedSubjectTeachers,
    selectedSubjectLessons,
    departmentOptions,
    teacherOptions,
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
    selectSubject,
    selectSubjectById,
  }
})
