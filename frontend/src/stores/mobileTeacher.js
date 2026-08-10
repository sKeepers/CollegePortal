import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'

function extractData(payload) { return payload?.data || {} }
function fullName(person) { return [person?.last_name, person?.first_name, person?.middle_name].filter(Boolean).join(' ') }
export function localIsoDate(date = new Date()) {
  return [date.getFullYear(), String(date.getMonth() + 1).padStart(2, '0'), String(date.getDate()).padStart(2, '0')].join('-')
}
export function formatCabinetDate(value) {
  if (!value) return '—'
  const date = new Date(`${value}T12:00:00`)
  if (Number.isNaN(date.getTime())) return String(value)
  return date.toLocaleDateString('ru-RU', { weekday: 'long', day: '2-digit', month: 'long' })
}
export function weekdayShort(value) {
  if (!value) return ''
  const date = new Date(`${value}T12:00:00`)
  if (Number.isNaN(date.getTime())) return ''
  return date.toLocaleDateString('ru-RU', { weekday: 'short' })
}
export function dayNumber(value) {
  if (!value) return ''
  return String(Number(value.slice(-2)))
}
export function lessonTime(lesson) { return [lesson?.starts_at, lesson?.ends_at].filter(Boolean).join('–') || '—' }
export function lessonTitle(lesson) { return lesson?.subject?.name || 'Занятие' }
export function studentName(student) { return fullName(student) || 'Студент' }

export const ATTENDANCE_STATUSES = [
  { value: 'present', short: 'П', label: 'Присутствовал' },
  { value: 'absent', short: 'Н', label: 'Отсутствовал' },
  { value: 'late', short: 'О', label: 'Опоздал' },
  { value: 'excused', short: 'У', label: 'Уважительная причина' },
]

export const GRADE_VALUES = ['5', '4', '3', '2']

