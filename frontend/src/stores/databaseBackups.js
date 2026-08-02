import { ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'

export const useDatabaseBackupsStore = defineStore('databaseBackups', () => {
  const snapshots = ref([])
  const loading = ref(false)
  const error = ref('')
  const lastMessage = ref('')

  async function load() {
    loading.value = true
    error.value = ''
    try {
      const payload = await api.list('admin/database-backups')
      snapshots.value = payload?.data || []
    } catch (err) {
      error.value = err.message || 'Не удалось загрузить архивы PostgreSQL'
    } finally {
      loading.value = false
    }
  }

  async function create() {
    loading.value = true
    error.value = ''
    try {
      const payload = await api.create('admin/database-backups', {})
      lastMessage.value = payload?.message || 'Архив создан'
      await load()
      return payload
    } catch (err) {
      error.value = err.message || 'Не удалось создать архив PostgreSQL'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function restore(snapshotId, confirmation) {
    loading.value = true
    error.value = ''
    try {
      const payload = await api.create(`admin/database-backups/${encodeURIComponent(snapshotId)}/restore`, { confirmation })
      lastMessage.value = payload?.message || 'База данных восстановлена'
      await load()
      return payload
    } catch (err) {
      error.value = err.message || 'Не удалось восстановить архив PostgreSQL'
      throw err
    } finally {
      loading.value = false
    }
  }

  return { snapshots, loading, error, lastMessage, load, create, restore }
})
