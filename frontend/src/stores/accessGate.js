import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'
import { useSettingsStore } from './settings'

function extractRows(payload) { return Array.isArray(payload?.data) ? payload.data : [] }
function normalizeRussianKeyboardLayout(token) {
  if (token.startsWith('CP2:')) return token
  const map = {
    й: 'q', ц: 'w', у: 'e', к: 'r', е: 't', н: 'y', г: 'u', ш: 'i', щ: 'o', з: 'p', х: '[', ъ: ']',
    ф: 'a', ы: 's', в: 'd', а: 'f', п: 'g', р: 'h', о: 'j', л: 'k', д: 'l', ж: ';', э: "'",
    я: 'z', ч: 'x', с: 'c', м: 'v', и: 'b', т: 'n', ь: 'm', б: ',', ю: '.', ё: '`',
    Й: 'Q', Ц: 'W', У: 'E', К: 'R', Е: 'T', Н: 'Y', Г: 'U', Ш: 'I', Щ: 'O', З: 'P', Х: '{', Ъ: '}',
    Ф: 'A', Ы: 'S', В: 'D', А: 'F', П: 'G', Р: 'H', О: 'J', Л: 'K', Д: 'L', Ж: ':', Э: '"',
    Я: 'Z', Ч: 'X', С: 'C', М: 'V', И: 'B', Т: 'N', Ь: 'M', Б: '<', Ю: '>', Ё: '~',
  }
  const candidate = Array.from(token).map((char) => map[char] || char).join('')
  return /^CP2:(?:[A-Za-z0-9_-]{32}|[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)$/.test(candidate) ? candidate : token
}
export function normalizeQrToken(value) {
  let token = String(value || '').trim()
  if (token.startsWith('CP1:')) token = token.slice(4).trim()
  return normalizeRussianKeyboardLayout(token)
}
function fullName(person) { return person?.display_name || [person?.last_name, person?.first_name, person?.middle_name].filter(Boolean).join(' ') }
export function ownerName(event) { return fullName(event?.owner) || 'Неизвестный пропуск' }
export function entityTypeLabel(type, event = null) {
  if (type === 'student') return 'Студент'
  if (type === 'teacher') return 'Преподаватель'
  return event?.owner?.entity_label || 'Неизвестно'
}export function directionLabel(direction) { return direction === 'exit' || direction === 'out' ? 'Выход' : 'Вход' }
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
        device_type: metadata.device_type || 'hid_scanner',
        device_identifier: metadata.device_identifier || undefined,
        direction: metadata.direction || undefined,
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
