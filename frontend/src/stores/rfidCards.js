import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'

/**
 * RFID-карты коменданта.
 *
 * Выдача и приём — отдельные обращения, а не правка поля: портал записывает,
 * кому и когда, иначе учёт ничем не отличается от списка.
 */
export const useRfidCardsStore = defineStore('rfidCards', () => {
  const cards = ref([])
  const people = ref([])
  const loading = ref(false)
  const saving = ref(false)
  const error = ref('')
  const filters = ref({ status: '', search: '' })

  const STATUS_LABELS = {
    stock: 'На складе',
    issued: 'На руках',
    lost: 'Утеряна',
    blocked: 'Заблокирована',
    written_off: 'Списана',
  }

  const statusOptions = computed(() => Object.entries(STATUS_LABELS)
    .map(([value, label]) => ({ value, label })))

  const counts = computed(() => cards.value.reduce((totals, card) => {
    totals[card.status] = (totals[card.status] || 0) + 1
    return totals
  }, {}))

  const peopleOptions = computed(() => people.value.map((person) => ({
    value: person.id,
    label: person.full_name || `Человек #${person.id}`,
  })))

  function rows(payload) {
    return Array.isArray(payload?.data) ? payload.data : []
  }

  async function load() {
    loading.value = true
    error.value = ''
    try {
      const query = { per_page: 200 }
      if (filters.value.status) query.status = filters.value.status
      if (filters.value.search) query.search = filters.value.search

      // Список людей нужен, чтобы выбрать, кому выдать карту. Просим с запасом:
      // выпадающий список по умолчанию отдаёт тридцать строк, а людей больше.
      const [cardsPayload, peoplePayload] = await Promise.all([
        api.list('rfid-cards', query),
        api.list('people', { per_page: 200 }),
      ])

      cards.value = rows(cardsPayload)
      people.value = rows(peoplePayload)
    } catch (err) {
      error.value = err.message || 'Не удалось загрузить карты'
    } finally {
      loading.value = false
    }
  }

  async function call(action) {
    saving.value = true
    error.value = ''
    try {
      await action()
      await load()
      return true
    } catch (err) {
      error.value = err.message || 'Не удалось выполнить действие'
      return false
    } finally {
      saving.value = false
    }
  }

  const create = (payload) => call(() => api.create('rfid-cards', payload))
  const issue = (card, personId, note) => call(() => api.create(`rfid-cards/${card.id}/issue`, { person_id: personId, note: note || null }))
  const accept = (card, note) => call(() => api.create(`rfid-cards/${card.id}/accept`, { note: note || null }))
  const changeStatus = (card, status, note) => call(() => api.create(`rfid-cards/${card.id}/status`, { status, note: note || null }))

  return {
    cards, people, loading, saving, error, filters,
    statusOptions, statusLabels: STATUS_LABELS, counts, peopleOptions,
    load, create, issue, accept, changeStatus,
  }
})
