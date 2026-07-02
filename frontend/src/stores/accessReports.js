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
]

export const ACCESS_RESULT_OPTIONS = [
  { label: 'Все', value: '' },
  { label: 'Разрешено', value: 'allowed' },
  { label: 'Отказано', value: 'denied' },
]

export const useAccessReportsStore = defineStore('accessReports', () => {
  const events = ref([])
  const summary = ref({ today_events: 0, entries: 0, exits: 0, denied: 0, inside_now: 0 })
  const loading = ref(false)
  const exporting = ref(false)
  const error = ref('')
  const filters = reactive({ date_from: todayIsoDate(), date_to: todayIsoDate(), entity_type: '', result: '', search: '' })

  const queryParams = computed(() => Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== null && value !== undefined && value !== '')))

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

  return { events, summary, loading, exporting, error, filters, load, resetFilters, exportCsv, directionLabel, entityTypeLabel, formatEventTime, ownerName, resultLabel, resultTone }
})
