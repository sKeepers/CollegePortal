<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useQuasar } from 'quasar'
import { BedDouble, Plus, Printer, RefreshCw } from '@lucide/vue'
import AppPage from '../../components/ui/AppPage.vue'
import PageHeader from '../../components/ui/PageHeader.vue'
import AppToolbar from '../../components/ui/AppToolbar.vue'
import AppTable from '../../components/ui/AppTable.vue'
import AppEmptyState from '../../components/ui/AppEmptyState.vue'
import AppLoading from '../../components/ui/AppLoading.vue'
import AppErrorBanner from '../../components/ui/AppErrorBanner.vue'
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'
import { escapeHtml, printHtmlDocument, printPage } from '../../utils/print'
import { formatPhone } from '../../utils/phone'
import { plural, PLACES, PLACES_OF } from '../../utils/plural'
import { formatDay } from '../../utils/date'
import { useDormStore } from '../../stores/dorm'
import { usePermissions } from '../../composables/usePermissions'

/**
 * Общежитие: места, заселение, отлучки и ночи.
 *
 * Вкладки идут в порядке работы коменданта: сначала есть комнаты, потом в них
 * заселяют, потом считаются ночи. Отлучки стоят перед ночами намеренно —
 * записать отлучку нужно **до** того, как ночь посчитается, иначе уехавший
 * домой попадёт в список не вернувшихся.
 *
 * Провинностей и социального паспорта здесь нет и не будет: у них своё право,
 * которого у коменданта нет.
 */
const store = useDormStore()
const permissions = usePermissions()
const $q = useQuasar()

const canManageRooms = computed(() => permissions.hasPermission('dorm.rooms.manage'))
const canManagePlacements = computed(() => permissions.hasPermission('dorm.placements.manage'))
const canManageLeaves = computed(() => permissions.hasPermission('dorm.leaves.manage'))
// Оплата — работа коменданта. Заместителю по воспитательной работе её не дают,
// и вкладки у него не будет вовсе: это разграничение, а не недоделка.
const canSeeIncidents = computed(() => permissions.hasPermission('dorm.incidents.view'))
const canManageIncidents = computed(() => permissions.hasPermission('dorm.incidents.manage'))
const canSeePayments = computed(() => permissions.hasPermission('dorm.payments.view'))
const canManagePayments = computed(() => permissions.hasPermission('dorm.payments.manage'))

const tab = ref('today')

const roomDialog = ref(false)
const roomForm = reactive({ id: null, number: '', floor: null, capacity: 2, kind: 'regular', is_active: true, note: '' })

const placeDialog = ref(false)
const placeForm = reactive({ mode: 'place', student_id: null, dorm_room_id: null, moved_in_at: today(), basis: '', note: '' })

const moveOutDialog = ref(false)
const moveOutForm = reactive({ student_id: null, student_name: '', moved_out_at: today(), note: '' })

const leaveDialog = ref(false)
const leaveForm = reactive({ student_id: null, starts_on: today(), ends_on: today(), reason: '' })

const recalcNight = ref(yesterday())

const reportFrom = ref(monthAgo())
const reportTo = ref(today())

const incidentDialog = ref(false)
const incidentForm = reactive({ id: null, happened_at: nowLocal(), summary: '', description: '', measures: '', dorm_room_id: null, participants: [] })

const paymentDialog = ref(false)
const paymentForm = reactive({ student_id: null, student_name: '', paid_through: today(), amount: null, paid_at: today(), note: '' })

const roomColumns = [
  { name: 'number', label: 'Комната', field: 'number', align: 'left', sortable: true },
  { name: 'floor', label: 'Этаж', field: 'floor', align: 'left', sortable: true },
  { name: 'capacity', label: 'Мест', field: 'capacity', align: 'left' },
  { name: 'occupied', label: 'Занято', field: 'occupied', align: 'left' },
  { name: 'free', label: 'Свободно', field: 'free', align: 'left', sortable: true },
  { name: 'kind', label: 'Тип', field: 'kind', align: 'left' },
  { name: 'is_active', label: 'В обращении', field: 'is_active', align: 'left' },
  { name: 'actions', label: '', field: 'actions', align: 'right' },
]

const placementColumns = [
  { name: 'student', label: 'Студент', field: 'student', align: 'left' },
  { name: 'group', label: 'Группа', field: 'group', align: 'left' },
  { name: 'room', label: 'Комната', field: 'room', align: 'left' },
  { name: 'moved_in_at', label: 'Заселён', field: 'moved_in_at', align: 'left', sortable: true },
  { name: 'moved_out_at', label: 'Выселен', field: 'moved_out_at', align: 'left' },
  { name: 'actions', label: '', field: 'actions', align: 'right' },
]

const leaveColumns = [
  { name: 'student', label: 'Студент', field: 'student', align: 'left' },
  { name: 'group', label: 'Группа', field: 'group', align: 'left' },
  { name: 'starts_on', label: 'С', field: 'starts_on', align: 'left', sortable: true },
  { name: 'ends_on', label: 'По', field: 'ends_on', align: 'left' },
  { name: 'reason', label: 'Причина', field: 'reason', align: 'left' },
  { name: 'actions', label: '', field: 'actions', align: 'right' },
]

const incidentColumns = [
  { name: 'happened_at', label: 'Когда', field: 'happened_at', align: 'left', sortable: true },
  { name: 'summary', label: 'Что случилось', field: 'summary', align: 'left' },
  { name: 'room', label: 'Комната', field: 'room', align: 'left' },
  { name: 'participants', label: 'Участники', field: 'participants', align: 'left' },
  { name: 'measures', label: 'Меры', field: 'measures', align: 'left' },
  { name: 'created_by', label: 'Записал', field: 'created_by', align: 'left' },
  { name: 'actions', label: '', field: 'actions', align: 'right' },
]

const paymentColumns = [
  { name: 'full_name', label: 'Студент', field: 'full_name', align: 'left', sortable: true },
  { name: 'group', label: 'Группа', field: 'group', align: 'left' },
  { name: 'room', label: 'Комната', field: 'room', align: 'left' },
  { name: 'paid_through', label: 'Оплачено по', field: 'paid_through', align: 'left', sortable: true },
  { name: 'overdue_days', label: 'Состояние', field: 'overdue_days', align: 'left', sortable: true },
  { name: 'actions', label: '', field: 'actions', align: 'right' },
]

