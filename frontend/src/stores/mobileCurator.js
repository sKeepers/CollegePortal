import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'
import { localIsoDate } from './mobileTeacher'
import { formatTime } from '../utils/datetime'

function extractData(payload) { return payload?.data || {} }

export function eventDirectionLabel(direction) {
  return { in: 'Вход', out: 'Выход' }[direction] || direction || '—'
}

export function eventTime(value) {
  return formatTime(value)
}

export const useMobileCuratorStore = defineStore('mobileCurator', () => {
  const curator = ref(null)
  const message = ref('')
  const groups = ref([])
  const loading = ref(false)
  const error = ref('')

  const group = ref(null)
  const students = ref([])
  const summary = ref(null)
  const groupLoading = ref(false)
  const groupError = ref('')

  const date = ref(localIsoDate())
  const range = ref('day')
  const attendanceRows = ref([])
  const attendanceSummary = ref(null)
  const attendanceRange = ref({ from: '', to: '' })
  const attendanceLoading = ref(false)

  // Успеваемость считает журнал, а не проходная: соседний экран отвечает на
  // «был ли проход», этот — на «был ли на занятии и с какой оценкой».
  const performanceRows = ref([])
  const performanceSummary = ref(null)
  const performanceLessons = ref(0)
  const performanceLoading = ref(false)

  const accessEvents = ref([])
  const accessTotal = ref(0)
  const accessTruncated = ref(false)
  const accessLoading = ref(false)

  const hasCurator = computed(() => Boolean(curator.value?.id))
  const hasGroups = computed(() => groups.value.length > 0)
  const curatorName = computed(() => [curator.value?.last_name, curator.value?.first_name, curator.value?.middle_name].filter(Boolean).join(' ') || 'Куратор')
  const studentsWithContacts = computed(() => students.value.filter((student) => student.phone || student.email).length)

  async function load() {
    loading.value = true
    error.value = ''
    try {
      const payload = extractData(await api.list('mobile/curator'))
      curator.value = payload.curator || null
      groups.value = payload.groups || []
      message.value = payload.message || ''
    } catch (err) {
      error.value = err.message || 'Не удалось загрузить кабинет куратора'
    } finally {
      loading.value = false
    }
  }

  /**
   * Чужая группа — это `403` от сервера, и показать его надо честно: без
   * списка, без контактов и без «попробуйте ещё раз».
   */
  async function loadGroup(groupId, on = date.value) {
    groupLoading.value = true
    groupError.value = ''
    try {
      const payload = extractData(await api.list(`mobile/curator/groups/${groupId}`, { date: on }))
      group.value = payload.group || null
      students.value = payload.students || []
      summary.value = payload.summary || null
      date.value = payload.date || on
    } catch (err) {
      group.value = null
      students.value = []
      summary.value = null
      groupError.value = err.status === 403 ? 'Эта группа не закреплена за вами.' : (err.message || 'Не удалось загрузить группу')
    } finally {
      groupLoading.value = false
    }
  }

  async function loadAttendance(groupId, nextRange = range.value, on = date.value) {
    attendanceLoading.value = true
    try {
      const payload = extractData(await api.list(`mobile/curator/groups/${groupId}/attendance`, { date: on, range: nextRange }))
      attendanceRows.value = payload.rows || []
      attendanceSummary.value = payload.summary || null
      attendanceRange.value = { from: payload.date_from || '', to: payload.date_to || '' }
      range.value = payload.range || nextRange
    } catch (err) {
      attendanceRows.value = []
      attendanceSummary.value = null
      groupError.value = err.status === 403 ? 'Эта группа не закреплена за вами.' : (err.message || 'Не удалось загрузить посещаемость')
    } finally {
      attendanceLoading.value = false
    }
  }

  async function loadPerformance(groupId, on = date.value) {
    performanceLoading.value = true
    try {
      const payload = extractData(await api.list(`mobile/curator/groups/${groupId}/performance`, { date: on }))
      performanceRows.value = payload.rows || []
      performanceSummary.value = payload.summary || null
      performanceLessons.value = Number(payload.lessons || 0)
    } catch (err) {
      performanceRows.value = []
      performanceSummary.value = null
      groupError.value = err.status === 403 ? 'Эта группа не закреплена за вами.' : (err.message || 'Не удалось загрузить успеваемость')
    } finally {
      performanceLoading.value = false
    }
  }

  async function loadAccess(groupId, on = date.value) {
    accessLoading.value = true
    try {
      const payload = extractData(await api.list(`mobile/curator/groups/${groupId}/access`, { date: on }))
      accessEvents.value = payload.events || []
      accessTotal.value = Number(payload.total || 0)
      accessTruncated.value = Boolean(payload.truncated)
    } catch (err) {
      accessEvents.value = []
      accessTotal.value = 0
      groupError.value = err.status === 403 ? 'Эта группа не закреплена за вами.' : (err.message || 'Не удалось загрузить события проходной')
    } finally {
      accessLoading.value = false
    }
  }

  async function openGroup(groupId, on = date.value) {
    await loadGroup(groupId, on)
    if (!group.value) return
    await Promise.all([loadAttendance(groupId, range.value, on), loadAccess(groupId, on), loadPerformance(groupId, on)])
  }

  async function changeDate(groupId, days) {
    const [year, month, day] = date.value.split('-').map(Number)
    const shifted = new Date(year, month - 1, day)
    shifted.setDate(shifted.getDate() + days)
    await openGroup(groupId, localIsoDate(shifted))
  }

  async function changeRange(groupId, nextRange) {
    await loadAttendance(groupId, nextRange)
  }

  return {
    curator,
    message,
    groups,
    loading,
    error,
    group,
    students,
    summary,
    groupLoading,
    groupError,
    date,
    range,
    attendanceRows,
    attendanceSummary,
    attendanceRange,
    attendanceLoading,
    accessEvents,
    accessTotal,
    accessTruncated,
    accessLoading,
    hasCurator,
    hasGroups,
    curatorName,
    studentsWithContacts,
    load,
    loadGroup,
    loadAttendance,
    loadAccess,
    loadPerformance,
    performanceRows,
    performanceSummary,
    performanceLessons,
    performanceLoading,
    openGroup,
    changeDate,
    changeRange,
  }
})
