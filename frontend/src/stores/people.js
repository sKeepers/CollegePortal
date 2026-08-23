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
  const saving = ref(false)
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

  // Карточка человека — единственное место, где общее поле можно очистить: профильные
  // карточки видят человека не целиком и пустое поле в них значит «не менять».
  async function savePerson(id, payload) {
    saving.value = true
    error.value = ''
    try {
      const updated = (await api.update('people', id, payload))?.data || null
      if (updated) {
        selectedPerson.value = updated
        const index = people.value.findIndex((person) => Number(person.id) === Number(id))
        if (index !== -1) people.value.splice(index, 1, { ...people.value[index], ...updated })
      }
      return updated
    } catch (err) {
      error.value = err.message || 'Не удалось сохранить карточку человека'
      throw err
    } finally {
      saving.value = false
    }
  }

  /**
   * Кандидаты на слияние: люди, похожие на выбранного.
   *
   * Ручка `people/duplicates/check` для этого и сделана, но из портала её не
   * звали ниоткуда — находка аудита 23.08.2026. Себя из ответа убираем: сам с
   * собой человек не сливается.
   */
  async function mergeCandidates(person) {
    if (!person?.id) return []

    const payload = await api.create('people/duplicates/check', {
      last_name: person.last_name || '',
      first_name: person.first_name || '',
      middle_name: person.middle_name || '',
      birth_date: person.birth_date || null,
      snils: person.snils || '',
      email: person.email || '',
      phone: person.phone || '',
    })

    return (payload?.data?.matches || [])
      .map((match) => match.person)
      .filter((candidate) => candidate && Number(candidate.id) !== Number(person.id))
  }

  /** Разбор перед слиянием: что переедет, что дозаполнится и что мешает. */
  async function mergePreview(survivorId, absorbedId) {
    const payload = await api.create('people/merge/preview', { survivor_id: survivorId, absorbed_id: absorbedId })

    return payload?.data || { moves: [], fills: [], blockers: [] }
  }

  /** Слияние. Обратного хода нет: присоединённая карточка исчезает. */
  async function mergePeople(survivorId, absorbedId) {
    saving.value = true
    error.value = ''
    try {
      const payload = await api.create('people/merge', { survivor_id: survivorId, absorbed_id: absorbedId })
      await load()
      await loadPerson(survivorId)

      return payload
    } catch (err) {
      error.value = err.message || 'Не удалось объединить карточки'
      throw err
    } finally {
      saving.value = false
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

  return { people, filters, pagination, selectedId, selectedPerson, selected, loading, detailsLoading, saving, error, load, loadPerson, savePerson, select, resetFilters, mergeCandidates, mergePreview, mergePeople }
})
