import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'

// The registry holds every person, so the list opens without students to stay readable.
// It is a preselected filter the operator can change, not a hidden rule of the section.
const initialFilters = { search: '', profile: 'without_students' }

function rows(payload) { return Array.isArray(payload?.data) ? payload.data : [] }
function meta(payload) { return payload?.meta || null }

export const usePeopleStore = defineStore('people', () => {
  const people = ref([])
  const filters = ref({ ...initialFilters })
  const pagination = ref(null)
  const selectedId = ref(null)
  const selectedPerson = ref(null)
  const loading = ref(false)
  const detailsLoading = ref(false)
  const error = ref('')

  const selected = computed(() => selectedPerson.value || people.value.find((person) => Number(person.id) === Number(selectedId.value)) || null)

  async function load() {
    loading.value = true
    error.value = ''
    try {
      const payload = await api.list('people', filters.value)
      people.value = rows(payload)
      pagination.value = meta(payload)
      if (selectedId.value && !people.value.some((person) => Number(person.id) === Number(selectedId.value))) {
        selectedId.value = null
        selectedPerson.value = null
      }
    } catch (err) {
      error.value = err.message || 'Не удалось загрузить людей'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function loadPerson(id) {
    if (!id) return null
    detailsLoading.value = true
    error.value = ''
    try {
      const payload = await api.list(`people/${id}`)
      selectedPerson.value = payload?.data || null
      selectedId.value = selectedPerson.value?.id || id
      return selectedPerson.value
    } catch (err) {
      error.value = err.message || 'Не удалось загрузить карточку человека'
      throw err
    } finally {
      detailsLoading.value = false
    }
  }

  function select(id) {
    selectedId.value = id ? Number(id) : null
    selectedPerson.value = null
    if (id) return loadPerson(id)
    return Promise.resolve(null)
  }

  function resetFilters() {
    filters.value = { ...initialFilters }
  }

  return { people, filters, pagination, selectedId, selectedPerson, selected, loading, detailsLoading, error, load, loadPerson, select, resetFilters }
})
