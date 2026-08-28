import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'

/**
 * Воспитательная работа: провинности и социальный паспорт.
 *
 * Отдельное хранилище, а не часть общежития, — по той же причине, по которой
 * это отдельный раздел: у контура своё право, выданное ровно одной роли.
 * Смешивать его с работой коменданта нельзя даже в состоянии на экране.
 */
export const useDormUpbringingStore = defineStore('dormUpbringing', () => {
  const conduct = ref([])
  const social = ref([])
  const students = ref([])
  const today = ref(null)

  const loading = ref(false)
  const saving = ref(false)
  const searching = ref(false)
  const error = ref('')

  const conductFilters = ref({ active: true })
  const socialFilters = ref({ category: null, open: true })

  const CATEGORIES = {
    orphan: 'Сирота или без попечения',
    guardianship: 'Под опекой',
    disability: 'Инвалидность или ОВЗ',
    low_income: 'Малоимущая семья',
    large_family: 'Многодетная семья',
    registered: 'На профилактическом учёте',
    difficult: 'Работа с трудным',
    other: 'Иное',
  }

  const categoryOptions = computed(() => Object.entries(CATEGORIES).map(([value, label]) => ({ value, label })))

  const studentOptions = computed(() => students.value.map((student) => ({
    value: student.id,
    label: student.group?.name ? `${student.full_name} — ${student.group.name}` : student.full_name,
  })))

  function rows(payload) {
    return Array.isArray(payload?.data) ? payload.data : []
  }

  function fail(err, fallback) {
    error.value = err?.message || fallback

    return false
  }

  /** Сводка заместителя — своя и другая, не комендантская. */
  async function loadToday() {
    loading.value = true
    error.value = ''
    try {
      const payload = await api.list('dorm/upbringing/today')
      today.value = payload?.data || null
    } catch (err) {
      fail(err, 'Не удалось загрузить сводку')
    } finally {
      loading.value = false
    }
  }

  async function loadConduct() {
    loading.value = true
    error.value = ''
    try {
      const query = { per_page: 200 }
      if (conductFilters.value.active !== null) query.active = conductFilters.value.active ? 1 : 0

      conduct.value = rows(await api.listAll('dorm/conduct', query))
    } catch (err) {
      fail(err, 'Не удалось загрузить записи')
    } finally {
      loading.value = false
    }
  }

  async function loadSocial() {
    loading.value = true
    error.value = ''
    try {
      const query = { per_page: 200 }
      if (socialFilters.value.category) query.category = socialFilters.value.category
      if (socialFilters.value.open !== null) query.open = socialFilters.value.open ? 1 : 0

      social.value = rows(await api.listAll('dorm/social', query))
    } catch (err) {
      fail(err, 'Не удалось загрузить социальный паспорт')
    } finally {
      loading.value = false
    }
  }

  async function searchStudents(query) {
    const search = (query || '').trim()
    if (search.length < 2) {
      students.value = []

      return
    }

    searching.value = true
    try {
      // Подсказка по мере набора: предел здесь осознанный, а не обрезанный
      // список. `listAll` тянул бы всех подходящих на каждое нажатие клавиши.
      students.value = rows(await api.list('students', { search, per_page: 25 })).map((student) => ({
        id: student.id,
        full_name: student.full_name
          || [student.last_name, student.first_name, student.middle_name].filter(Boolean).join(' '),
        group: student.group,
      }))
    } catch (err) {
      fail(err, 'Не удалось найти студента')
    } finally {
      searching.value = false
    }
  }

  async function act(action, reload) {
    saving.value = true
    error.value = ''
    try {
      await action()
      if (reload) await reload()

      return true
    } catch (err) {
      return fail(err, 'Не удалось выполнить действие')
    } finally {
      saving.value = false
    }
  }

  const recordConduct = (payload) => act(() => api.create('dorm/conduct', payload), loadConduct)
  const updateConduct = (record, payload) => act(() => api.update('dorm/conduct', record.id, payload), loadConduct)
  const amendConduct = (record, payload) => act(() => api.create(`dorm/conduct/${record.id}/amend`, payload), loadConduct)

  const recordSocial = (payload) => act(() => api.create('dorm/social', payload), loadSocial)
  const updateSocial = (record, payload) => act(() => api.update('dorm/social', record.id, payload), loadSocial)

  return {
    conduct, social, students, today,
    loading, saving, searching, error,
    conductFilters, socialFilters,
    categoryOptions, studentOptions, categories: CATEGORIES,
    loadConduct, loadSocial, loadToday, searchStudents,
    recordConduct, updateConduct, amendConduct, recordSocial, updateSocial,
  }
})
