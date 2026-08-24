import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'

/**
 * Бланки строгой отчётности и книга регистрации выданных дипломов.
 *
 * Удаления здесь нет и не будет: испорченный бланк отмечается испорченным и
 * списывается актом. Ни одного вызова `api.delete` в этом хранилище быть не
 * должно — если он появится, значит кто-то не прочитал, зачем учёт заводился.
 */

export const BLANK_KIND_OPTIONS = [
  { label: 'Диплом', value: 'diploma' },
  { label: 'Диплом с отличием', value: 'diploma_honours' },
  { label: 'Приложение к диплому', value: 'supplement' },
  { label: 'Дубликат', value: 'duplicate' },
]

export const BLANK_STATUS_OPTIONS = [
  { label: 'В наличии', value: 'stock', tone: 'neutral' },
  { label: 'Закреплён', value: 'assigned', tone: 'info' },
  { label: 'Выдан', value: 'issued', tone: 'success' },
  { label: 'Испорчен', value: 'spoiled', tone: 'warning' },
  { label: 'Списан', value: 'written_off', tone: 'danger' },
]

const initialFilters = { kind: '', status: '', series: '', number: '' }

function extractRows(payload) { return Array.isArray(payload?.data) ? payload.data : [] }
export function kindLabel(value) { return BLANK_KIND_OPTIONS.find((item) => item.value === value)?.label || value || '—' }
export function statusLabel(value) { return BLANK_STATUS_OPTIONS.find((item) => item.value === value)?.label || value || '—' }
export function statusTone(value) { return BLANK_STATUS_OPTIONS.find((item) => item.value === value)?.tone || 'neutral' }

export function formatRuDate(value) {
  if (!value) return '—'
  const date = new Date(`${value}T00:00:00`)
  return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString('ru-RU')
}

export const useDiplomaBlanksStore = defineStore('diplomaBlanks', () => {
  const blanks = ref([])
  const batches = ref([])
  const balance = ref([])
  const registry = ref([])
  const registryYears = ref([])
  const filters = ref({ ...initialFilters })
  const loading = ref(false)
  const saving = ref(false)
  const error = ref('')

  /** Остаток одной строкой: столько бланков сейчас можно печатать. */
  const inStock = computed(() => balance.value.reduce((sum, row) => sum + (row.stock || 0), 0))
  const spoiled = computed(() => balance.value.reduce((sum, row) => sum + (row.spoiled || 0), 0))

  async function load() {
    loading.value = true
    error.value = ''
    try {
      const query = Object.fromEntries(Object.entries(filters.value).filter(([, value]) => value !== '' && value !== null))
      const [list, balancePayload, batchPayload] = await Promise.all([
        api.list('diploma-blanks', query),
        api.get('/diploma-blanks/balance'),
        api.list('diploma-blanks/batches'),
      ])
      blanks.value = extractRows(list)
      balance.value = extractRows(balancePayload)
      batches.value = extractRows(batchPayload)
    } catch (err) {
      error.value = err.message || 'Не удалось загрузить бланки'
    } finally {
      loading.value = false
    }
  }

  async function loadRegistry(year = null) {
    loading.value = true
    error.value = ''
    try {
      const payload = await api.get('/diploma-registry', year ? { graduation_year: year } : {})
      registry.value = extractRows(payload)
      registryYears.value = payload?.meta?.years || []
    } catch (err) {
      error.value = err.message || 'Не удалось загрузить книгу регистрации'
    } finally {
      loading.value = false
    }
  }

  /** Приход партии: сервер сам развернёт диапазон номеров в отдельные бланки. */
  async function receive(payload) {
    return act(() => api.create('diploma-blanks/batches', {
      kind: payload.kind,
      series: String(payload.series || '').trim(),
      number_from: String(payload.number_from || '').trim(),
      number_to: String(payload.number_to || '').trim(),
      received_at: payload.received_at || null,
      supplier: payload.supplier?.trim() || null,
      invoice_number: payload.invoice_number?.trim() || null,
      note: payload.note?.trim() || null,
    }), 'Не удалось принять партию')
  }

  async function assign(blank, graduateId, note = '') {
    return act(() => api.create(`diploma-blanks/${blank.id}/assign`, { graduate_id: graduateId, note: note || null }), 'Не удалось закрепить бланк')
  }

  async function release(blank, reason = '') {
    return act(() => api.create(`diploma-blanks/${blank.id}/release`, { reason: reason || null }), 'Не удалось снять закрепление')
  }

  async function issue(blank, issuedAt = null) {
    return act(() => api.create(`diploma-blanks/${blank.id}/issue`, { issued_at: issuedAt }), 'Не удалось выдать бланк')
  }

  /** Причина обязательна: «испорчен» без причины — это пропавший бланк с пометкой. */
  async function spoil(blank, reason) {
    return act(() => api.create(`diploma-blanks/${blank.id}/spoil`, { reason }), 'Не удалось отметить порчу')
  }

  /** Номер акта обязателен: списание без акта — это бланк, исчезнувший по слову нажавшего кнопку. */
  async function writeOff(blank, actNumber, reason = '') {
    return act(() => api.create(`diploma-blanks/${blank.id}/write-off`, { act_number: actNumber, reason: reason || null }), 'Не удалось списать бланк')
  }

  async function act(call, failure) {
    saving.value = true
    error.value = ''
    try {
      const response = await call()
      await load()
      return response?.data || null
    } catch (err) {
      error.value = err.message || failure
      throw err
    } finally {
      saving.value = false
    }
  }

  function setFilters(next) { filters.value = { ...filters.value, ...next } }
  function resetFilters() { filters.value = { ...initialFilters } }

  return {
    blanks, batches, balance, registry, registryYears, filters, loading, saving, error,
    inStock, spoiled,
    load, loadRegistry, receive, assign, release, issue, spoil, writeOff, setFilters, resetFilters,
  }
})