const absenceColumns = [
  { name: 'night_of', label: 'Ночь', field: 'night_of', align: 'left', sortable: true },
  { name: 'student', label: 'Студент', field: 'student', align: 'left' },
  { name: 'group', label: 'Группа', field: 'group', align: 'left' },
  { name: 'left_at', label: 'Вышел', field: 'left_at', align: 'left' },
  { name: 'returned_at', label: 'Вернулся', field: 'returned_at', align: 'left' },
]

function today() {
  return new Date().toISOString().slice(0, 10)
}

function nowLocal() {
  const date = new Date()
  date.setMinutes(date.getMinutes() - date.getTimezoneOffset())

  return date.toISOString().slice(0, 16)
}

function monthAgo() {
  const date = new Date()
  date.setDate(date.getDate() - 30)

  return date.toISOString().slice(0, 10)
}

function yesterday() {
  const date = new Date()
  date.setDate(date.getDate() - 1)

  return date.toISOString().slice(0, 10)
}

function formatDate(value) {
  if (!value) return '—'
  const date = new Date(value)

  return Number.isNaN(date.valueOf()) ? String(value) : date.toLocaleDateString('ru-RU')
}

function formatDateTime(value) {
  if (!value) return '—'
  const date = new Date(value)

  return Number.isNaN(date.valueOf())
    ? String(value)
    : date.toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
}

function notify(message, type = 'positive') {
  $q.notify({ type, message, position: 'top-right', timeout: 2500 })
}

function openRoom(room = null) {
  Object.assign(roomForm, room
    ? { id: room.id, number: room.number, floor: room.floor, capacity: room.capacity, kind: room.kind, is_active: room.is_active, note: room.note || '' }
    : { id: null, number: '', floor: null, capacity: 2, kind: 'regular', is_active: true, note: '' })
  roomDialog.value = true
}

async function submitRoom() {
  const payload = {
    number: roomForm.number.trim(),
    floor: roomForm.floor,
    capacity: roomForm.capacity,
    kind: roomForm.kind,
    is_active: roomForm.is_active,
    note: roomForm.note || null,
  }

  const done = roomForm.id
    ? await store.updateRoom({ id: roomForm.id }, payload)
    : await store.createRoom(payload)

  if (done) {
    roomDialog.value = false
    notify(roomForm.id ? 'Комната изменена' : 'Комната заведена')
  }
}

function openPlace(mode, placement = null) {
  Object.assign(placeForm, {
    mode,
    student_id: placement?.student_id ?? null,
    dorm_room_id: null,
    moved_in_at: today(),
    basis: '',
    note: '',
  })

  if (placement) store.students = [{ id: placement.student_id, full_name: placement.student?.full_name, group: { name: placement.student?.group } }]
  placeDialog.value = true
}

async function submitPlace() {
  const payload = {
    student_id: placeForm.student_id,
    dorm_room_id: placeForm.dorm_room_id,
    moved_in_at: placeForm.moved_in_at,
    basis: placeForm.basis || null,
    note: placeForm.note || null,
  }

  const done = placeForm.mode === 'relocate' ? await store.relocate(payload) : await store.place(payload)

  if (done) {
    placeDialog.value = false
    notify(placeForm.mode === 'relocate' ? 'Студент переселён' : 'Студент заселён')
  }
}

function openMoveOut(placement) {
  Object.assign(moveOutForm, {
    student_id: placement.student_id,
    student_name: placement.student?.full_name || '',
    moved_out_at: today(),
    note: '',
  })
  moveOutDialog.value = true
}

async function submitMoveOut() {
  const done = await store.moveOut({
    student_id: moveOutForm.student_id,
    moved_out_at: moveOutForm.moved_out_at,
    note: moveOutForm.note || null,
  })

  if (done) {
    moveOutDialog.value = false
    notify('Студент выселен')
  }
}

function openLeave() {
  Object.assign(leaveForm, { student_id: null, starts_on: today(), ends_on: today(), reason: '' })
  leaveDialog.value = true
}

async function submitLeave() {
  const done = await store.createLeave({
    student_id: leaveForm.student_id,
    starts_on: leaveForm.starts_on,
    ends_on: leaveForm.ends_on,
    reason: leaveForm.reason || null,
  })

  if (done) {
    leaveDialog.value = false
    notify('Отлучка записана')
  }
}

async function removeLeave(leave) {
  if (await store.removeLeave(leave)) notify('Отлучка удалена')
}

async function recalculate() {
  const done = await store.recalculate(recalcNight.value)
  if (done && done !== true) {
    notify(`Ночь пересчитана: не вернулись ${done.data?.counted ?? 0}, в отлучке ${done.data?.skipped_by_leave ?? 0}`)
  }
}

/**
 * Отметка об оплате.
 *
 * С экрана она всегда ручная: строки из 1С приходят обменом, а не отсюда.
 * Иначе ручная отметка смогла бы притвориться победившей в споре источников.
 */
/**
 * Происшествие записывается по горячим следам.
 *
 * Обязательны только время и одна строка. Подробности, участники и меры
 * дописываются потом: потребуй их сразу — запись не появится вовсе.
 */
function openIncident(incident = null) {
  Object.assign(incidentForm, {
    id: incident?.id ?? null,
    happened_at: incident?.happened_at ? incident.happened_at.slice(0, 16) : nowLocal(),
    summary: incident?.summary || '',
    description: incident?.description || '',
    measures: incident?.measures || '',
    dorm_room_id: incident?.dorm_room_id ?? null,
    participants: (incident?.participants || []).map((item) => item.id),
  })

  if (incident?.participants?.length) {
    store.students = incident.participants.map((item) => ({ id: item.id, full_name: item.full_name, group: { name: item.group } }))
  }

  incidentDialog.value = true
}

async function submitIncident() {
  const payload = {
    happened_at: incidentForm.happened_at,
    summary: incidentForm.summary.trim(),
    description: incidentForm.description || null,
    measures: incidentForm.measures || null,
    dorm_room_id: incidentForm.dorm_room_id,
    participants: incidentForm.participants,
  }

  const done = incidentForm.id
    ? await store.updateIncident({ id: incidentForm.id }, payload)
    : await store.recordIncident(payload)

  if (done) {
    incidentDialog.value = false
    notify(incidentForm.id ? 'Происшествие изменено' : 'Происшествие записано')
  }
}

