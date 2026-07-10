import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'

function extractRows(payload) {
  return Array.isArray(payload?.data) ? payload.data : []
}

function defaultSummary() {
  return { total: 0, with_events: 0, with_schedule: 0, inside_now: 0 }
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

export const useAttendanceAnalysisStore = defineStore('attendanceAnalysis', () => {
  const mode = ref('teachers')
  const teachers = ref([])
  const students = ref([])
  const teacherSummary = ref(defaultSummary())
  const studentSummary = ref(defaultSummary())
  const date = ref('')
  const loading = ref(false)
  const error = ref('')

  const rows = computed(() => (mode.value === 'teachers' ? teachers.value : students.value))
  const summary = computed(() => (mode.value === 'teachers' ? teacherSummary.value : studentSummary.value))

  async function load() {
    loading.value = true
    error.value = ''
    try {
      const [teacherPayload, studentPayload] = await Promise.all([
        api.list('attendance/teachers/today'),
        api.list('attendance/students/today'),
      ])
      teachers.value = extractRows(teacherPayload)
      students.value = extractRows(studentPayload)
      teacherSummary.value = teacherPayload?.summary || defaultSummary()
      studentSummary.value = studentPayload?.summary || defaultSummary()
      date.value = teacherPayload?.date || studentPayload?.date || ''
    } catch (err) {
      error.value = err.message || 'Не удалось загрузить аналитику посещаемости'
    } finally {
      loading.value = false
    }
  }

  function setMode(value) {
    mode.value = value
  }

  return {
    mode,
    teachers,
    students,
    teacherSummary,
    studentSummary,
    date,
    loading,
    error,
    rows,
    summary,
    load,
    setMode,
    formatAttendanceDateTime,
    firstLessonLabel,
  }
})
