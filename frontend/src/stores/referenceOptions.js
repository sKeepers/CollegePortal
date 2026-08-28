import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'

function rows(payload) { return Array.isArray(payload?.data) ? payload.data : [] }

export const useReferenceOptionsStore = defineStore('referenceOptions', () => {
  const catalogs = ref({})
  // Состояние каждого справочника отдельно: пустой список и отказ в правах
  // выглядели на экране одинаково — молчащим выпадающим списком, и владелец
  // не мог отличить «справочник не заполнен» от «мне сюда нельзя».
  const states = ref({})
  const loading = ref(false)
  const error = ref('')

  function setState(code, status, message = '') {
    states.value = { ...states.value, [code]: { status, message } }
  }

  async function loadCatalog(code, { force = false } = {}) {
    if (!code) return []
    if (!force && Array.isArray(catalogs.value[code])) return catalogs.value[code]

    loading.value = true
    error.value = ''
    try {
      const payload = await api.listAll('admin/reference/items', { catalog_code: code, is_active: 1 })
      const items = rows(payload)
      catalogs.value = { ...catalogs.value, [code]: items }
      setState(code, items.length ? 'ok' : 'empty')
      return items
    } catch (err) {
      const denied = err?.status === 403
      // Отказ по одному справочнику не должен выглядеть как поломка страницы:
      // общий error оставляем только для настоящих сбоев.
      if (!denied) error.value = err.message || 'Не удалось загрузить справочник'
      setState(code, denied ? 'denied' : 'failed', err.message || '')
      catalogs.value = { ...catalogs.value, [code]: [] }
      return []
    } finally {
      loading.value = false
    }
  }

  function state(code) {
    return states.value[code]?.status || 'unknown'
  }

  /** Готовая подпись под выпадающим списком: почему он пуст. */
  function hint(code) {
    return {
      denied: 'Нет прав на справочник — обратитесь к администратору',
      empty: 'Справочник пуст — заполните его в разделе «Справочники»',
      failed: 'Справочник не загрузился, повторите позже',
    }[state(code)] || ''
  }

  async function loadCatalogs(codes) {
    await Promise.all([...new Set(codes.filter(Boolean))].map((code) => loadCatalog(code)))
  }

  function items(code) {
    return catalogs.value[code] || []
  }

  function options(code, { valueField = 'code' } = {}) {
    return items(code).map((item) => ({
      label: item.name,
      value: valueField === 'name' ? item.name : item.code,
      code: item.code,
      id: item.id,
      tone: item.metadata?.tone || 'neutral',
      metadata: item.metadata || {},
    }))
  }

  function label(code, value, fallback = '—', { valueField = 'code' } = {}) {
    const option = options(code, { valueField }).find((item) => item.value === value || item.code === value)
    return option?.label || value || fallback
  }

  function tone(code, value, fallback = 'neutral', { valueField = 'code' } = {}) {
    const option = options(code, { valueField }).find((item) => item.value === value || item.code === value)
    return option?.tone || fallback
  }

  const hasData = computed(() => Object.keys(catalogs.value).length > 0)

  return { catalogs, states, loading, error, hasData, loadCatalog, loadCatalogs, items, options, label, tone, state, hint }
})