function openPayment(row = null) {
  Object.assign(paymentForm, {
    student_id: row?.student_id ?? null,
    student_name: row?.full_name ?? '',
    paid_through: row?.paid_through || today(),
    amount: null,
    paid_at: today(),
    note: '',
  })

  if (row) store.students = [{ id: row.student_id, full_name: row.full_name, group: { name: row.group } }]
  paymentDialog.value = true
}

async function submitPayment() {
  const done = await store.recordPayment({
    student_id: paymentForm.student_id,
    paid_through: paymentForm.paid_through,
    amount: paymentForm.amount,
    paid_at: paymentForm.paid_at || null,
    note: paymentForm.note || null,
  })

  if (done) {
    paymentDialog.value = false
    notify('Оплата отмечена')
  }
}

/**
 * Список проживающих на бумагу — тот, что вывешивают по этажам.
 *
 * Печатается отдельным документом: каскад приложения до него не достаёт.
 * Правило оплачено пустым листом ведомости выдачи карт.
 */
function printResidents() {
  const data = store.residents
  if (!data) return

  const body = data.floors.map((floor) => {
    const rows = floor.rooms.map((room) => {
      if (!room.people.length) {
        return `<tr><td>${escapeHtml(room.number)}</td><td colspan="4">свободна (${plural(room.capacity, PLACES)})</td></tr>`
      }

      // Номер комнаты стоит в **каждой** строке, а не только у первого жильца.
      // Раньше у соседей ячейка была пустой ради вида — и это беда с отложенным
      // сроком: пока список влезает на страницу, никто не замечает, а разрыв
      // страницы между жильцами одной комнаты уносит человека на следующий лист
      // без комнаты. Лист вешают на дверь, разбираться с ним будут без нас.
      const people = room.people.map((person) => `
        <tr>
          <td>${escapeHtml(room.number)}</td>
          <td>${escapeHtml(person.full_name)}</td>
          <td>${escapeHtml(person.course ? person.course + ' курс' : '')}</td>
          <td>${escapeHtml(person.group || '')}</td>
          <td>${escapeHtml(formatPhone(person.phone, ''))}</td>
        </tr>`).join('')

      // Сколько в комнате осталось мест — единственный вопрос, ради которого
      // этот список печатают: по нему заселяют. Пустая комната о своих местах
      // говорила, а полузанятая молчала, хотя именно она и нужна.
      const free = Math.max(0, Number(room.capacity ?? 0) - (Number(room.occupied ?? room.people.length) || 0))

      return people + (free > 0
        ? `<tr><td>${escapeHtml(room.number)}</td><td colspan="4">свободно ещё ${plural(free, PLACES)}</td></tr>`
        : '')
    }).join('')

    return `
      <h2>${floor.floor ? floor.floor + ' этаж' : 'Без этажа'} — занято ${floor.occupied} из ${floor.capacity}</h2>
      <table>
        <thead><tr><th>Комната</th><th>Фамилия, имя, отчество</th><th>Курс</th><th>Группа</th><th>Телефон</th></tr></thead>
        <tbody>${rows}</tbody>
      </table>`
  }).join('')

  printHtmlDocument(printPage({
    title: 'Список проживающих в общежитии',
    subtitle: `Занято ${data.occupied} из ${plural(data.capacity, PLACES_OF)}. Составлен ${new Date().toLocaleString('ru-RU')}`,
    body,
  }))
}

/** Лист на дверь одной комнаты — книжный, крупным шрифтом. */
function printRoomSheet(room) {
  const rows = room.people.length
    ? room.people.map((person) => `
        <tr>
          <td>${escapeHtml(person.full_name)}</td>
          <td>${escapeHtml(person.course ? person.course + ' курс' : '')}</td>
          <td>${escapeHtml(person.group || '')}</td>
        </tr>`).join('')
    : '<tr><td colspan="3">Комната свободна</td></tr>'

  printHtmlDocument(printPage({
    title: `Комната № ${room.number}`,
    // Дата здесь важнее, чем на любом другом листе портала: этот висит на
    // двери месяцами, жильцы за это время меняются, и отличить сегодняшний
    // от прошлогоднего было нечем — даты не стояло вообще. Время не пишем:
    // лист живёт днями, а час его составления никому не нужен и только
    // сбивал бы с толку рядом с числом.
    subtitle: `Мест ${room.capacity}, занято ${room.occupied}. Составлен ${new Date().toLocaleDateString('ru-RU')}`,
    landscape: false,
    body: `<table style="font-size:15px">
      <thead><tr><th>Фамилия, имя, отчество</th><th>Курс</th><th>Группа</th></tr></thead>
      <tbody>${rows}</tbody>
    </table>`,
  }))
}

function printOccupancy() {
  const data = store.occupancy
  if (!data) return

  const floors = data.floors.map((floor) => `
    <tr>
      <td>${escapeHtml(floor.floor ? floor.floor + ' этаж' : 'Без этажа')}</td>
      <td>${floor.rooms}</td><td>${floor.capacity}</td><td>${floor.occupied}</td><td>${floor.free}</td>
    </tr>`).join('')

  // Итог по этажам складывался читателем: три строки по 17 мест, а сколько
  // всего — считай сам. Числа берутся из сводки отчёта, а не из суммы строк
  // на экране: если они когда-нибудь разойдутся, это надо увидеть на листе,
  // а не спрятать сложением.
  const roomsTotal = data.floors.reduce((sum, floor) => sum + Number(floor.rooms ?? 0), 0)
  const totals = `
    <tr>
      <td><strong>Всего</strong></td>
      <td><strong>${roomsTotal}</strong></td>
      <td><strong>${data.totals.capacity}</strong></td>
      <td><strong>${data.totals.occupied}</strong></td>
      <td><strong>${data.totals.free}</strong></td>
    </tr>`

  const movement = data.movement.length
    ? data.movement.map((row) => `
        <tr>
          <td>${escapeHtml(formatDay(row.date))}</td>
          <td>${row.kind === 'in' ? 'въехал' : 'выехал'}</td>
          <td>${escapeHtml(row.full_name)}</td>
          <td>${escapeHtml(row.group || '')}</td>
          <td>${escapeHtml(row.room || '')}</td>
        </tr>`).join('')
    : '<tr><td colspan="5">За период движения не было</td></tr>'

  printHtmlDocument(printPage({
    title: 'Заселённость общежития',
    subtitle: `За период ${formatDay(data.from)} — ${formatDay(data.to)}. Въехали ${data.totals.moved_in}, выехали ${data.totals.moved_out}`,
    body: `
      <h2>По этажам</h2>
      <table>
        <thead><tr><th>Этаж</th><th>Комнат</th><th>Мест</th><th>Занято</th><th>Свободно</th></tr></thead>
        <tbody>${floors}</tbody>
        <tfoot>${totals}</tfoot>
      </table>
      <h2>Движение за период</h2>
      <table>
        <thead><tr><th>Дата</th><th>Что</th><th>Фамилия, имя, отчество</th><th>Группа</th><th>Комната</th></tr></thead>
        <tbody>${movement}</tbody>
      </table>`,
    footer: `Составлен ${new Date().toLocaleString('ru-RU')}`,
  }))
}

