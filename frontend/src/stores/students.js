import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'

const initialFilters = {
  search: '',
  group_id: '',
  status: '',
}

function extractRows(payload) {
  return Array.isArray(payload?.data) ? payload.data : []
}

function extractMeta(payload) {
  return payload?.meta || null
}

function cleanPayload(payload) {
  return {
    user_id: payload.user_id || null,
    group_id: payload.group_id ? Number(payload.group_id) : null,
    last_name: payload.last_name?.trim() || '',
    first_name: payload.first_name?.trim() || '',
    middle_name: payload.middle_name?.trim() || null,
    birth_date: payload.birth_date || null,
    phone: payload.phone?.trim() || null,
    email: payload.email?.trim() || null,
    status: payload.status || 'active',
    enrollment_date: payload.enrollment_date || null,
  }
}

function buildStudentQuery(filters) {
  const query = new URLSearchParams()

  Object.entries(filters).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') {
      query.set(key, value)
    }
  })

  return query.toString()
}

export const useStudentsStore = defineStore('students', () => {
  const students = ref([])
  const groups = ref([])
  const filters = ref({ ...initialFilters })
  const pagination = ref(null)
  const selectedId = ref(null)
  const loading = ref(false)
  const detailsLoading = ref(false)
  const saving = ref(false)
  const error = ref('')
  const importSummary = ref(null)
  const selectedAttendance = ref([])
  const selectedGrades = ref([])

  const selectedStudent = computed(() => (
    students.value.find((student) => Number(student.id) === Number(selectedId.value)) || null
  ))

  const groupOptions = computed(() => groups.value.map((group) => ({
    label: group.name,
    value: group.id,
    group,
  })))

  const attendanceSummary = computed(() => {
    const summary = {
      total: selectedAttendance.value.length,
      present: 0,
      absent: 0,
      late: 0,
      excused: 0,
    }

    selectedAttendance.value.forEach((entry) => {
      if (summary[entry.status] !== undefined) {
        summary[entry.status] += 1
      }
    })

    return summary
  })

  const gradeSummary = computed(() => {
    const numericGrades = selectedGrades.value
      .map((entry) => Number(entry.grade))
      .filter((grade) => Number.isFinite(grade))

    const average = numericGrades.length
      ? numericGrades.reduce((sum, grade) => sum + grade, 0) / numericGrades.length
      : null

    return {
      total: selectedGrades.value.length,
      average,
      latest: selectedGrades.value.slice(0, 5),
    }
  })

  async function load() {
    loading.value = true
    error.value = ''

    try {
      const [studentsPayload, groupsPayload] = await Promise.all([
        api.list('students', filters.value),
        api.list('groups'),
      ])

      students.value = extractRows(studentsPayload)
      groups.value = extractRows(groupsPayload)
      pagination.value = extractMeta(studentsPayload)

      if (selectedId.value && !selectedStudent.value) {
        selectedId.value = null
        selectedAttendance.value = []
        selectedGrades.value = []
      }
    } catch (err) {
      error.value = err.message || 'Не удалось загрузить студентов'
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
        ? await api.update('students', id, data)
        : await api.create('students', data)

      await load()

      const savedId = response?.data?.id || id
      if (savedId) {
        selectedId.value = savedId
        await loadStudentDetails(savedId)
      }

      return response?.data || null
    } catch (err) {
      error.value = err.message || 'Не удалось сохранить студента'
      throw err
    } finally {
      saving.value = false
    }
  }

  async function remove(student) {
    if (!student?.id) {
      return
    }

    loading.value = true
    error.value = ''

    try {
      await api.delete('students', student.id)
      if (Number(selectedId.value) === Number(student.id)) {
        selectedId.value = null
        selectedAttendance.value = []
        selectedGrades.value = []
      }
      await load()
    } catch (err) {
      error.value = err.message || 'Не удалось удалить студента'
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
      const payload = await api.upload('/students/import', formData)

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
      const query = buildStudentQuery(filters.value)
      const blob = await api.download(`/students/export${query ? `?${query}` : ''}`)
      const url = window.URL.createObjectURL(blob)
      const link = document.createElement('a')

      link.href = url
      link.download = 'students.csv'
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

  async function loadStudentDetails(studentId) {
    if (!studentId) {
      selectedAttendance.value = []
      selectedGrades.value = []
      return
    }

    detailsLoading.value = true

    try {
      const [attendancePayload, gradesPayload] = await Promise.all([
        api.list('attendance', { student_id: studentId }),
        api.list('grades', { student_id: studentId }),
      ])

      if (Number(selectedId.value) === Number(studentId)) {
        selectedAttendance.value = extractRows(attendancePayload)
        selectedGrades.value = extractRows(gradesPayload)
      }
    } catch (err) {
      error.value = err.message || 'Не удалось загрузить карточку студента'
    } finally {
      detailsLoading.value = false
    }
  }

  function selectStudent(student) {
    selectedId.value = student?.id || null
    loadStudentDetails(selectedId.value)
  }

  function selectStudentById(id) {
    selectedId.value = id || null
    loadStudentDetails(selectedId.value)
  }

  return {
    students,
    groups,
    filters,
    pagination,
    selectedId,
    selectedStudent,
    groupOptions,
    loading,
    detailsLoading,
    saving,
    error,
    importSummary,
    selectedAttendance,
    selectedGrades,
    attendanceSummary,
    gradeSummary,
    load,
    save,
    remove,
    importCsv,
    exportCsv,
    loadStudentDetails,
    setFilters,
    resetFilters,
    selectStudent,
    selectStudentById,
  }
})
