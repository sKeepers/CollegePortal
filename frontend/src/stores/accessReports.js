import { computed, reactive, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'
import { directionLabel, entityTypeLabel, formatEventTime, ownerName, resultLabel, resultTone } from './accessGate'

function extractRows(payload) { return Array.isArray(payload?.data) ? payload.data : [] }
function todayIsoDate() { return new Date().toISOString().slice(0, 10) }

export const ACCESS_ENTITY_OPTIONS = [
  { label: 'Все', value: '' },
  { label: 'Студенты', value: 'student' },
  { label: 'Преподаватели', value: 'teacher' },
  { label: 'Сотрудники', value: 'employee' },
]

export const ACCESS_PERIOD_OPTIONS = [
  { label: 'День', value: 'day' },
  { label: 'Неделя', value: 'week' },
  { label: 'Месяц', value: 'month' },
]

/**
 * Период задается одной кнопкой, а не двумя датами: день, неделя и месяц —
 * это то, чем отчет спрашивают на самом деле. Точные даты остаются рядом
 * для произвольного отрезка.
 */
export function periodRange(period) {
  const today = new Date()
  const from = new Date(today)
  if (period === 'week') from.setDate(today.getDate() - 6)
  if (period === 'month') from.setMonth(today.getMonth() - 1)
  return { date_from: from.toISOString().slice(0, 10), date_to: today.toISOString().slice(0, 10) }
}

export const ACCESS_RESULT_OPTIONS = [
  { label: 'Все', value: '' },
  { label: 'Разрешено', value: 'allowed' },
  { label: 'Отказано', value: 'denied' },
]

export const useAccessReportsStore = defineStore('accessReports', () => {
  const events = ref([])
  const meta = ref({ total: 0, limit: 0, truncated: false })
  const summary = ref({ today_events: 0, entries: 0, exits: 0, denied: 0, inside_now: 0 })
  const loading = ref(false)
  const exporting = ref(false)
  const error = ref('')

  // Отчёт получен — только тогда его числа что-то значат. При оборванном
  // запросе `meta` остаётся начальной, и «Событий в отчете: 0» становится
  // утверждением о проходной; пустое состояние при этом советовало «изменить
  // фильтры», хотя фильтры ни при чём. Замер 03.09.2026.
  const loaded = ref(false)
  const filters = reactive({ date_from: todayIsoDate(), date_to: todayIsoDate(), entity_type: '', result: '', search: '', only_late: false })
  const period = ref('day')

  const queryParams = computed(() => Object.fromEntries(
    Object.entries(filters).filter(([, value]) => value !== null && value !== undefined && value !== '' && value !== false),
  ))

  // «Только опоздавшие» опирается на расписание, а у сотрудников оно не заведено:
  // рабочий график с порогом опоздания пока не связан.
  const lateFilterAvailable = computed(() => filters.entity_type !== 'employee')

  // Сколько событий подошло под фильтры и сколько из них показано. Раньше на
  // экране стояло число строк таблицы, и обрезанный список выдавался за весь отчёт.
  const totalEvents = computed(() => meta.value.total ?? events.value.length)
  const shownEvents = computed(() => events.value.length)
  const truncated = computed(() => Boolean(meta.value.truncated))

  function setPeriod(value) {
    period.value = value
    Object.assign(filters, periodRange(value))
  }

  /**
   * Из строки события открывается карточка человека, а не поиск по фамилии:
   * однофамильцы иначе приводят не туда.
   */
  function personRoute(event) {
    if (!event?.entity_id) return null
    if (event.entity_type === 'student') return { path: '/students', query: { student: String(event.entity_id) } }
    if (event.entity_type === 'teacher') return { path: '/teachers', query: { teacher: String(event.entity_id) } }
    if (event.entity_type === 'employee') return { path: '/hr/employees', query: { employee: String(event.entity_id) } }
    return null
  }

  async function load() {
    loading.value = true
    error.value = ''
    try {
      const [summaryPayload, eventsPayload] = await Promise.all([
        api.list('access/reports/summary', queryParams.value),
        api.list('access/reports/events', queryParams.value),
      ])
      summary.value = summaryPayload?.data || summary.value
      events.value = extractRows(eventsPayload)
      meta.value = eventsPayload?.meta || { total: events.value.length, limit: events.value.length, truncated: false }
      loaded.value = true
    } catch (err) {
      error.value = err.message || 'Не удалось загрузить отчет по проходам'
    } finally {
      loading.value = false
    }
  }

  function resetFilters() {
    filters.date_from = todayIsoDate()
    filters.date_to = todayIsoDate()
    filters.entity_type = ''
    filters.result = ''
    filters.search = ''
    filters.only_late = false
    period.value = 'day'
  }

  async function exportCsv() {
    exporting.value = true
    error.value = ''
    try {
      const params = new URLSearchParams({ ...queryParams.value, export: 'csv' })
      const blob = await api.download(`/access/reports/events?${params.toString()}`)
      const url = URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.download = `access-events-${todayIsoDate()}.csv`
      document.body.appendChild(link)
      link.click()
      link.remove()
      URL.revokeObjectURL(url)
    } catch (err) {
      error.value = err.message || 'Не удалось выгрузить CSV'
      throw err
    } finally {
      exporting.value = false
    }
  }

  return { events, meta, summary, loading, exporting, error, loaded, filters, period, lateFilterAvailable, totalEvents, shownEvents, truncated, load, resetFilters, setPeriod, personRoute, exportCsv, directionLabel, entityTypeLabel, formatEventTime, ownerName, resultLabel, resultTone }
})
