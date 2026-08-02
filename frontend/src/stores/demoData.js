import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'
import { getCurrentEnvironment } from '../services/environmentService'

function extractData(payload) { return payload?.data || {} }

export const useDemoDataStore = defineStore('demoData', () => {
  const summary = ref({})
  const loading = ref(false)
  const error = ref('')
  const lastMessage = ref('')
  const importResult = ref(null)
  const lastClearResult = ref(null)
  const isProduction = computed(() => getCurrentEnvironment().key === 'production')

  async function load() {
    loading.value = true
    error.value = ''
    try {
      summary.value = extractData(await api.list('admin/demo-data'))
    } catch (err) {
      error.value = err.message || 'Не удалось загрузить состояние данных'
    } finally {
      loading.value = false
    }
  }

  async function createDemoData() {
    loading.value = true
    error.value = ''
    try {
      const payload = await api.create('admin/demo-data/create', {})
      summary.value = extractData(payload)
      lastMessage.value = payload?.message || 'Демо-данные созданы'
      return payload
    } catch (err) {
      error.value = err.message || 'Не удалось создать демо-данные'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function clearDemoData() {
    if (isProduction.value) {
      throw new Error('Очистка демо-данных запрещена в production')
    }
    loading.value = true
    error.value = ''
    try {
      const payload = await api.create('admin/demo-data/reset', {})
      lastClearResult.value = payload?.data || null
      summary.value = payload?.data?.summary || summary.value
      lastMessage.value = payload?.message || 'Рабочие данные DEV очищены'
      return payload
    } catch (err) {
      error.value = err.message || 'Не удалось очистить демо-данные'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function importData(file) {
    if (!file) return null
    loading.value = true
    error.value = ''
    importResult.value = null
    try {
      const formData = new FormData()
      formData.append('file', file)
      const payload = await api.upload('/admin/demo-data/import', formData)
      importResult.value = extractData(payload)
      lastMessage.value = payload?.message || 'Файл импортирован'
      await load()
      return payload
    } catch (err) {
      error.value = err.message || 'Не удалось импортировать данные'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function exportData() {
    const blob = await api.download('/admin/demo-data/export')
    const url = window.URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = 'demo-data-summary.csv'
    link.click()
    window.URL.revokeObjectURL(url)
  }

  return { summary, loading, error, lastMessage, importResult, lastClearResult, isProduction, load, createDemoData, clearDemoData, importData, exportData }
})
