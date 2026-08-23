import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'

/**
 * Общежитие: места, заселение, отлучки и ночные отсутствия.
 *
 * Порядок работы коменданта тот же, что в жизни: сначала есть комнаты, потом в
 * них заселяют, потом считаются ночи. Поэтому и вкладки идут так, и загрузка
 * каждой вкладки своя — списки заселений и ночей длинные, тянуть их вместе с
 * комнатами незачем.
 */
export const useDormStore = defineStore('dorm', () => {
  const rooms = ref([])
  const placements = ref([])
  const leaves = ref([])
  const absences = ref([])
  const payments = ref([])
  const incidents = ref([])
  const today = ref(null)
  const studentPayments = ref([])
  const students = ref([])

  const loading = ref(false)
  const saving = ref(false)
  const searching = ref(false)
  const error = ref('')

  const roomFilters = ref({ only_free: false, is_active: null })
  const placementFilters = ref({ dorm_room_id: null, open: true })
  const nightFilters = ref({ from: '', to: '' })

  const ROOM_KINDS = {
    regular: 'Обычная',
    isolation: 'Изолятор',
    service: 'Служебная',
  }

  const kindOptions = computed(() => Object.entries(ROOM_KINDS).map(([value, label]) => ({ value, label })))

  const roomOptions = computed(() => rooms.value.map((room) => ({
    value: room.id,
    label: room.floor
      ? `№ ${room.number} (${room.floor} этаж, свободно ${room.free ?? '?'})`
      : `№ ${room.number} (свободно ${room.free ?? '?'})`,
  })))

  const studentOptions = computed(() => students.value.map((student) => ({
    value: student.id,
    label: student.group?.name ? `${student.full_name} — ${student.group.name}` : student.full_name,
  })))

  const roomTotals = computed(() => rooms.value.reduce((totals, room) => ({
    capacity: totals.capacity + (room.capacity || 0),
    occupied: totals.occupied + (room.occupied || 0),
    free: totals.free + (room.free || 0),
  }), { capacity: 0, occupied: 0, free: 0 }))

  function rows(payload) {
    return Array.isArray(payload?.data) ? payload.data : []
  }

  function fail(err, fallback) {
    error.value = err?.message || fallback

    return false
  }

  async function loadRooms() {
    loading.value = true
    error.value = ''
    try {
      const query = { per_page: 200 }
      if (roomFilters.value.only_free) query.only_free = 1
      if (roomFilters.value.is_active !== null) query.is_active = roomFilters.value.is_active ? 1 : 0

      rooms.value = rows(await api.list('dorm/rooms', query))
    } catch (err) {
      fail(err, 'Не удалось загрузить комнаты')
    } finally {
      loading.value = false
    }
  }

  async function loadPlacements() {
    loading.value = true
    error.value = ''
    try {
      const query = { per_page: 300 }
      if (placementFilters.value.dorm_room_id) query.dorm_room_id = placementFilters.value.dorm_room_id
      if (placementFilters.value.open !== null) query.open = placementFilters.value.open ? 1 : 0

      placements.value = rows(await api.list('dorm/placements', query))
    } catch (err) {
      fail(err, 'Не удалось загрузить заселения')
    } finally {
      loading.value = false
    }
  }

  /**
   * Сводка по оплате: кто по какое число закрыт и на сколько просрочил.
   *
   * Экран показывает именно её, а не список отметок: коменданту важно «кто
   * должен», а отметки смотрят потом, по конкретному человеку.
   */
  async function loadPayments() {
    loading.value = true
    error.value = ''
    try {
      const payload = await api.list('dorm/payments/summary')
      payments.value = Array.isArray(payload?.data) ? payload.data : []
    } catch (err) {
      fail(err, 'Не удалось загрузить сводку по оплате')
    } finally {
      loading.value = false
    }
  }

  async function loadStudentPayments(studentId) {
    try {
      studentPayments.value = rows(await api.list('dorm/payments', { student_id: studentId, per_page: 100 }))
    } catch (err) {
      fail(err, 'Не удалось загрузить отметки об оплате')
    }
  }

  /**
   * Происшествия: драка, потоп, кража.
   *
   * Общая часть двух контуров — видят и ведут обе роли.
   */
  /** Сводка «что сегодня» — с чего начать день. */
  async function loadToday() {
    loading.value = true
    error.value = ''
    try {
      const payload = await api.list('dorm/today')
      today.value = payload?.data || null
    } catch (err) {
      fail(err, 'Не удалось загрузить сводку')
    } finally {
      loading.value = false
    }
  }

  async function loadIncidents() {
    loading.value = true
    error.value = ''
    try {
      const query = { per_page: 200 }
      if (nightFilters.value.from) query.from = nightFilters.value.from
      if (nightFilters.value.to) query.to = nightFilters.value.to

      incidents.value = rows(await api.list('dorm/incidents', query))
    } catch (err) {
      fail(err, 'Не удалось загрузить происшествия')
    } finally {
      loading.value = false
    }
  }

  async function loadLeaves() {
    loading.value = true
    error.value = ''
    try {
      const query = { per_page: 300 }
      if (nightFilters.value.from) query.from = nightFilters.value.from
      if (nightFilters.value.to) query.to = nightFilters.value.to

      leaves.value = rows(await api.list('dorm/leaves', query))
    } catch (err) {
      fail(err, 'Не удалось загрузить отлучки')
    } finally {
      loading.value = false
    }
  }

  async function loadAbsences() {
    loading.value = true
    error.value = ''
    try {
      const query = { per_page: 300 }
      if (nightFilters.value.from) query.from = nightFilters.value.from
      if (nightFilters.value.to) query.to = nightFilters.value.to

      absences.value = rows(await api.list('dorm/absences', query))
    } catch (err) {
      fail(err, 'Не удалось загрузить ночные отсутствия')
    } finally {
      loading.value = false
    }
  }

  /** Поиск студента для заселения и отлучки. */
  async function searchStudents(query) {
    const search = (query || '').trim()
    if (search.length < 2) {
      students.value = []

      return
    }

    searching.value = true
    try {
      const payload = await api.list('students', { search, per_page: 25 })
      students.value = rows(payload).map((student) => ({
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
      const result = await action()
      if (reload) await reload()

      return result ?? true
    } catch (err) {
      return fail(err, 'Не удалось выполнить действие')
    } finally {
      saving.value = false
    }
  }

  const createRoom = (payload) => act(() => api.create('dorm/rooms', payload), loadRooms)
  const updateRoom = (room, payload) => act(() => api.update('dorm/rooms', room.id, payload), loadRooms)

  const place = (payload) => act(() => api.create('dorm/placements', payload), async () => {
    await loadPlacements()
    await loadRooms()
  })

  const relocate = (payload) => act(() => api.create('dorm/placements/relocate', payload), async () => {
    await loadPlacements()
    await loadRooms()
  })

  const moveOut = (payload) => act(() => api.create('dorm/placements/move-out', payload), async () => {
    await loadPlacements()
    await loadRooms()
  })

  const createLeave = (payload) => act(() => api.create('dorm/leaves', payload), loadLeaves)
  const removeLeave = (leave) => act(() => api.delete('dorm/leaves', leave.id), loadLeaves)

  const recalculate = (night) => act(() => api.create('dorm/absences/recalculate', { night }), loadAbsences)
  const recordPayment = (payload) => act(() => api.create('dorm/payments', payload), loadPayments)
  const recordIncident = (payload) => act(() => api.create('dorm/incidents', payload), loadIncidents)
  const updateIncident = (incident, payload) => act(() => api.update('dorm/incidents', incident.id, payload), loadIncidents)

  return {
    rooms, placements, leaves, absences, payments, studentPayments, incidents, today, students,
    loading, saving, searching, error,
    roomFilters, placementFilters, nightFilters,
    kindOptions, roomOptions, studentOptions, roomTotals, roomKinds: ROOM_KINDS,
    loadRooms, loadPlacements, loadLeaves, loadAbsences, loadPayments, loadStudentPayments, loadIncidents, loadToday, searchStudents,
    createRoom, updateRoom, place, relocate, moveOut, createLeave, removeLeave, recalculate, recordPayment, recordIncident, updateIncident,
  }
})
