import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'

function rows(payload) { return Array.isArray(payload?.data) ? payload.data : [] }

export const useReferenceOptionsStore = defineStore('referenceOptions', () => {
  const catalogs = ref({})
  const loading = ref(false)
  const error = ref('')

  async function loadCatalog(code, { force = false } = {}) {
    if (!code) return []
    if (!force && Array.isArray(catalogs.value[code])) return catalogs.value[code]

    loading.value = true
    error.value = ''
    try {
      const payload = await api.list('admin/reference/items', { catalog_code: code, is_active: 1 })
      catalogs.value = { ...catalogs.value, [code]: rows(payload) }
      return catalogs.value[code]
    } catch (err) {
      error.value = err.message || 'Не удалось загрузить справочник'
      catalogs.value = { ...catalogs.value, [code]: [] }
      return []
    } finally {
      loading.value = false
    }
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

  return { catalogs, loading, error, hasData, loadCatalog, loadCatalogs, items, options, label, tone }
})
