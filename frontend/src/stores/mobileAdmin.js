import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'
import { loadAdminInbox } from '../services/adminInbox'
import { useAuthStore } from './auth'

function extractData(payload) { return payload?.data || {} }

export const useMobileAdminStore = defineStore('mobileAdmin', () => {
  const counts = ref({ students: 0, teachers: 0, groups: 0, users: 0 })
  const today = ref(null)
  const pending = ref({})
  const abilities = ref({})
  const inbox = ref([])
  const loading = ref(false)
  const error = ref('')
  const deciding = ref(null)

  const search = ref('')
  const people = ref([])
  const searching = ref(false)
  const searched = ref(false)

  const journalRequests = computed(() => inbox.value.filter((item) => item.kind === 'journal_edit_request'))
  const otherInbox = computed(() => inbox.value.filter((item) => item.kind !== 'journal_edit_request'))
  const inboxTotal = computed(() => inbox.value.length)

  async function load() {
    const auth = useAuthStore()
    loading.value = true
    error.value = ''
    try {
      const [payload, items] = await Promise.all([
        api.list('mobile/admin').then(extractData),
        // Тот же сборщик, что у колокольчика на десктопе. Отказ одного источника
        // не должен ронять весь кабинет: показатели важнее списка.
        loadAdminInbox(auth).catch(() => []),
      ])
      counts.value = payload.counts || counts.value
      today.value = payload.today || null
      pending.value = payload.pending || {}
      abilities.value = payload.abilities || {}
      inbox.value = items
    } catch (err) {
      error.value = err.message || 'Не удалось загрузить кабинет администратора'
    } finally {
      loading.value = false
    }
  }

  /**
   * Решение по запросу на переоткрытие журнала — существующим маршрутом
   * журнала. Своего пути записи кабинет не заводит: право и последствия у
   * решения одни и те же, с телефона оно или с настольного экрана.
   */
  async function decideJournalRequest(requestId, approved, comment = '') {
    deciding.value = requestId
    error.value = ''
    try {
      await api.post(`journal/edit-requests/${requestId}/review`, { approved, comment: comment || null })
      await load()
      return true
    } catch (err) {
      error.value = err.message || 'Не удалось отправить решение'
      return false
    } finally {
      deciding.value = null
    }
  }

  async function findPeople() {
    const query = search.value.trim()
    if (query.length < 2) {
      people.value = []
      searched.value = false
      return
    }

    searching.value = true
    try {
      const payload = await api.list('people', { search: query, per_page: 20 })
      people.value = payload?.data || []
      searched.value = true
    } catch (err) {
      people.value = []
      error.value = err.message || 'Поиск не выполнен'
    } finally {
      searching.value = false
    }
  }

  return {
    counts,
    today,
    pending,
    abilities,
    inbox,
    loading,
    error,
    deciding,
    search,
    people,
    searching,
    searched,
    journalRequests,
    otherInbox,
    inboxTotal,
    load,
    decideJournalRequest,
    findPeople,
  }
})
