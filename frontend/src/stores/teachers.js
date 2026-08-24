import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'
import { loadReferences } from '../services/referenceLoader'

const initialFilters = {
  search: '',
  status: '',
  department: '',
}

function extractRows(payload) {
  return Array.isArray(payload?.data) ? payload.data : []
}

function extractMeta(payload) {
  return payload?.meta || null
}

function fullName(teacher) {
  return [teacher?.last_name, teacher?.first_name, teacher?.middle_name].filter(Boolean).join(' ')
}

function cleanPayload(payload) {
  return {
    last_name: payload.last_name?.trim() || '',
    first_name: payload.first_name?.trim() || '',
    middle_name: payload.middle_name?.trim() || '',
    phone: payload.phone?.trim() || '',
    email: payload.email?.trim() || '',
    position: payload.position?.trim() || '',
    department: payload.department?.trim() || '',
    is_active: Boolean(payload.is_active),
  }
}

export const useTeachersStore = defineStore('teachers', () => {
  const teachers = ref([])
  const subjects = ref([])
  const scheduleLessons = ref([])
  const filters = ref({ ...initialFilters })
  const pagination = ref(null)
  const selectedId = ref(null)
  const loading = ref(false)
  const saving = ref(false)
  const error = ref('')
  const importSummary = ref(null)

  const selectedTeacher = computed(() => (
    teachers.value.find((teacher) => Number(teacher.id) === Number(selectedId.value)) || null
  ))

  const departmentOptions = computed(() => {
    const departments = new Set(teachers.value.map((teacher) => teacher.department).filter(Boolean))

    return [...departments].sort((a, b) => a.localeCompare(b, 'ru')).map((department) => ({
      label: department,
      value: department,
    }))
  })

  const statusOptions = [
    { label: 'Активные', value: 'active' },
    { label: 'Неактивные', value: 'inactive' },
  ]

  const selectedTeacherSubjects = computed(() => {
    const teacherId = Number(selectedId.value)

    if (!teacherId) {
      return []
    }

    return subjects.value.filter((subject) => {
      if (Array.isArray(subject.teachers)) {
        return subject.teachers.some((teacher) => Number(teacher.id) === teacherId)
      }

      return Number(subject.teacher_id) === teacherId
    })
  })

  const selectedTeacherLessons = computed(() => {
    const teacherId = Number(selectedId.value)

    if (!teacherId) {
      return []
    }

    return scheduleLessons.value.filter((lesson) => Number(lesson.teacher_id) === teacherId)
  })

  const filteredTeachers = computed(() => {
    const search = filters.value.search.trim().toLowerCase()

    return teachers.value.filter((teacher) => {
      const searchable = [
        fullName(teacher),
        teacher.phone,
        teacher.email,
        teacher.position,
        teacher.department,
      ].filter(Boolean).join(' ').toLowerCase()
      const matchesSearch = !search || searchable.includes(search)
      const matchesStatus = !filters.value.status
        || (filters.value.status === 'active' && teacher.is_active)
        || (filters.value.status === 'inactive' && !teacher.is_active)
      const matchesDepartment = !filters.value.department || teacher.department === filters.value.department

      return matchesSearch && matchesStatus && matchesDepartment
    })
  })

  async function load() {
    loading.value = true
    error.value = ''

    try {
      // Преподаватели — сам экран, дисциплины и занятия — его справочники:
      // кадровик без прав на них обязан увидеть список преподавателей.
      const { payloads } = await loadReferences({
        teachers: api.list('teachers'),
        subjects: api.list('subjects'),
        lessons: api.list('schedule-lessons'),
      })

      teachers.value = extractRows(payloads.teachers)
      subjects.value = extractRows(payloads.subjects)
      scheduleLessons.value = extractRows(payloads.lessons)
      pagination.value = extractMeta(payloads.teachers)

      if (selectedId.value && !selectedTeacher.value) {
        selectedId.value = null
      }
    } catch (err) {
      error.value = err.message || 'Не удалось загрузить преподавателей'
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
        ? await api.update('teachers', id, data)
        : await api.create('teachers', data)

      await load()

      const savedId = response?.data?.id || id
      if (savedId) {
        selectedId.value = savedId
      }

      return response?.data || null
    } catch (err) {
      error.value = err.message || 'Не удалось сохранить преподавателя'
      throw err
    } finally {
      saving.value = false
    }
  }

  async function remove(teacher) {
    if (!teacher?.id) {
      return
    }

    loading.value = true
    error.value = ''

    try {
      await api.delete('teachers', teacher.id)
      if (Number(selectedId.value) === Number(teacher.id)) {
        selectedId.value = null
      }
      await load()
    } catch (err) {
      error.value = err.message || 'Не удалось удалить преподавателя'
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
      const payload = await api.upload('/teachers/import', formData)

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
      const blob = await api.download('/teachers/export')
      const url = window.URL.createObjectURL(blob)
      const link = document.createElement('a')

      link.href = url
      link.download = 'teachers.csv'
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

  function selectTeacher(teacher) {
    selectedId.value = teacher?.id || null
  }

  function selectTeacherById(id) {
    selectedId.value = id || null
  }

  /**
   * Выдача учётных записей преподавателям разом.
   *
   * Репетиция первого сентября насчитала 60 одинаковых шагов — по одному сбросу
   * пароля на преподавателя. Предпросмотр считает и ничего не пишет; применение
   * возвращает логины и пароли **один раз**: второго раза не будет, в базе лежит
   * хеш.
   *
   * Выбор идёт фильтром, а не списком отмеченных строк: учётной записи нет у
   * всех сразу, а не у выбранных троих, и отмечать сорок человек мышью — та же
   * ручная работа, от которой уходим.
   */
  async function previewAccounts() {
    saving.value = true
    error.value = ''
    try {
      const payload = await api.create('teachers/bulk/preview', { action: 'issue_accounts', filter: {} })
      return payload?.data || null
    } catch (err) {
      error.value = err.message || 'Не удалось подготовить выдачу учётных записей'
      throw err
    } finally {
      saving.value = false
    }
  }

  async function applyAccounts() {
    saving.value = true
    error.value = ''
    try {
      const payload = await api.create('teachers/bulk/apply', { action: 'issue_accounts', filter: {} })
      await load()
      return payload?.data || null
    } catch (err) {
      error.value = err.message || 'Не удалось выдать учётные записи'
      throw err
    } finally {
      saving.value = false
    }
  }

  return {
    teachers,
    previewAccounts,
    applyAccounts,
    filteredTeachers,
    subjects,
    scheduleLessons,
    filters,
    pagination,
    selectedId,
    selectedTeacher,
    selectedTeacherSubjects,
    selectedTeacherLessons,
    departmentOptions,
    statusOptions,
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
    selectTeacher,
    selectTeacherById,
  }
})
