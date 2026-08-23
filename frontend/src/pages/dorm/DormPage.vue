<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useQuasar } from 'quasar'
import { BedDouble, Plus, RefreshCw } from '@lucide/vue'
import AppPage from '../../components/ui/AppPage.vue'
import PageHeader from '../../components/ui/PageHeader.vue'
import AppToolbar from '../../components/ui/AppToolbar.vue'
import AppTable from '../../components/ui/AppTable.vue'
import AppEmptyState from '../../components/ui/AppEmptyState.vue'
import AppLoading from '../../components/ui/AppLoading.vue'
import AppErrorBanner from '../../components/ui/AppErrorBanner.vue'
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'
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

const tab = ref('rooms')

const roomDialog = ref(false)
const roomForm = reactive({ id: null, number: '', floor: null, capacity: 2, kind: 'regular', is_active: true, note: '' })

const placeDialog = ref(false)
const placeForm = reactive({ mode: 'place', student_id: null, dorm_room_id: null, moved_in_at: today(), basis: '', note: '' })

const moveOutDialog = ref(false)
const moveOutForm = reactive({ student_id: null, student_name: '', moved_out_at: today(), note: '' })

const leaveDialog = ref(false)
const leaveForm = reactive({ student_id: null, starts_on: today(), ends_on: today(), reason: '' })

const recalcNight = ref(yesterday())

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

async function openTab(name) {
  tab.value = name
  if (name === 'rooms') await store.loadRooms()
  if (name === 'placements') {
    await store.loadPlacements()
    if (!store.rooms.length) await store.loadRooms()
  }
  if (name === 'leaves') await store.loadLeaves()
  if (name === 'nights') await store.loadAbsences()
}

onMounted(() => store.loadRooms())
</script>

<template>
  <AppPage>
    <PageHeader title="Общежитие" subtitle="Места, заселение, отлучки и ночи">
      <template #icon><BedDouble :size="22" /></template>
    </PageHeader>

    <AppErrorBanner v-if="store.error" :message="store.error" />

    <q-tabs :model-value="tab" dense no-caps align="left" class="dorm-tabs" @update:model-value="openTab">
      <q-tab name="rooms" label="Места" />
      <q-tab name="placements" label="Заселение" />
      <q-tab name="leaves" label="Отлучки" />
      <q-tab name="nights" label="Ночные отсутствия" />
    </q-tabs>

    <q-tab-panels :model-value="tab" animated class="dorm-panels">
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
