import { computed, reactive, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'
import { loadReferences } from '../services/referenceLoader'

function extractRows(payload) {
  return Array.isArray(payload?.data) ? payload.data : []
}

function defaultSummary() {
  return { total: 0, with_events: 0, with_schedule: 0, inside_now: 0, late: 0, absent: 0, on_time: 0 }
}

function defaultHistorySummary() {
  return { total: 0, present_days: 0, absent_days: 0, late_count: 0, early_leave_count: 0, minutes_inside: 0, open_sessions: 0 }
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

export function formatAttendanceDate(value) {
  if (!value) return '—'
  return new Intl.DateTimeFormat('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' }).format(new Date(value))
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
  { label: 'Ранний уход', value: 'early_leave' },
  { label: 'Незакрытый вход', value: 'open_session' },
  { label: 'Нет занятий', value: 'no_schedule' },
]

export const ATTENDANCE_REPORT_MODE_OPTIONS = [
  { label: 'Сегодня', value: 'today' },
  { label: 'Период', value: 'period' },
  { label: 'По человеку', value: 'person' },
]

export const useAttendanceAnalysisStore = defineStore('attendanceAnalysis', () => {
  const mode = ref('teachers')
  const reportMode = ref('today')
  const teachers = ref([])
  const students = ref([])
  const historyRows = ref([])
  const historySummary = ref(defaultHistorySummary())
  const personDays = ref([])
  const groups = ref([])
  const teacherOptionsSource = ref([])
  const teacherSummary = ref(defaultSummary())
  const studentSummary = ref(defaultSummary())
  const date = ref(todayIsoDate())
  const dateTo = ref(todayIsoDate())
  const selectedId = ref(null)
  const selectedTab = ref('summary')
  const loading = ref(false)
  const loadingOptions = ref(false)
  const exporting = ref(false)
  const error = ref('')
  // marked_without_entry — признак рядом со статусом, а не вместо него: студент
  // может быть и «не вошёл», и отмеченным преподавателем на занятии.
  const filters = reactive({ status: '', marked_without_entry: '', group_id: '', teacher_id: '', person_id: '', date_from: todayIsoDate(), date_to: todayIsoDate() })

  const todayRows = computed(() => (mode.value === 'teachers' ? teachers.value : students.value))
  const rows = computed(() => (reportMode.value === 'today' ? todayRows.value : historyRows.value))
  const summary = computed(() => (reportMode.value === 'today' ? (mode.value === 'teachers' ? teacherSummary.value : studentSummary.value) : historySummary.value))
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
  const historyParams = computed(() => ({
    ...queryParams.value,
    type: mode.value === 'teachers' ? 'teacher' : 'student',
  }))

  async function loadOptions() {
    loadingOptions.value = true
    try {
      // Здесь оба запроса — справочники фильтров. Куратор и охрана прав на них
      // не имеют, и раньше отказ ронял загрузку экрана целиком.
      const { payloads } = await loadReferences({
        groups: api.list('groups', { per_page: 200 }),
        teachers: api.list('teachers'),
      })
      groups.value = extractRows(payloads.groups)
      teacherOptionsSource.value = extractRows(payloads.teachers)
    } finally {
      loadingOptions.value = false
    }
  }

  async function loadToday() {
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
  }

  async function loadHistory() {
    const payload = await api.list('attendance/history', historyParams.value)
    historyRows.value = extractRows(payload)
    historySummary.value = payload?.summary || defaultHistorySummary()
    date.value = payload?.date_from || filters.date_from
    dateTo.value = payload?.date_to || filters.date_to
  }

  async function loadPersonDays(row = selectedRow.value) {
    personDays.value = []
    if (!row?.entity_type || !row?.entity_id) return
    const payload = await api.list(`attendance/person/${row.entity_type}/${row.entity_id}/days`, {
      date_from: filters.date_from,
      date_to: filters.date_to,
    })
    personDays.value = extractRows(payload)
  }

  async function load() {
    loading.value = true
    error.value = ''
    try {
      if (reportMode.value === 'today') {
        await loadToday()
      } else {
        await loadHistory()
      }
      if (!rows.value.some((row) => row.id === selectedId.value)) {
        selectedId.value = rows.value[0]?.id || null
      }
      if (reportMode.value !== 'today') {
        await loadPersonDays()
      }
    } catch (err) {
      error.value = err.message || 'Не удалось загрузить аналитику посещаемости'
    } finally {
      loading.value = false
    }
  }

  function setMode(value) {
    mode.value = value
    filters.person_id = ''
    if (!rows.value.some((row) => row.id === selectedId.value)) {
      selectedId.value = rows.value[0]?.id || null
    }
  }

  function setReportMode(value) {
    reportMode.value = value
    selectedTab.value = value === 'today' ? 'summary' : selectedTab.value
  }

  async function select(row) {
    selectedId.value = row?.id || null
    if (reportMode.value !== 'today') {
      await loadPersonDays(row)
    }
  }

  function resetFilters() {
    filters.status = ''
    filters.marked_without_entry = ''
    filters.group_id = ''
    filters.teacher_id = ''
    filters.person_id = ''
    filters.date_from = todayIsoDate()
    filters.date_to = todayIsoDate()
  }

  function applyQuery(query = {}) {
    if (query.type === 'students' || query.type === 'teachers') {
      mode.value = query.type
    }
    if (['today', 'period', 'person'].includes(query.mode)) {
      reportMode.value = query.mode
    }
    filters.status = typeof query.status === 'string' ? query.status : ''
    filters.marked_without_entry = query.marked_without_entry ? 1 : ''
    filters.group_id = query.group ? Number(query.group) : (query.group_id ? Number(query.group_id) : '')
    filters.teacher_id = query.teacher ? Number(query.teacher) : (query.teacher_id ? Number(query.teacher_id) : '')
    filters.person_id = query.person_id ? Number(query.person_id) : ''
    filters.date_from = typeof query.date_from === 'string' ? query.date_from : todayIsoDate()
    filters.date_to = typeof query.date_to === 'string' ? query.date_to : filters.date_from
  }

  function toQuery() {
    return {
      type: mode.value,
      mode: reportMode.value,
      ...Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== '' && value !== null && value !== undefined)),
    }
  }

  async function exportHistoryCsv() {
    exporting.value = true
    try {
      const params = new URLSearchParams()
      Object.entries({ ...historyParams.value, export: 'csv' }).forEach(([key, value]) => {
        if (value !== undefined && value !== null && value !== '') params.set(key, value)
      })
      const blob = await api.download(`/attendance/history?${params.toString()}`)
      const url = URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.download = `attendance-history-${filters.date_from}-${filters.date_to}.csv`
      link.click()
      URL.revokeObjectURL(url)
    } finally {
      exporting.value = false
    }
  }

  return {
    mode,
    reportMode,
    teachers,
    students,
    historyRows,
    historySummary,
    personDays,
    groups,
    teacherOptionsSource,
    teacherSummary,
    studentSummary,
    date,
    dateTo,
    selectedId,
    selectedRow,
    selectedTab,
    loading,
    loadingOptions,
    exporting,
    error,
    filters,
    rows,
    summary,
    groupOptions,
    teacherOptions,
    loadOptions,
    load,
    loadHistory,
    loadPersonDays,
    exportHistoryCsv,
    setMode,
    setReportMode,
    select,
    resetFilters,
    applyQuery,
    toQuery,
    formatAttendanceDate,
    formatAttendanceDateTime,
    firstLessonLabel,
    minutesLabel,
  }
})
