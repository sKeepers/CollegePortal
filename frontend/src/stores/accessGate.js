import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'

const DUPLICATE_SCAN_WINDOW_MS = 2000

function extractRows(payload) { return Array.isArray(payload?.data) ? payload.data : [] }
function fullName(person) { return [person?.last_name, person?.first_name, person?.middle_name].filter(Boolean).join(' ') }
export function ownerName(event) { return fullName(event?.owner) || 'Неизвестный пропуск' }
export function entityTypeLabel(type) {
  if (type === 'student') return 'Студент'
  if (type === 'teacher') return 'Преподаватель'
  return 'Неизвестно'
}
export function directionLabel(direction) { return direction === 'out' ? 'Выход' : 'Вход' }
export function resultLabel(result) { return result === 'allowed' ? 'Разрешено' : 'Отказано' }
export function resultTone(result) { return result === 'allowed' ? 'success' : 'danger' }
export function formatEventTime(value) {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return String(value)
  const datePart = date.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' })
  const timePart = date.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })
  return datePart + " " + timePart
}

export const useAccessGateStore = defineStore('accessGate', () => {
  const events = ref([])
  const lastEvent = ref(null)
  const loading = ref(false)
  const scanning = ref(false)
  const error = ref('')
  const warning = ref('')
  const lastToken = ref('')
  const lastTokenScannedAt = ref(0)

  const allowedCount = computed(() => events.value.filter((event) => event.result === 'allowed').length)
  const deniedCount = computed(() => events.value.filter((event) => event.result === 'denied').length)

  async function loadEvents() {
    loading.value = true
    error.value = ''
    try {
      const payload = await api.list('access/events')
      events.value = extractRows(payload)
      if (!lastEvent.value && events.value[0]) lastEvent.value = events.value[0]
    } catch (err) {
      error.value = err.message || 'Не удалось загрузить события проходной'
    } finally {
      loading.value = false
    }
  }

  async function scan(token, metadata = {}) {
    const normalizedToken = String(token || '').trim()
    if (!normalizedToken) return null

    const now = Date.now()
    if (normalizedToken === lastToken.value && now - lastTokenScannedAt.value < DUPLICATE_SCAN_WINDOW_MS) {
      warning.value = 'Повторное сканирование проигнорировано. Подождите 2 секунды.'
      return lastEvent.value
    }

    lastToken.value = normalizedToken
    lastTokenScannedAt.value = now
    scanning.value = true
    error.value = ''
    warning.value = ''
    try {
      const response = await api.create('access/scan', {
        token: normalizedToken,
        access_point: metadata.access_point || 'Главный вход',
        device_name: metadata.device_name || 'HID QR Scanner',
      })
      lastEvent.value = response?.data || null
      if (lastEvent.value?.duplicate_ignored) {
        warning.value = 'Повторное сканирование проигнорировано. Новое событие не создано.'
      }
      await loadEvents()
      return lastEvent.value
    } catch (err) {
      error.value = err.message || 'Не удалось выполнить сканирование QR'
      throw err
    } finally {
      scanning.value = false
    }
  }

  return { events, lastEvent, loading, scanning, error, warning, allowedCount, deniedCount, loadEvents, scan }
})