async function openTab(name) {
  tab.value = name
  if (name === 'today') await store.loadToday()
  if (name === 'rooms') await store.loadRooms()
  if (name === 'placements') {
    await store.loadPlacements()
    if (!store.rooms.length) await store.loadRooms()
  }
  if (name === 'leaves') await store.loadLeaves()
  if (name === 'nights') await store.loadAbsences()
  if (name === 'payments') await store.loadPayments()
  if (name === 'reports') await store.loadResidents()
  if (name === 'incidents') {
    await store.loadIncidents()
    if (!store.rooms.length) await store.loadRooms()
  }
}

onMounted(() => store.loadToday())
</script>

<template>
  <AppPage>
    <PageHeader title="Общежитие" subtitle="Места, заселение, отлучки и ночи">
      <template #icon><BedDouble :size="22" /></template>
    </PageHeader>

    <AppErrorBanner v-if="store.error" :message="store.error" />

    <q-tabs :model-value="tab" dense no-caps align="left" class="dorm-tabs" @update:model-value="openTab">
      <q-tab name="today" label="Сегодня" />
      <q-tab name="rooms" label="Места" />
      <q-tab name="placements" label="Заселение" />
      <q-tab name="leaves" label="Отлучки" />
      <q-tab name="nights" label="Ночные отсутствия" />
      <q-tab v-if="canSeeIncidents" name="incidents" label="Происшествия" />
      <q-tab v-if="canSeePayments" name="payments" label="Оплата" />
      <q-tab name="reports" label="Списки и отчёты" />
    </q-tabs>

    <q-tab-panels :model-value="tab" animated class="dorm-panels">
      <!-- Сводка: с чего начать день -->
      <q-tab-panel name="today" class="q-pa-none">
        <AppToolbar>
          <q-btn flat no-caps :disable="store.loading" @click="store.loadToday">
            <RefreshCw :size="16" class="q-mr-xs" /> Обновить
          </q-btn>
        </AppToolbar>

        <AppLoading v-if="store.loading" />
        <template v-else-if="store.today">
          <div class="dorm-counters">
            <div class="dorm-counter"><span class="dorm-counter__value">{{ store.today.places?.free ?? '—' }}</span><span class="dorm-counter__label">Свободных мест</span></div>
            <div class="dorm-counter"><span class="dorm-counter__value">{{ store.today.places?.occupied ?? '—' }}</span><span class="dorm-counter__label">Занято</span></div>
            <div class="dorm-counter"><span class="dorm-counter__value">{{ store.today.places?.rooms ?? '—' }}</span><span class="dorm-counter__label">Комнат</span></div>
          </div>

          <div v-if="store.today.night" class="dorm-block">
            <div class="dorm-block__title">Ночь с {{ formatDate(store.today.night.night_of) }}</div>
            <!--
              Ноль показывается только тогда, когда он посчитан. Непосчитанная
              ночь говорит об этом прямо: иначе сводка утверждала бы «все на
              месте» там, где никто не считал.
            -->
            <div v-if="!store.today.night.calculated" class="dorm-hint">
              Ночь не пересчитывалась. Это <b>не</b> значит, что все на месте — значит, что никто не считал.
              Пересчитать можно на вкладке «Ночные отсутствия».
            </div>
            <div v-else-if="!store.today.night.count" class="dorm-hint">Не вернувшихся нет.</div>
            <template v-else>
              <div class="dorm-hint">Не вернулись до утра: <b>{{ store.today.night.count }}</b></div>
              <div v-for="person in store.today.night.people" :key="person.student_id" class="dorm-row">
                {{ person.full_name }}<span v-if="person.group"> · {{ person.group }}</span>
                <span class="dorm-secondary"> — вышел {{ formatDateTime(person.left_at) }}</span>
              </div>
            </template>
          </div>

          <div v-if="store.today.payments" class="dorm-block">
            <div class="dorm-block__title">Оплата</div>
            <div class="dorm-hint">
              Просрочили <b>{{ store.today.payments.overdue }}</b> из {{ store.today.payments.residents }};
              ни разу не отмечалась у {{ store.today.payments.never_paid }}.
            </div>
            <div v-for="row in store.today.payments.worst" :key="row.student_id" class="dorm-row">
              {{ row.full_name }}<span v-if="row.group"> · {{ row.group }}</span>
              <span class="dorm-secondary">
                — {{ row.never_paid ? 'отметок нет' : `просрочка ${row.overdue_days} дн.` }}
              </span>
            </div>
          </div>

          <div v-if="store.today.incidents" class="dorm-block">
            <div class="dorm-block__title">Происшествия за сутки</div>
            <div v-if="!store.today.incidents.count" class="dorm-hint">Ничего не записано.</div>
            <div v-for="row in store.today.incidents.rows" :key="row.id" class="dorm-row">
              {{ formatDateTime(row.happened_at) }} — {{ row.summary }}
              <span v-if="row.room" class="dorm-secondary"> · комната {{ row.room }}</span>
            </div>
          </div>
        </template>
        <AppEmptyState v-else title="Сводка не загрузилась" description="Обновите страницу." />
      </q-tab-panel>

      <!-- Места -->
      <q-tab-panel name="rooms" class="q-pa-none">
        <AppToolbar>
          <q-toggle v-model="store.roomFilters.only_free" label="Только со свободными местами" @update:model-value="store.loadRooms" />
          <q-space />
          <q-btn flat no-caps :disable="store.loading" @click="store.loadRooms">
            <RefreshCw :size="16" class="q-mr-xs" /> Обновить
          </q-btn>
          <q-btn v-if="canManageRooms" color="primary" unelevated no-caps @click="openRoom()">
            <Plus :size="16" class="q-mr-xs" /> Добавить комнату
          </q-btn>
        </AppToolbar>

        <div class="dorm-counters">
          <div class="dorm-counter"><span class="dorm-counter__value">{{ store.roomTotals.capacity }}</span><span class="dorm-counter__label">Мест всего</span></div>
          <div class="dorm-counter"><span class="dorm-counter__value">{{ store.roomTotals.occupied }}</span><span class="dorm-counter__label">Занято</span></div>
          <div class="dorm-counter"><span class="dorm-counter__value">{{ store.roomTotals.free }}</span><span class="dorm-counter__label">Свободно</span></div>
        </div>

        <AppLoading v-if="store.loading" />
        <AppEmptyState
          v-else-if="!store.rooms.length"
          title="Комнат нет"
          description="Заведите комнаты — потом в них можно будет заселять."
        />
        <AppTable v-else :rows="store.rooms" :columns="roomColumns" row-key="id" :pagination="{ rowsPerPage: 50 }">
          <template #body-cell-kind="props">
            <q-td :props="props">{{ store.roomKinds[props.row.kind] || props.row.kind }}</q-td>
          </template>
          <template #body-cell-is_active="props">
            <q-td :props="props">
              <AppStatusBadge :label="props.row.is_active ? 'В обращении' : 'Выведена'" :tone="props.row.is_active ? 'success' : 'neutral'" />
            </q-td>
          </template>
          <template #body-cell-actions="props">
            <q-td :props="props">
              <q-btn v-if="canManageRooms" flat dense no-caps color="primary" @click="openRoom(props.row)">Изменить</q-btn>
            </q-td>
          </template>
        </AppTable>
      </q-tab-panel>

      <!-- Заселение -->
      <q-tab-panel name="placements" class="q-pa-none">
        <AppToolbar>
          <q-toggle v-model="store.placementFilters.open" label="Только действующие" @update:model-value="store.loadPlacements" />
          <q-select
            v-model="store.placementFilters.dorm_room_id"
            dense outlined clearable emit-value map-options
            label="Комната" style="min-width: 220px"
            :options="store.roomOptions"
            @update:model-value="store.loadPlacements"
          />
          <q-space />
          <q-btn flat no-caps :disable="store.loading" @click="store.loadPlacements">
            <RefreshCw :size="16" class="q-mr-xs" /> Обновить
          </q-btn>
          <q-btn v-if="canManagePlacements" color="primary" unelevated no-caps @click="openPlace('place')">
            <Plus :size="16" class="q-mr-xs" /> Заселить
          </q-btn>
        </AppToolbar>

        <AppLoading v-if="store.loading" />
        <AppEmptyState v-else-if="!store.placements.length" title="Заселений нет" description="Никто не заселён по выбранному отбору." />
        <AppTable v-else :rows="store.placements" :columns="placementColumns" row-key="id" :pagination="{ rowsPerPage: 50 }">
          <template #body-cell-student="props">
            <q-td :props="props">{{ props.row.student?.full_name || '—' }}</q-td>
          </template>
          <template #body-cell-group="props">
            <q-td :props="props">{{ props.row.student?.group || '—' }}</q-td>
          </template>
          <template #body-cell-room="props">
            <q-td :props="props">№ {{ props.row.room?.number || '—' }}</q-td>
          </template>
          <template #body-cell-moved_in_at="props">
            <q-td :props="props">{{ formatDate(props.row.moved_in_at) }}</q-td>
          </template>
          <template #body-cell-moved_out_at="props">
            <q-td :props="props">
              <span v-if="props.row.is_open">живёт</span>
              <span v-else>{{ formatDate(props.row.moved_out_at) }}</span>
            </q-td>
          </template>
          <template #body-cell-actions="props">
            <q-td :props="props" class="dorm-actions">
              <template v-if="canManagePlacements && props.row.is_open">
                <q-btn flat dense no-caps color="primary" @click="openPlace('relocate', props.row)">Переселить</q-btn>
                <q-btn flat dense no-caps @click="openMoveOut(props.row)">Выселить</q-btn>
              </template>
            </q-td>
          </template>
        </AppTable>
      </q-tab-panel>

      <!-- Отлучки -->
      <q-tab-panel name="leaves" class="q-pa-none">
        <AppToolbar>
          <q-input v-model="store.nightFilters.from" dense outlined type="date" label="С" style="min-width: 150px" />
          <q-input v-model="store.nightFilters.to" dense outlined type="date" label="По" style="min-width: 150px" />
          <q-space />
          <q-btn flat no-caps :disable="store.loading" @click="store.loadLeaves">
            <RefreshCw :size="16" class="q-mr-xs" /> Показать
          </q-btn>
          <q-btn v-if="canManageLeaves" color="primary" unelevated no-caps @click="openLeave">
            <Plus :size="16" class="q-mr-xs" /> Записать отлучку
          </q-btn>
        </AppToolbar>

        <div class="dorm-hint">
          Отлучку записывают <b>до</b> расчёта ночи: уехавший домой на выходные иначе попадёт в список не вернувшихся.
        </div>

        <AppLoading v-if="store.loading" />
        <AppEmptyState v-else-if="!store.leaves.length" title="Отлучек нет" description="За выбранный период отлучек не записано." />
        <AppTable v-else :rows="store.leaves" :columns="leaveColumns" row-key="id" :pagination="{ rowsPerPage: 50 }">
          <template #body-cell-student="props">
            <q-td :props="props">{{ props.row.student?.full_name || '—' }}</q-td>
          </template>
          <template #body-cell-group="props">
            <q-td :props="props">{{ props.row.student?.group || '—' }}</q-td>
          </template>
          <template #body-cell-starts_on="props">
            <q-td :props="props">{{ formatDate(props.row.starts_on) }}</q-td>
          </template>
          <template #body-cell-ends_on="props">
            <q-td :props="props">{{ formatDate(props.row.ends_on) }}</q-td>
          </template>
          <template #body-cell-actions="props">
            <q-td :props="props">
              <q-btn v-if="canManageLeaves" flat dense no-caps color="negative" @click="removeLeave(props.row)">Удалить</q-btn>
            </q-td>
          </template>
        </AppTable>
      </q-tab-panel>

      <!-- Ночные отсутствия -->
      <q-tab-panel name="nights" class="q-pa-none">
        <AppToolbar>
          <q-input v-model="store.nightFilters.from" dense outlined type="date" label="С" style="min-width: 150px" />
          <q-input v-model="store.nightFilters.to" dense outlined type="date" label="По" style="min-width: 150px" />
          <q-space />
          <q-btn flat no-caps :disable="store.loading" @click="store.loadAbsences">
            <RefreshCw :size="16" class="q-mr-xs" /> Показать
          </q-btn>
          <template v-if="canManageLeaves">
            <q-input v-model="recalcNight" dense outlined type="date" label="Пересчитать ночь" style="min-width: 170px" />
            <q-btn color="primary" unelevated no-caps :loading="store.saving" @click="recalculate">Пересчитать</q-btn>
          </template>
        </AppToolbar>

        <div class="dorm-hint">
          Список означает <b>«не входил до утра»</b>, а не «не ночевал»: проходная видит только дверь.
          Ушедший через чёрный ход неотличим от спящего.
        </div>

        <AppLoading v-if="store.loading" />
        <AppEmptyState v-else-if="!store.absences.length" title="Ночных отсутствий нет" description="За выбранный период никто не остался снаружи — либо ночь ещё не пересчитывали." />
        <AppTable v-else :rows="store.absences" :columns="absenceColumns" row-key="id" :pagination="{ rowsPerPage: 50 }">
          <template #body-cell-night_of="props">
            <q-td :props="props">{{ formatDate(props.row.night_of) }}</q-td>
          </template>
          <template #body-cell-student="props">
            <q-td :props="props">{{ props.row.student?.full_name || '—' }}</q-td>
          </template>
          <template #body-cell-group="props">
            <q-td :props="props">{{ props.row.student?.group || '—' }}</q-td>
          </template>
          <template #body-cell-left_at="props">
            <q-td :props="props">{{ formatDateTime(props.row.left_at) }}</q-td>
          </template>
          <template #body-cell-returned_at="props">
            <q-td :props="props">{{ formatDateTime(props.row.returned_at) }}</q-td>
          </template>
        </AppTable>
      </q-tab-panel>

      <!-- Происшествия -->
      <q-tab-panel name="incidents" class="q-pa-none">
        <AppToolbar>
          <q-input v-model="store.nightFilters.from" dense outlined type="date" label="С" style="min-width: 150px" />
          <q-input v-model="store.nightFilters.to" dense outlined type="date" label="По" style="min-width: 150px" />
          <q-space />
          <q-btn flat no-caps :disable="store.loading" @click="store.loadIncidents">
            <RefreshCw :size="16" class="q-mr-xs" /> Показать
          </q-btn>
          <q-btn v-if="canManageIncidents" color="primary" unelevated no-caps @click="openIncident()">
            <Plus :size="16" class="q-mr-xs" /> Записать происшествие
          </q-btn>
        </AppToolbar>

        <div class="dorm-hint">
          Записывайте по горячим следам: обязательны только время и одна строка.
          Участников, подробности и меры допишете потом — а вот не записанное сразу не записывается никогда.
        </div>

        <AppLoading v-if="store.loading" />
        <AppEmptyState v-else-if="!store.incidents.length" title="Происшествий нет" description="За выбранный период происшествий не записано." />
        <AppTable v-else :rows="store.incidents" :columns="incidentColumns" row-key="id" :pagination="{ rowsPerPage: 50 }">
          <template #body-cell-happened_at="props">
            <q-td :props="props">{{ formatDateTime(props.row.happened_at) }}</q-td>
          </template>
          <template #body-cell-summary="props">
            <q-td :props="props">
              <div>{{ props.row.summary }}</div>
              <div v-if="props.row.description" class="dorm-secondary">{{ props.row.description }}</div>
            </q-td>
          </template>
          <template #body-cell-room="props">
            <q-td :props="props">{{ props.row.room ? `№ ${props.row.room}` : '—' }}</q-td>
          </template>
          <template #body-cell-participants="props">
            <q-td :props="props">
              <span v-if="!props.row.participants?.length">—</span>
              <div v-for="item in props.row.participants || []" :key="item.id" class="dorm-secondary">
                {{ item.full_name }}<span v-if="item.group"> · {{ item.group }}</span>
              </div>
            </q-td>
          </template>
          <template #body-cell-measures="props">
            <q-td :props="props">{{ props.row.measures || '—' }}</q-td>
          </template>
          <template #body-cell-actions="props">
            <q-td :props="props">
              <q-btn v-if="canManageIncidents" flat dense no-caps color="primary" @click="openIncident(props.row)">Дописать</q-btn>
            </q-td>
          </template>
        </AppTable>
      </q-tab-panel>

      <!-- Оплата -->
      <q-tab-panel name="payments" class="q-pa-none">
        <AppToolbar>
          <q-btn flat no-caps :disable="store.loading" @click="store.loadPayments">
            <RefreshCw :size="16" class="q-mr-xs" /> Обновить
          </q-btn>
          <q-space />
          <q-btn v-if="canManagePayments" color="primary" unelevated no-caps @click="openPayment()">
            <Plus :size="16" class="q-mr-xs" /> Отметить оплату
          </q-btn>
        </AppToolbar>

        <div class="dorm-hint">
          Оплата считается «оплачено по такое-то число», а не помесячно.
          Когда появится обмен с 1С, его строка перекроет ручную отметку за тот же срок:
          отметка коменданта не пропадёт, а будет помечена замещённой.
        </div>

        <AppLoading v-if="store.loading" />
        <AppEmptyState v-else-if="!store.payments.length" title="Проживающих нет" description="Сводка по оплате строится по действующим заселениям." />
        <AppTable v-else :rows="store.payments" :columns="paymentColumns" row-key="student_id" :pagination="{ rowsPerPage: 50 }">
          <template #body-cell-paid_through="props">
            <q-td :props="props">
              <span v-if="props.row.never_paid">не отмечалась</span>
              <span v-else>{{ formatDate(props.row.paid_through) }}</span>
            </q-td>
          </template>
          <template #body-cell-overdue_days="props">
            <q-td :props="props">
              <AppStatusBadge
                v-if="props.row.overdue_days > 0"
                :label="`просрочка ${props.row.overdue_days} дн.`"
                :tone="props.row.overdue_days > 30 ? 'danger' : 'warning'"
              />
              <span v-else-if="props.row.never_paid">—</span>
              <AppStatusBadge v-else label="закрыт" tone="success" />
            </q-td>
          </template>
          <template #body-cell-actions="props">
            <q-td :props="props">
              <q-btn v-if="canManagePayments" flat dense no-caps color="primary" @click="openPayment(props.row)">Отметить</q-btn>
            </q-td>
          </template>
        </AppTable>
      </q-tab-panel>
      <!-- Списки и отчёты -->
      <q-tab-panel name="reports" class="q-pa-none">
        <AppToolbar>
          <q-btn flat no-caps :disable="store.loading" @click="store.loadResidents">
            <RefreshCw :size="16" class="q-mr-xs" /> Обновить список
          </q-btn>
          <q-btn color="primary" unelevated no-caps :disable="!store.residents" @click="printResidents">
            <Printer :size="16" class="q-mr-xs" /> Печать списка проживающих
          </q-btn>
        </AppToolbar>

        <div class="dorm-hint">
          Список по этажам — тот, что вывешивают и носят с собой. Лист на дверь печатается отдельно, по комнате.
        </div>

        <AppLoading v-if="store.loading" />
        <template v-else-if="store.residents">
          <div v-for="floor in store.residents.floors" :key="floor.floor ?? 0" class="dorm-block">
            <div class="dorm-block__title">
              {{ floor.floor ? floor.floor + ' этаж' : 'Без этажа' }} — занято {{ floor.occupied }} из {{ floor.capacity }}
            </div>
            <div v-for="room in floor.rooms" :key="room.id" class="dorm-row">
              <b>№ {{ room.number }}</b>
              <span class="dorm-secondary"> — {{ room.occupied }} из {{ room.capacity }}</span>
              <q-btn flat dense no-caps size="sm" color="primary" class="q-ml-sm" @click="printRoomSheet(room)">лист на дверь</q-btn>
              <div v-for="person in room.people" :key="person.student_id" class="dorm-secondary">
                {{ person.full_name }}<span v-if="person.course"> · {{ person.course }} курс</span><span v-if="person.group"> · {{ person.group }}</span><span v-if="person.phone"> · {{ person.phone }}</span>
              </div>
              <div v-if="!room.people.length" class="dorm-secondary">свободна</div>
            </div>
          </div>
        </template>

        <AppToolbar class="q-mt-md">
          <q-input v-model="reportFrom" dense outlined type="date" label="С" style="min-width: 150px" />
          <q-input v-model="reportTo" dense outlined type="date" label="По" style="min-width: 150px" />
          <q-btn flat no-caps :disable="store.loading" @click="store.loadOccupancy(reportFrom, reportTo)">
            <RefreshCw :size="16" class="q-mr-xs" /> Построить отчёт
          </q-btn>
          <q-space />
          <q-btn color="primary" unelevated no-caps :disable="!store.occupancy" @click="printOccupancy">
            <Printer :size="16" class="q-mr-xs" /> Печать отчёта
          </q-btn>
        </AppToolbar>

        <div v-if="store.occupancy" class="dorm-block">
          <div class="dorm-block__title">
            Заселённость за {{ store.occupancy.from }} — {{ store.occupancy.to }}
          </div>
          <div class="dorm-hint">
            Занято {{ store.occupancy.totals.occupied }} из {{ store.occupancy.totals.capacity }},
            свободно {{ store.occupancy.totals.free }}.
            За период въехали {{ store.occupancy.totals.moved_in }}, выехали {{ store.occupancy.totals.moved_out }}.
          </div>
          <div v-for="floor in store.occupancy.floors" :key="floor.floor ?? 0" class="dorm-row">
            {{ floor.floor ? floor.floor + ' этаж' : 'Без этажа' }} — занято {{ floor.occupied }} из {{ floor.capacity }}, свободно {{ floor.free }}
          </div>
          <div v-for="day in store.occupancy.by_date" :key="day.date" class="dorm-row">
            {{ formatDate(day.date) }} — въехали {{ day.in }}, выехали {{ day.out }}
          </div>
        </div>
      </q-tab-panel>
    </q-tab-panels>

    <q-dialog v-model="roomDialog">
      <q-card class="dorm-dialog">
        <q-card-section class="text-h6">{{ roomForm.id ? 'Изменить комнату' : 'Добавить комнату' }}</q-card-section>
        <q-card-section class="column q-gutter-sm">
          <q-input v-model="roomForm.number" dense outlined autofocus label="Номер комнаты" />
          <q-input v-model.number="roomForm.floor" dense outlined type="number" label="Этаж" />
          <q-input v-model.number="roomForm.capacity" dense outlined type="number" label="Вместимость, мест" hint="Койки отдельно не заводятся: занятость считается по заселениям" />
          <q-select v-model="roomForm.kind" dense outlined emit-value map-options label="Тип" :options="store.kindOptions" />
          <q-toggle v-model="roomForm.is_active" label="В обращении" />
          <q-input v-model="roomForm.note" dense outlined type="textarea" autogrow label="Примечание" />
        </q-card-section>
        <q-card-actions align="right">
          <q-btn flat no-caps label="Отмена" v-close-popup />
          <q-btn color="primary" no-caps :label="roomForm.id ? 'Сохранить' : 'Добавить'" :loading="store.saving" :disable="!roomForm.number.trim()" @click="submitRoom" />
        </q-card-actions>
      </q-card>
    </q-dialog>

    <q-dialog v-model="placeDialog">
      <q-card class="dorm-dialog">
        <q-card-section class="text-h6">{{ placeForm.mode === 'relocate' ? 'Переселить' : 'Заселить' }}</q-card-section>
        <q-card-section class="column q-gutter-sm">
          <q-select
            v-model="placeForm.student_id"
            dense outlined use-input emit-value map-options
            input-debounce="350" label="Студент"
            :options="store.studentOptions" :loading="store.searching"
            :disable="placeForm.mode === 'relocate'"
            @filter="(value, update) => { store.searchStudents(value); update(() => {}) }"
          />
          <q-select v-model="placeForm.dorm_room_id" dense outlined emit-value map-options label="Комната" :options="store.roomOptions" />
          <q-input v-model="placeForm.moved_in_at" dense outlined type="date" :label="placeForm.mode === 'relocate' ? 'Дата переселения' : 'Дата заселения'" />
          <q-input v-model="placeForm.basis" dense outlined label="Основание" hint="Приказ, заявление" />
          <q-input v-model="placeForm.note" dense outlined type="textarea" autogrow label="Примечание" />
        </q-card-section>
        <q-card-actions align="right">
          <q-btn flat no-caps label="Отмена" v-close-popup />
          <q-btn color="primary" no-caps :label="placeForm.mode === 'relocate' ? 'Переселить' : 'Заселить'" :loading="store.saving" :disable="!placeForm.student_id || !placeForm.dorm_room_id" @click="submitPlace" />
        </q-card-actions>
      </q-card>
    </q-dialog>

    <q-dialog v-model="moveOutDialog">
      <q-card class="dorm-dialog">
        <q-card-section class="text-h6">Выселить</q-card-section>
        <q-card-section class="column q-gutter-sm">
          <div>{{ moveOutForm.student_name }}</div>
          <q-input v-model="moveOutForm.moved_out_at" dense outlined type="date" label="Дата выселения" />
          <q-input v-model="moveOutForm.note" dense outlined type="textarea" autogrow label="Примечание" />
        </q-card-section>
        <q-card-actions align="right">
          <q-btn flat no-caps label="Отмена" v-close-popup />
          <q-btn color="primary" no-caps label="Выселить" :loading="store.saving" @click="submitMoveOut" />
        </q-card-actions>
      </q-card>
    </q-dialog>

    <q-dialog v-model="incidentDialog">
      <q-card class="dorm-dialog">
        <q-card-section class="text-h6">{{ incidentForm.id ? 'Дописать происшествие' : 'Записать происшествие' }}</q-card-section>
        <q-card-section class="column q-gutter-sm">
          <q-input v-model="incidentForm.happened_at" dense outlined type="datetime-local" label="Когда" />
          <q-input v-model="incidentForm.summary" dense outlined autofocus label="В одну строку" hint="Драка, потоп, кража — что случилось" />
          <q-select v-model="incidentForm.dorm_room_id" dense outlined clearable emit-value map-options label="Комната" :options="store.roomOptions" />
          <q-select
            v-model="incidentForm.participants"
            dense outlined multiple use-chips use-input emit-value map-options
            input-debounce="350" label="Участники"
            :options="store.studentOptions" :loading="store.searching"
            @filter="(value, update) => { store.searchStudents(value); update(() => {}) }"
          />
          <q-input v-model="incidentForm.description" dense outlined type="textarea" autogrow label="Подробно" />
          <q-input v-model="incidentForm.measures" dense outlined type="textarea" autogrow label="Что сделали" />
        </q-card-section>
        <q-card-actions align="right">
          <q-btn flat no-caps label="Отмена" v-close-popup />
          <q-btn color="primary" no-caps label="Сохранить" :loading="store.saving" :disable="!incidentForm.summary.trim() || !incidentForm.happened_at" @click="submitIncident" />
        </q-card-actions>
      </q-card>
    </q-dialog>

    <q-dialog v-model="paymentDialog">
      <q-card class="dorm-dialog">
        <q-card-section class="text-h6">Отметить оплату</q-card-section>
        <q-card-section class="column q-gutter-sm">
          <div v-if="paymentForm.student_name">{{ paymentForm.student_name }}</div>
          <q-select
            v-else
            v-model="paymentForm.student_id"
            dense outlined use-input emit-value map-options
            input-debounce="350" label="Студент"
            :options="store.studentOptions" :loading="store.searching"
            @filter="(value, update) => { store.searchStudents(value); update(() => {}) }"
          />
          <q-input v-model="paymentForm.paid_through" dense outlined type="date" label="Оплачено по" hint="До какого числа человек закрыт" />
          <q-input v-model.number="paymentForm.amount" dense outlined type="number" label="Сумма" />
          <q-input v-model="paymentForm.paid_at" dense outlined type="date" label="Дата платежа" />
          <q-input v-model="paymentForm.note" dense outlined type="textarea" autogrow label="Примечание" />
        </q-card-section>
        <q-card-actions align="right">
          <q-btn flat no-caps label="Отмена" v-close-popup />
          <q-btn color="primary" no-caps label="Отметить" :loading="store.saving" :disable="!paymentForm.student_id || !paymentForm.paid_through" @click="submitPayment" />
        </q-card-actions>
      </q-card>
    </q-dialog>

    <q-dialog v-model="leaveDialog">
      <q-card class="dorm-dialog">
        <q-card-section class="text-h6">Записать отлучку</q-card-section>
        <q-card-section class="column q-gutter-sm">
          <q-select
            v-model="leaveForm.student_id"
            dense outlined use-input emit-value map-options
            input-debounce="350" label="Студент"
            :options="store.studentOptions" :loading="store.searching"
            @filter="(value, update) => { store.searchStudents(value); update(() => {}) }"
          />
          <q-input v-model="leaveForm.starts_on" dense outlined type="date" label="С" />
          <q-input v-model="leaveForm.ends_on" dense outlined type="date" label="По" />
          <q-input v-model="leaveForm.reason" dense outlined label="Причина" hint="Домой, на соревнования, в больницу" />
        </q-card-section>
        <q-card-actions align="right">
          <q-btn flat no-caps label="Отмена" v-close-popup />
          <q-btn color="primary" no-caps label="Записать" :loading="store.saving" :disable="!leaveForm.student_id" @click="submitLeave" />
        </q-card-actions>
      </q-card>
    </q-dialog>
  </AppPage>
