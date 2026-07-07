import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'

function extractData(payload) { return payload?.data || {} }
function extractRows(payload) { return Array.isArray(payload?.data) ? payload.data : [] }

export const useUniversalImportStore = defineStore('universalImport', () => {
  const config = ref({ types: [], modes: [] })
  const history = ref([])
  const currentJob = ref(null)
  const loading = ref(false)
  const saving = ref(false)
  const error = ref('')

  const typeOptions = computed(() => config.value.types || [])
  const modeOptions = computed(() => config.value.modes || [])
  const selectedTypeConfig = computed(() => typeOptions.value.find((type) => type.value === currentJob.value?.data_type) || null)

  async function loadConfig() {
    loading.value = true
    error.value = ''
    try {
      config.value = extractData(await api.list('admin/import/config'))
    } catch (err) {
      error.value = err.message || 'Не удалось загрузить настройки импорта'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function loadHistory(dataType = '') {
    try {
      history.value = extractRows(await api.list('admin/import/history', { data_type: dataType }))
    } catch (err) {
      error.value = err.message || 'Не удалось загрузить историю импортов'
    }
  }

  async function preview(dataType, file) {
    if (!dataType || !file) return null
    saving.value = true
    error.value = ''
    try {
      const formData = new FormData()
      formData.append('data_type', dataType)
      formData.append('file', file)
      const payload = await api.upload('/admin/import/preview', formData)
      currentJob.value = extractData(payload)
      await loadHistory(dataType)
      return currentJob.value
    } catch (err) {
      error.value = err.message || 'Не удалось подготовить предварительный просмотр'
      throw err
    } finally {
      saving.value = false
    }
  }

  async function validate(mapping, mode) {
    if (!currentJob.value?.id) return null
    saving.value = true
    error.value = ''
    try {
      const payload = await api.create(`admin/import/${currentJob.value.id}/validate`, { mapping, mode })
      currentJob.value = extractData(payload)
      await loadHistory(currentJob.value.data_type)
      return currentJob.value
    } catch (err) {
      error.value = err.message || 'Не удалось проверить строки'
      throw err
    } finally {
      saving.value = false
    }
  }

  async function confirm(mapping, mode) {
    if (!currentJob.value?.id) return null
    saving.value = true
    error.value = ''
    try {
      const payload = await api.create(`admin/import/${currentJob.value.id}/confirm`, { mapping, mode })
      currentJob.value = extractData(payload)
      await loadHistory(currentJob.value.data_type)
      return currentJob.value
    } catch (err) {
      error.value = err.message || 'Не удалось выполнить импорт'
      throw err
    } finally {
      saving.value = false
    }
  }

  async function downloadTemplate(dataType) {
    if (!dataType) return null
    saving.value = true
    error.value = ''
    try {
      return await api.download(`/admin/import/templates/${dataType}.csv`)
    } catch (err) {
      error.value = err.message || 'Не удалось скачать шаблон'
      throw err
    } finally {
      saving.value = false
    }
  }

  function resetJob() { currentJob.value = null }

  return { config, history, currentJob, loading, saving, error, typeOptions, modeOptions, selectedTypeConfig, loadConfig, loadHistory, preview, validate, confirm, downloadTemplate, resetJob }
})
