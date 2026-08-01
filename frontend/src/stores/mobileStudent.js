import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'

function extractData(payload) { return payload?.data || {} }
function fullName(person) { return [person?.last_name, person?.first_name, person?.middle_name].filter(Boolean).join(' ') }
function localIsoDate(date = new Date()) { return [date.getFullYear(), String(date.getMonth() + 1).padStart(2, '0'), String(date.getDate()).padStart(2, '0')].join('-') }
export function formatMobileDate(value) {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return String(value)
  return date.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' })
}
export function formatMobileScheduleDate(value) {
  if (!value) return '—'
  const date = new Date(`${value}T12:00:00`)
  if (Number.isNaN(date.getTime())) return String(value)
  return date.toLocaleDateString('ru-RU', { weekday: 'long', day: '2-digit', month: 'long' })
}
export function formatLessonTime(lesson) { return [lesson?.starts_at, lesson?.ends_at].filter(Boolean).join('–') || '—' }
export function lessonTitle(lesson) { return lesson?.subject?.name || 'Занятие' }
export function statusLabel(status) {
  const labels = { active: 'Активен', suspended: 'Приостановлен', revoked: 'Отозван', expired: 'Истек' }
  return labels[status] || status || '—'
}
export function attendanceLabel(status) {
  const labels = { present: 'Присутствовал', absent: 'Отсутствовал', late: 'Опоздал', excused: 'Уважительная причина' }
  return labels[status] || status || '—'
}

export const useMobileStudentStore = defineStore('mobileStudent', () => {
  const student = ref(null)
  const todaySchedule = ref([])
  const nextLesson = ref(null)
  const grades = ref([])
  const attendance = ref([])
  const attendanceSummary = ref({ present: 0, absent: 0, late: 0, excused: 0 })
  const digitalIdentity = ref(null)
  const qrSvg = ref('')
  const qrExpiresAt = ref(null)
  const qrRefreshSeconds = ref(30)
  const scheduleDate = ref(localIsoDate())
  const notifications = ref([])
  const message = ref('')
  const loading = ref(false)
  const error = ref('')

  const studentName = computed(() => fullName(student.value) || 'Студент')
  const groupName = computed(() => student.value?.group?.name || 'Группа не указана')
  const hasStudent = computed(() => Boolean(student.value?.id))
  const hasActivePass = computed(() => Boolean(digitalIdentity.value?.id && qrSvg.value))
  const gradeAverage = computed(() => {
    const numeric = grades.value.map((item) => Number(item.grade)).filter((value) => Number.isFinite(value))
    if (!numeric.length) return '—'
    return (numeric.reduce((sum, value) => sum + value, 0) / numeric.length).toFixed(1)
  })
  const attendanceTotal = computed(() => Object.values(attendanceSummary.value || {}).reduce((sum, value) => sum + Number(value || 0), 0))

  async function load(date = scheduleDate.value) {
    loading.value = true
    error.value = ''
    try {
      const payload = extractData(await api.list('mobile/student', { date }))
      student.value = payload.student || null
      todaySchedule.value = payload.today_schedule || []
      nextLesson.value = payload.next_lesson || null
      grades.value = payload.grades || []
      attendance.value = payload.attendance || []
      attendanceSummary.value = payload.attendance_summary || { present: 0, absent: 0, late: 0, excused: 0 }
      digitalIdentity.value = payload.digital_identity || null
      qrSvg.value = payload.qr_svg || ''
      qrExpiresAt.value = payload.qr_expires_at || null
      qrRefreshSeconds.value = Number(payload.qr_refresh_seconds || 30)
      scheduleDate.value = payload.schedule_date || date
      notifications.value = payload.notifications || []
      message.value = payload.message || ''
    } catch (err) {
      error.value = err.message || 'Не удалось загрузить мобильный кабинет'
    } finally {
      loading.value = false
    }
  }

  async function changeScheduleDate(days) {
    const [year, month, day] = scheduleDate.value.split('-').map(Number)
    const date = new Date(year, month - 1, day)
    date.setDate(date.getDate() + days)
    await load(localIsoDate(date))
  }

  return {
    student,
    todaySchedule,
    nextLesson,
    grades,
    attendance,
    attendanceSummary,
    digitalIdentity,
    qrSvg,
    qrExpiresAt,
    qrRefreshSeconds,
    scheduleDate,
    notifications,
    message,
    loading,
    error,
    studentName,
    groupName,
    hasStudent,
    hasActivePass,
    gradeAverage,
    attendanceTotal,
    load,
    changeScheduleDate,
  }
})
