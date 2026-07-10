import { computed, reactive, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'

function extractRows(payload) {
  return Array.isArray(payload?.data) ? payload.data : []
}

function defaultSummary() {
  return { total: 0, with_events: 0, with_schedule: 0, inside_now: 0, late: 0, absent: 0, on_time: 0 }
}

function todayIsoDate() {
  return new Date().toISOString().slice(0, 10)
}

function teacherName(teacher) {
  return [teacher?.last_name, teacher?.first_name, teacher?.middle_name].filter(Boolean).join(' ')
}

export function formatAttendanceDateTime(value) {
  if (!value) return '—'
  return new Intl.DateTimeFormat('ru-RU', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }).format(new Date(value))
}

export function firstLessonLabel(lesson) {
  if (!lesson) return '—'
  return [lesson.starts_at, lesson.subject, lesson.group].filter(Boolean).join(' · ')
}

export function minutesLabel(value) {
  const minutes = Number(value || 0)
  if (!minutes) return '—'
  const hours = Math.floor(minutes / 60)
  const rest = minutes % 60
  return hours ? `${hours} ч ${rest} мин` : `${rest} мин`
}

export const ATTENDANCE_STATUS_OPTIONS = [
  { label: 'Все', value: '' },
  { label: 'Вовремя / пришел заранее', value: 'on_time' },
  { label: 'Опоздал', value: 'late' },
  { label: 'Не пришел / не вошел', value: 'absent' },
  { label: 'Нет занятий', value: 'no_schedule' },
]

export const useAttendanceAnalysisStore = defineStore('attendanceAnalysis', () => {
  const mode = ref('teachers')
  const teachers = ref([])
  const students = ref([])
  const groups = ref([])
  const teacherOptionsSource = ref([])
  const teacherSummary = ref(defaultSummary())
  const studentSummary = ref(defaultSummary())
  const date = ref(todayIsoDate())
  const dateTo = ref(todayIsoDate())
  const selectedId = ref(null)
  const loading = ref(false)
  const loadingOptions = ref(false)
  const error = ref('')
  const filters = reactive({ status: '', group_id: '', teacher_id: '', date_from: todayIsoDate(), date_to: todayIsoDate() })

  const rows = computed(() => (mode.value === 'teachers' ? teachers.value : students.value))
  const summary = computed(() => (mode.value === 'teachers' ? teacherSummary.value : studentSummary.value))
  const selectedRow = computed(() => rows.value.find((row) => row.id === selectedId.value) || rows.value[0] || null)
  const groupOptions = computed(() => [
    { label: 'Все группы', value: '' },
    ...groups.value.map((group) => ({ label: group.name, value: group.id })),
  ])
  const teacherOptions = computed(() => [
    { label: 'Все преподаватели', value: '' },
    ...teacherOptionsSource.value.map((teacher) => ({ label: teacherName(teacher), value: teacher.id })),
  ])
  const queryParams = computed(() => Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== null && value !== undefined && value !== '')))

  async function loadOptions() {
    loadingOptions.value = true
    try {
      const [groupsPayload, teachersPayload] = await Promise.all([
        api.list('groups'),
        api.list('teachers'),
      ])
      groups.value = extractRows(groupsPayload)
      teacherOptionsSource.value = extractRows(teachersPayload)
    } finally {
      loadingOptions.value = false
    }
  }

  async function load() {
    loading.value = true
    error.value = ''
    try {
      const [teacherPayload, studentPayload] = await Promise.all([
        api.list('attendance/teachers/today', queryParams.value),
        api.list('attendance/students/today', queryParams.value),
      ])
      teachers.value = extractRows(teacherPayload)
      students.value = extractRows(studentPayload)
      teacherSummary.value = teacherPayload?.summary || defaultSummary()
      studentSummary.value = studentPayload?.summary || defaultSummary()
      date.value = teacherPayload?.date_from || studentPayload?.date_from || filters.date_from
      dateTo.value = teacherPayload?.date_to || studentPayload?.date_to || filters.date_to
      if (!rows.value.some((row) => row.id === selectedId.value)) {
        selectedId.value = rows.value[0]?.id || null
      }
    } catch (err) {
      error.value = err.message || 'Не удалось загрузить аналитику посещаемости'
    } finally {
      loading.value = false
    }
  }

  function setMode(value) {
    mode.value = value
    if (!rows.value.some((row) => row.id === selectedId.value)) {
      selectedId.value = rows.value[0]?.id || null
    }
  }

  function select(row) {
    selectedId.value = row?.id || null
  }

  function resetFilters() {
    filters.status = ''
    filters.group_id = ''
    filters.teacher_id = ''
    filters.date_from = todayIsoDate()
    filters.date_to = todayIsoDate()
  }

  function applyQuery(query = {}) {
    if (query.type === 'students' || query.type === 'teachers') {
      mode.value = query.type
    }
    filters.status = typeof query.status === 'string' ? query.status : ''
    filters.group_id = query.group ? Number(query.group) : (query.group_id ? Number(query.group_id) : '')
    filters.teacher_id = query.teacher ? Number(query.teacher) : (query.teacher_id ? Number(query.teacher_id) : '')
    filters.date_from = typeof query.date_from === 'string' ? query.date_from : todayIsoDate()
    filters.date_to = typeof query.date_to === 'string' ? query.date_to : filters.date_from
  }

  function toQuery() {
    return {
      type: mode.value,
      ...Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== '' && value !== null && value !== undefined)),
    }
  }

  return {
    mode,
    teachers,
    students,
    groups,
    teacherOptionsSource,
    teacherSummary,
    studentSummary,
    date,
    dateTo,
    selectedId,
    selectedRow,
    loading,
    loadingOptions,
    error,
    filters,
    rows,
    summary,
    groupOptions,
    teacherOptions,
    loadOptions,
    load,
    setMode,
    select,
    resetFilters,
    applyQuery,
    toQuery,
    formatAttendanceDateTime,
    firstLessonLabel,
    minutesLabel,
  }
})
