import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'
import { buildGroupOptions } from '../utils/groupOptions'

/**
 * Своя группа глазами куратора: состав и успеваемость.
 *
 * Стор один на два экрана — десктопный раздел и мобильный кабинет. Владелец
 * просил одну и ту же картину на компьютере и на телефоне, а две загрузки
 * одной успеваемости однажды покажут два разных средних балла.
 *
 * Оценки приходят из журнала: считает их сервер по `journal_grades`. Своего
 * счёта здесь нет намеренно — ни среднего, ни числа двоек.
 */

function extractData(payload) {
  return payload?.data || {}
}

function refusalMessage(err, fallback) {
  if (err?.status === 403) return 'Эта группа не закреплена за вами.'
  return err?.message || fallback
}

export const useCuratorGroupStore = defineStore('curatorGroup', () => {
  const groups = ref([])
  const message = ref('')
  const groupsLoading = ref(false)
  const error = ref('')

  const groupId = ref(null)
  const performance = ref(null)
  const performanceLoading = ref(false)
  const students = ref([])
  const studentsLoading = ref(false)

  const lessons = ref([])
  const lessonsLoading = ref(false)

  const filters = ref({ date_from: '', date_to: '' })

  const hasGroups = computed(() => groups.value.length > 0)
  const currentGroup = computed(() => groups.value.find((group) => group.id === groupId.value) || performance.value?.group || null)
  const summary = computed(() => performance.value?.summary || null)
  const subjects = computed(() => performance.value?.subjects || [])
  const rows = computed(() => performance.value?.students || [])
  // Кому куратору звонить в первую очередь: двойки и полное отсутствие оценок.
  const needsAttention = computed(() => rows.value.filter((row) => row.failing_count > 0 || !row.has_grades))
  const groupOptions = computed(() => buildGroupOptions(groups.value, {
    suffix: (group) => (group.students_count === null || group.students_count === undefined
      ? null
      : `${group.students_count} чел.`),
  }))

  async function loadGroups() {
    groupsLoading.value = true
    error.value = ''
    try {
      const payload = extractData(await api.list('curator/groups'))
      groups.value = payload.groups || []
      message.value = payload.message || ''
      if (!groupId.value && groups.value.length) groupId.value = groups.value[0].id
    } catch (err) {
      groups.value = []
      error.value = err.message || 'Не удалось загрузить список групп'
    } finally {
      groupsLoading.value = false
    }
  }

  async function loadPerformance(id = groupId.value) {
    if (!id) return
    performanceLoading.value = true
    error.value = ''
    try {
      const params = {}
      if (filters.value.date_from) params.date_from = filters.value.date_from
      if (filters.value.date_to) params.date_to = filters.value.date_to
      performance.value = extractData(await api.list(`curator/groups/${id}/performance`, params))
      groupId.value = Number(id)
    } catch (err) {
      performance.value = null
      error.value = refusalMessage(err, 'Не удалось загрузить успеваемость')
    } finally {
      performanceLoading.value = false
    }
  }

  async function loadStudents(id = groupId.value) {
    if (!id) return
    studentsLoading.value = true
    try {
      const payload = extractData(await api.list(`curator/groups/${id}/students`))
      students.value = payload.students || []
    } catch (err) {
      students.value = []
      error.value = refusalMessage(err, 'Не удалось загрузить состав группы')
    } finally {
      studentsLoading.value = false
    }
  }

  /**
   * Занятия группы берутся из журнала, а не из своего эндпоинта: с 12.08.2026
   * журнал сам показывает куратору занятия его группы у любого преподавателя.
   * Второй список тех же занятий означал бы второе правило, кто их видит.
   */
  async function loadLessons(id = groupId.value, on = {}) {
    if (!id) return
    lessonsLoading.value = true
    try {
      const params = { group_id: id, per_page: 100 }
      const from = on.date_from ?? filters.value.date_from
      const to = on.date_to ?? filters.value.date_to
      if (from) params.date_from = from
      if (to) params.date_to = to
      const payload = await api.list('journal/lessons', params)
      lessons.value = Array.isArray(payload?.data) ? payload.data : []
    } catch (err) {
      lessons.value = []
      error.value = refusalMessage(err, 'Не удалось загрузить занятия группы')
    } finally {
      lessonsLoading.value = false
    }
  }

  async function open(id) {
    groupId.value = Number(id)
    await Promise.all([loadPerformance(id), loadStudents(id)])
  }

  return {
    groups,
    message,
    groupsLoading,
    error,
    groupId,
    performance,
    performanceLoading,
    students,
    studentsLoading,
    lessons,
    lessonsLoading,
    filters,
    hasGroups,
    currentGroup,
    summary,
    subjects,
    rows,
    needsAttention,
    groupOptions,
    loadGroups,
    loadPerformance,
    loadStudents,
    loadLessons,
    open,
  }
})