export const useMobileTeacherStore = defineStore('mobileTeacher', () => {
  const teacher = ref(null)
  const message = ref('')
  const scheduleDate = ref(localIsoDate())
  const lessons = ref([])
  const week = ref([])
  const daySummary = ref({ lessons: 0, journals_opened: 0, students: 0, marked: 0 })
  const abilities = ref({ open_journal: false, mark_attendance: false, set_grades: false })
  const nextLessonId = ref(null)
  const digitalIdentity = ref(null)
  const qrSvg = ref('')
  const qrExpiresAt = ref(null)
  const qrRefreshSeconds = ref(30)
  const loading = ref(false)
  const error = ref('')

  // Журнал одного занятия: своя загрузка, чтобы экран занятия открывался по
  // прямой ссылке, а не только переходом с главной.
  const journal = ref(null)
  const journalLoading = ref(false)
  const journalError = ref('')
  const savingStudentId = ref(null)

  const teacherName = computed(() => fullName(teacher.value) || 'Преподаватель')
  const hasTeacher = computed(() => Boolean(teacher.value?.id))
  const hasActivePass = computed(() => Boolean(digitalIdentity.value?.id && qrSvg.value))
  const nextLesson = computed(() => lessons.value.find((lesson) => lesson.id === nextLessonId.value) || null)
  const journalStudents = computed(() => {
    const rows = journal.value?.attendance || []
    return [...rows].sort((left, right) => studentName(left.student).localeCompare(studentName(right.student), 'ru'))
  })
  const journalIsSigned = computed(() => journal.value?.status === 'signed')
  const canMarkAttendance = computed(() => abilities.value.mark_attendance && !journalIsSigned.value)
  const canSetGrades = computed(() => abilities.value.set_grades && !journalIsSigned.value)

  async function load(date = scheduleDate.value) {
    loading.value = true
    error.value = ''
    try {
      const payload = extractData(await api.list('mobile/teacher', { date }))
      teacher.value = payload.teacher || null
      message.value = payload.message || ''
      scheduleDate.value = payload.schedule_date || date
      lessons.value = payload.lessons || []
      week.value = payload.week || []
      daySummary.value = payload.day_summary || { lessons: 0, journals_opened: 0, students: 0, marked: 0 }
      abilities.value = payload.abilities || { open_journal: false, mark_attendance: false, set_grades: false }
      nextLessonId.value = payload.next_lesson || null
      digitalIdentity.value = payload.digital_identity || null
      qrSvg.value = payload.qr_svg || ''
      qrExpiresAt.value = payload.qr_expires_at || null
      qrRefreshSeconds.value = Number(payload.qr_refresh_seconds || 30)
    } catch (err) {
      error.value = err.message || 'Не удалось загрузить мобильный кабинет'
    } finally {
      loading.value = false
    }
  }

  async function selectDate(date) {
    await load(date)
  }

  async function changeScheduleDate(days) {
    const [year, month, day] = scheduleDate.value.split('-').map(Number)
    const date = new Date(year, month - 1, day)
    date.setDate(date.getDate() + days)
    await load(localIsoDate(date))
  }

  /** Открыть журнал занятия расписания и вернуть идентификатор журнала. */
  async function openJournal(scheduleLessonId) {
    journalError.value = ''
    try {
      const payload = extractData(await api.post(`journal/from-legacy-schedule/${scheduleLessonId}/open`))
      return payload.id || null
    } catch (err) {
      journalError.value = err.message || 'Не удалось открыть журнал занятия'
      return null
    }
  }

  async function loadJournal(lessonId) {
    journalLoading.value = true
    journalError.value = ''
    try {
      journal.value = extractData(await api.list(`journal/lessons/${lessonId}`))
      // Права приходят вместе с кабинетом; при заходе по прямой ссылке их ещё нет.
      if (!hasTeacher.value) await load()
    } catch (err) {
      journal.value = null
      journalError.value = err.message || 'Не удалось загрузить журнал занятия'
    } finally {
      journalLoading.value = false
    }
  }

  /**
   * Отметка ставится сразу по нажатию: на телефоне отдельная кнопка «Сохранить»
   * означает потерянную отметку. Значение показывается до ответа сервера и
   * откатывается, если он отказал.
   */
  async function markAttendance(studentId, status) {
    if (!journal.value || !canMarkAttendance.value) return
    const row = journal.value.attendance.find((item) => item.student_id === studentId)
    if (!row || row.status === status) return

    const previous = row.status
    row.status = status
    savingStudentId.value = studentId
    journalError.value = ''
    try {
      journal.value = extractData(await api.put(`journal/lessons/${journal.value.id}/attendance`, {
        attendance: [{ student_id: studentId, status }],
      }))
    } catch (err) {
      row.status = previous
      journalError.value = err.message || 'Не удалось сохранить отметку'
    } finally {
      savingStudentId.value = null
    }
  }

  async function setGrade(studentId, value) {
    if (!journal.value || !canSetGrades.value) return
    savingStudentId.value = studentId
    journalError.value = ''
    try {
      journal.value = extractData(await api.put(`journal/lessons/${journal.value.id}/grades`, {
        grades: [{ student_id: studentId, value }],
      }))
    } catch (err) {
      journalError.value = err.message || 'Не удалось сохранить оценку'
    } finally {
      savingStudentId.value = null
    }
  }

  async function saveTopic(topic) {
    if (!journal.value) return
    journalError.value = ''
    try {
      journal.value = extractData(await api.put(`journal/lessons/${journal.value.id}`, { topic }))
    } catch (err) {
      journalError.value = err.message || 'Не удалось сохранить тему занятия'
    }
  }

  function gradeFor(studentId) {
    const grades = journal.value?.grades || []
    return grades.find((item) => item.student_id === studentId && item.value)?.value || null
  }

  return {
    teacher,
    message,
    scheduleDate,
    lessons,
    week,
    daySummary,
    abilities,
    nextLessonId,
    digitalIdentity,
    qrSvg,
    qrExpiresAt,
    qrRefreshSeconds,
    loading,
    error,
    journal,
    journalLoading,
    journalError,
    savingStudentId,
    teacherName,
    hasTeacher,
    hasActivePass,
    nextLesson,
    journalStudents,
    journalIsSigned,
    canMarkAttendance,
    canSetGrades,
    load,
    selectDate,
    changeScheduleDate,
    openJournal,
    loadJournal,
    markAttendance,
    setGrade,
    saveTopic,
    gradeFor,
  }
})
