import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'
import { buildGroupOptions } from '../utils/groupOptions'

/**
 * RFID-карты: рабочее место коменданта и отдела кадров.
 *
 * Работа идёт от двух вещей: от человека (пришёл за картой) и от карты на
 * считывателе (пришёл сдать). Поэтому здесь два входа — поиск по фамилии и
 * разбор номера, — а список карт и журнал выдач идут следом.
 *
 * Выдача, приём и блокировка — отдельные обращения, а не правка поля: портал
 * записывает, кому и когда, иначе учёт ничем не отличается от списка.
 */
export const useRfidCardsStore = defineStore('rfidCards', () => {
  const cards = ref([])
  const journal = ref([])
  const groups = ref([])
  const foundPeople = ref([])
  const person = ref(null)
  const unknownCard = ref('')
  const loading = ref(false)
  const searching = ref(false)
  const saving = ref(false)
  const error = ref('')
  const filters = ref({ status: '', search: '' })
  const journalFilters = ref({ from: '', to: '', group_id: null, reason: '', open: null })

  /**
   * Отбор, по которому пришли строки, лежащие в `journal` **сейчас**.
   *
   * Отдельно от `journalFilters` потому, что это разные вещи: в полях лежит то,
   * что человек набрал, а здесь — то, по чему сервер отобрал. Между ними всегда
   * есть промежуток: выбрал группу, «Показать» не нажал.
   *
   * Печать обязана подписываться **этим**. Замерено 29.08.2026: с живым отбором
   * лист выходил под заголовком «Ведомость выдачи RFID-карт — Театральное
   * творчество…» и нёс 245 строк — все выданные карты колледжа. Это тот же
   * дефект, что чинился 24.08, когда ведомость подписала пятерых живых людей
   * чужим заголовком; причина другая, а неправда на бумаге та же.
   *
   * `null` — журнал ещё ни разу не грузили; печатать в этом состоянии нечего,
   * и кнопка выключена пустым `journal`.
   */
  const journalApplied = ref(null)

  const STATUS_LABELS = {
    stock: 'На складе',
    issued: 'На руках',
    lost: 'Утеряна',
    blocked: 'Заблокирована',
    written_off: 'Списана',
  }

  const REASON_LABELS = {
    returned: 'Сдана',
    lost: 'Утеряна',
    damaged: 'Испорчена',
    replaced: 'Заменена',
    left: 'Человек выбыл',
  }

  const statusOptions = computed(() => Object.entries(STATUS_LABELS).map(([value, label]) => ({ value, label })))
  const reasonOptions = computed(() => Object.entries(REASON_LABELS).map(([value, label]) => ({ value, label })))

  const groupOptions = computed(() => buildGroupOptions(groups.value))

  const counts = computed(() => cards.value.reduce((totals, card) => {
    totals[card.status] = (totals[card.status] || 0) + 1
    return totals
  }, {}))

  function rows(payload) {
    return Array.isArray(payload?.data) ? payload.data : []
  }

  function fail(err, fallback) {
    error.value = err?.message || fallback
    return false
  }

  async function load() {
    loading.value = true
    error.value = ''
    try {
      // Без `per_page`: `listAll` дочитывает список целиком и переданный
      // размер намеренно игнорирует. Пока он здесь стоял, число читалось
      // как действующее ограничение, хотя не значило ничего.
      const query = {}
      if (filters.value.status) query.status = filters.value.status
      if (filters.value.search) query.search = filters.value.search

      cards.value = rows(await api.listAll('rfid-cards', query))
    } catch (err) {
      fail(err, 'Не удалось загрузить карты')
    } finally {
      loading.value = false
    }
  }

  async function loadGroups() {
    if (groups.value.length) return
    try {
      groups.value = rows(await api.list('rfid-cards/groups'))
    } catch (err) {
      fail(err, 'Не удалось загрузить группы')
    }
  }

  async function loadJournal() {
    loading.value = true
    error.value = ''
    try {
      const asked = { ...journalFilters.value }
      journal.value = rows(await api.list('rfid-cards/journal', { ...journalQuery(), per_page: 500 }))
      // Снимок ставится только после удачного ответа: если загрузка отказала,
      // на экране остаются прежние строки, и подписывать их новым отбором
      // нельзя.
      journalApplied.value = asked
    } catch (err) {
      fail(err, 'Не удалось загрузить журнал')
    } finally {
      loading.value = false
    }
  }

  /** Отбор строк журнала — общий для экрана, печати и выгрузки. */
  function journalQuery() {
    const query = {}
    const { from, to, group_id: groupId, reason, open } = journalFilters.value
    if (from) query.from = from
    if (to) query.to = to
    if (groupId) query.group_id = groupId
    if (reason) query.reason = reason
    // Единицей и нулём, а не словами: проверка на сервере ждёт булево, а
    // строка «true» ей не булево — фильтр падал с `validation.boolean`.
    if (open !== null && open !== '') query.open = open ? 1 : 0

    return query
  }

  /** Тот же журнал книгой Excel. */
  async function exportJournalFile() {
    saving.value = true
    error.value = ''
    try {
      const query = new URLSearchParams(journalQuery()).toString()

      return await api.download(`/rfid-cards/journal/export${query ? '?'+query : ''}`)
    } catch (err) {
      fail(err, 'Не удалось выгрузить журнал')

      return null
    } finally {
      saving.value = false
    }
  }

  /**
   * Загрузка журнала из файла: сначала проверка без записи, потом запись.
   *
   * Механизм общий с разделом «Импорт данных» — второй реализации нет, иначе
   * два пути разошлись бы поведением.
   */
  async function previewJournalImport(file) {
    saving.value = true
    error.value = ''
    try {
      const form = new FormData()
      form.append('file', file)

      const payload = await api.upload('/rfid-cards/journal/import', form)

      return payload?.data || null
    } catch (err) {
      fail(err, 'Не удалось разобрать файл журнала')

      return null
    } finally {
      saving.value = false
    }
  }

  async function confirmJournalImport(jobId, mode = 'skip_duplicates') {
    saving.value = true
    error.value = ''
    try {
      const payload = await api.create(`rfid-cards/journal/import/${jobId}/confirm`, { mode })
      await loadJournal()
      await load()

      return payload?.data || null
    } catch (err) {
      fail(err, 'Не удалось загрузить журнал')

      return null
    } finally {
      saving.value = false
    }
  }

  /** Поиск человека по фамилии — первый из двух входов в работу. */
  async function searchPeople(query) {
    const search = (query || '').trim()
    if (search.length < 2) {
      foundPeople.value = []
      return
    }

    searching.value = true
    error.value = ''
    try {
      foundPeople.value = rows(await api.list('rfid-cards/people', { search, limit: 25 }))
    } catch (err) {
      fail(err, 'Не удалось найти человека')
    } finally {
      searching.value = false
    }
  }

  function selectPerson(found) {
    person.value = found
    unknownCard.value = ''
    foundPeople.value = []
  }

  function clearPerson() {
    person.value = null
    unknownCard.value = ''
  }

  async function refreshPerson() {
    if (!person.value) return
    try {
      const found = rows(await api.list('rfid-cards/people', { person_id: person.value.id }))
      if (found.length) person.value = found[0]
    } catch (err) {
      fail(err, 'Не удалось обновить карточку человека')
    }
  }

  /**
   * Разбор номера со считывателя — второй вход в работу.
   *
   * Известная карта открывает своего владельца, незнакомая запоминается: её
   * сейчас выдадут тому, кого найдут по фамилии.
   */
  async function lookup(uid) {
    const value = (uid || '').trim()
    if (!value) return null

    saving.value = true
    error.value = ''
    try {
      const result = await api.list('rfid-cards/lookup', { uid: value })

      if (result?.found && result.person) {
        // Карта у кого-то на руках — открываем владельца: это «пришёл сдать».
        person.value = result.person
        unknownCard.value = ''
      } else {
        // Карта свободна или незнакома. Выбранного человека **не трогаем**:
        // это главный порядок работы — сначала нашли фамилию, потом поднесли
        // карту. Пока экран здесь сбрасывал человека, привязать её было нельзя.
        unknownCard.value = result?.uid || value
      }

      return result
    } catch (err) {
      fail(err, 'Не удалось разобрать номер карты')
      return null
    } finally {
      saving.value = false
    }
  }

  async function act(action) {
    saving.value = true
    error.value = ''
    try {
      await action()
      await Promise.all([refreshPerson(), load()])
      return true
    } catch (err) {
      return fail(err, 'Не удалось выполнить действие')
    } finally {
      saving.value = false
    }
  }

  const bind = (personId, uid, label, note) => act(() => api.create('rfid-cards/bind', {
    person_id: personId,
    uid,
    label: label || null,
    note: note || null,
  }))

  const create = (payload) => act(() => api.create('rfid-cards', payload))
  const release = (card, reason, note) => act(() => api.create(`rfid-cards/${card.id}/release`, {
    reason: reason || null,
    note: note || null,
  }))
  const remove = (card) => act(() => api.delete('rfid-cards', card.id))
  const issue = (card, personId, note) => act(() => api.create(`rfid-cards/${card.id}/issue`, { person_id: personId, note: note || null }))
  const accept = (card, note) => act(() => api.create(`rfid-cards/${card.id}/accept`, { note: note || null }))
  const changeStatus = (card, status, note, reason) => act(() => api.create(`rfid-cards/${card.id}/status`, {
    status,
    note: note || null,
    reason: reason || null,
  }))

  return {
    cards, journal, groups, foundPeople, person, unknownCard,
    loading, searching, saving, error, filters, journalFilters, journalApplied,
    statusOptions, reasonOptions, groupOptions, counts,
    statusLabels: STATUS_LABELS, reasonLabels: REASON_LABELS,
    load, loadGroups, loadJournal, exportJournalFile, previewJournalImport, confirmJournalImport,
    searchPeople, selectPerson, clearPerson, refreshPerson,
    lookup, bind, create, issue, accept, release, remove, changeStatus,
  }
})