</template>

<style scoped>
.dorm-tabs { border-bottom: 1px solid #e2e8f0; }
.dorm-panels { background: transparent; }
.dorm-counters { display: flex; gap: 12px; flex-wrap: wrap; margin: 12px 0; }
.dorm-counter { display: grid; gap: 2px; padding: 8px 14px; border: 1px solid #e2e8f0; border-radius: 10px; min-width: 120px; }
.dorm-counter__value { font-size: 20px; font-weight: 600; color: #0f172a; }
.dorm-counter__label { font-size: 12px; color: #64748b; }
.dorm-hint { margin: 12px 0; font-size: 13px; color: #475569; }
.dorm-actions { white-space: nowrap; }
.dorm-secondary { font-size: 12px; color: #64748b; }
.dorm-block { border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px 16px; margin-bottom: 12px; background: #fff; }
.dorm-block__title { font-size: 14px; font-weight: 600; color: #0f172a; margin-bottom: 6px; }
.dorm-row { font-size: 13px; padding: 3px 0; border-top: 1px solid #f1f5f9; }
.dorm-dialog { min-width: min(520px, 92vw); }

/*
  Содержимое панели выкладываем в строку: поля ввода блочные, и без этого
  фильтры встают друг под друга столбиком. Правило местное — общий стиль делят
  другие разделы.
*/
:deep(.app-toolbar__content) {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  width: 100%;
}

:deep(.app-toolbar) { padding: 8px 10px; }
</style>
