import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'

function extractRows(payload) { return Array.isArray(payload?.data) ? payload.data : [] }

export function formatEnteredAt(value) {
  if (!value) return ''
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return ''
  // 24-часовой вид независимо от локали браузера — как везде в портале.
  return date.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit', hour12: false })
}

export const useAccessBuildingsStore = defineStore('accessBuildings', () => {
  const buildings = ref([])
  const points = ref([])
  const muster = ref({ generated_at: null, inside_now: 0, buildings: [] })
  const loading = ref(false)
  const saving = ref(false)
  const error = ref('')

  const insideNow = computed(() => muster.value.inside_now || 0)
  const buildingOptions = computed(() => buildings.value.map((building) => ({ label: building.name, value: building.id })))

  async function loadMuster() {
    loading.value = true
    error.value = ''
    try {
      const payload = await api.list('access/muster')
      muster.value = payload?.data || muster.value
    } catch (err) {
      error.value = err.message || 'Не удалось загрузить список находящихся в здании'
    } finally {
      loading.value = false
    }
  }

  async function loadReference() {
    loading.value = true
    error.value = ''
    try {
      const [buildingsPayload, pointsPayload] = await Promise.all([
        api.list('access/buildings'),
        api.list('access/points'),
      ])
      buildings.value = extractRows(buildingsPayload)
      points.value = extractRows(pointsPayload)
    } catch (err) {
      error.value = err.message || 'Не удалось загрузить справочник корпусов'
    } finally {
      loading.value = false
    }
  }

  async function saveBuilding(payload) {
    saving.value = true
    error.value = ''
    try {
      // `api.update` — это `PATCH`, а маршруты справочника объявлены на `PUT`, и
      // правка с экрана отвечала `405`. Отправляем `PUT`: запрос и так несёт
      // запись целиком, а сервер проверяет её целиком — `StoreBuildingRequest`
      // требует название при каждом сохранении. Тем же способом правятся
      // дисциплины плана (`curricula.js`) и занятия журнала (`journal.js`).
      if (payload.id) await api.put(`access/buildings/${payload.id}`, payload)
      else await api.create('access/buildings', payload)
      await loadReference()
    } catch (err) {
      error.value = err.message || 'Не удалось сохранить корпус'
      throw err
    } finally {
      saving.value = false
    }
  }

  async function savePoint(payload) {
    saving.value = true
    error.value = ''
    try {
      if (payload.id) await api.put(`access/points/${payload.id}`, payload)
      else await api.create('access/points', payload)
      await loadReference()
    } catch (err) {
      error.value = err.message || 'Не удалось сохранить точку прохода'
      throw err
    } finally {
      saving.value = false
    }
  }

  async function removeBuilding(id) {
    saving.value = true
    error.value = ''
    try {
      await api.delete('access/buildings', id)
      await loadReference()
    } catch (err) {
      error.value = err.message || 'Не удалось удалить корпус'
      throw err
    } finally {
      saving.value = false
    }
  }

  async function removePoint(id) {
    saving.value = true
    error.value = ''
    try {
      await api.delete('access/points', id)
      await loadReference()
    } catch (err) {
      error.value = err.message || 'Не удалось удалить точку прохода'
      throw err
    } finally {
      saving.value = false
    }
  }

  return {
    buildings, points, muster, loading, saving, error,
    insideNow, buildingOptions,
    loadMuster, loadReference, saveBuilding, savePoint, removeBuilding, removePoint,
    formatEnteredAt,
  }
})
