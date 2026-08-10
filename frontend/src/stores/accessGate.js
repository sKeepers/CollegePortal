import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'
import { useSettingsStore } from './settings'

function extractRows(payload) { return Array.isArray(payload?.data) ? payload.data : [] }
export function normalizeQrToken(value) {
  let token = String(value || '').trim()
  if (token.startsWith('CP1:')) token = token.slice(4).trim()
  return token
}
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

/**
 * Крупная строка, которую читает оператор проходной.
 *
 * Раньше здесь стояло «Проход зарегистрирован» — фраза про то, что запись
 * сохранилась. Человеку у турникета нужно другое: зашёл посетитель или вышел.
 * Направление сервер уже вычислил и вернул в ответе, оставалось его показать.
 *
 * У отказа направления нет: пропуск не сработал, и говорить про вход или
 * выход было бы неправдой.
 */
export function outcomeHeadline(event) {
  if (!event) return ''
  return event.result === 'allowed' ? directionLabel(event.direction) : resultLabel(event.result)
}

/** Пояснение под крупной строкой: у отказа — причина, у прохода — что пропуск действителен. */
export function outcomeDetail(event) {
  if (!event) return ''
  if (event.result !== 'allowed') return event.reason || 'Причина отказа не указана.'
  return event.reason || 'Пропуск действителен.'
}
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
  const settingsStore = useSettingsStore()

  const duplicateScanWindowSeconds = computed(() => Number(settingsStore.publicValue('identity', 'duplicate_scan_window_seconds', 2)) || 2)
  const duplicateScanWindowMs = computed(() => duplicateScanWindowSeconds.value * 1000)

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
    const normalizedToken = normalizeQrToken(token)
    if (!normalizedToken) return null

    const now = Date.now()
    if (!settingsStore.publicLoaded) {
      await settingsStore.loadPublic().catch(() => {})
    }

    if (normalizedToken === lastToken.value && now - lastTokenScannedAt.value < duplicateScanWindowMs.value) {
      warning.value = `Повторное сканирование проигнорировано. Подождите ${duplicateScanWindowSeconds.value} сек.`
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
