import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'

function rows(payload) { return Array.isArray(payload?.data) ? payload.data : [] }

export const useReferenceDataStore = defineStore('referenceData', () => {
  const catalogs = ref([])
  const items = ref([])
  const selectedCatalogId = ref(null)
  const loading = ref(false)
  const itemsLoading = ref(false)
  const saving = ref(false)
  const error = ref('')

  const selectedCatalog = computed(() => catalogs.value.find((catalog) => Number(catalog.id) === Number(selectedCatalogId.value)) || null)
  const activeItemsCount = computed(() => items.value.filter((item) => item.is_active).length)

  async function loadCatalogs() {
    loading.value = true
    error.value = ''
    try {
      catalogs.value = rows(await api.list('admin/reference/catalogs'))
      if (!selectedCatalogId.value && catalogs.value[0]) selectedCatalogId.value = catalogs.value[0].id
      if (selectedCatalogId.value) await loadItems(selectedCatalogId.value)
    } catch (err) {
      error.value = err.message || 'Не удалось загрузить справочники'
    } finally {
      loading.value = false
    }
  }

  async function loadItems(catalogId = selectedCatalogId.value) {
    if (!catalogId) {
      items.value = []
      return
    }
    itemsLoading.value = true
    error.value = ''
    try {
      selectedCatalogId.value = catalogId
      items.value = rows(await api.listAll('admin/reference/items', { catalog_id: catalogId }))
    } catch (err) {
      error.value = err.message || 'Не удалось загрузить элементы справочника'
    } finally {
      itemsLoading.value = false
    }
  }

  async function saveCatalog(data, id = null) {
    saving.value = true
    try {
      if (id) await api.update('admin/reference/catalogs', id, data)
      else await api.create('admin/reference/catalogs', data)
      await loadCatalogs()
    } finally {
      saving.value = false
    }
  }

  async function deleteCatalog(id) {
    saving.value = true
    try {
      await api.delete('admin/reference/catalogs', id)
      if (Number(selectedCatalogId.value) === Number(id)) selectedCatalogId.value = null
      await loadCatalogs()
    } finally {
      saving.value = false
    }
  }

  async function saveItem(data, id = null) {
    saving.value = true
    try {
      if (id) await api.update('admin/reference/items', id, data)
      else await api.create('admin/reference/items', data)
      await loadItems(data.catalog_id || selectedCatalogId.value)
      await loadCatalogs()
    } finally {
      saving.value = false
    }
  }

  async function deleteItem(id) {
    saving.value = true
    try {
      await api.delete('admin/reference/items', id)
      await loadItems()
      await loadCatalogs()
    } finally {
      saving.value = false
    }
  }

  async function toggleItem(item) {
    await saveItem({
      catalog_id: item.catalog_id,
      code: item.code,
      name: item.name,
      sort_order: item.sort_order,
      is_active: !item.is_active,
      metadata: item.metadata || null,
    }, item.id)
  }

  return {
    catalogs,
    items,
    selectedCatalogId,
    selectedCatalog,
    activeItemsCount,
    loading,
    itemsLoading,
    saving,
    error,
    loadCatalogs,
    loadItems,
    saveCatalog,
    deleteCatalog,
    saveItem,
    deleteItem,
    toggleItem,
  }
})
