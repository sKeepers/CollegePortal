<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { RefreshCw, Search, UserRound } from '@lucide/vue'
import AppPage from '../../components/ui/AppPage.vue'
import PageHeader from '../../components/ui/PageHeader.vue'
import AppToolbar from '../../components/ui/AppToolbar.vue'
import AppFilterBar from '../../components/ui/AppFilterBar.vue'
import AppTable from '../../components/ui/AppTable.vue'
import AppEmptyState from '../../components/ui/AppEmptyState.vue'
import AppLoading from '../../components/ui/AppLoading.vue'
import AppErrorBanner from '../../components/ui/AppErrorBanner.vue'
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'
import WorkspacePanel from '../../components/workspace/WorkspacePanel.vue'
import WorkspaceSplitter from '../../components/workspace/WorkspaceSplitter.vue'
import { useResizableWorkspace } from '../../composables/useResizableWorkspace'
import { TABLE_ROWS_PER_PAGE_OPTIONS, createTablePagination, persistTablePagination } from '../../services/tableSettings'
import { usePeopleStore } from '../../stores/people'

const store = usePeopleStore()
const route = useRoute()
const router = useRouter()
const syncingQuery = ref(false)
const rowsKey = 'collegePortal.people.rowsPerPage'
const splitterKey = 'collegePortal.people.splitter.v1'
const tablePagination = ref(createTablePagination(rowsKey, { sortBy: 'last_name', rowsPerPage: 20 }))
const {
  resetSplitter,
  startResize,
  workspaceRef,
  workspaceStyle,
} = useResizableWorkspace({
  storageKey: splitterKey,
  defaultDetailsWidth: 400,
  minDetailsWidth: 340,
  minListWidth: 520,
  resizeBodyClass: 'people-splitter-resizing',
})

const profileOptions = [
  { label: 'Все профили', value: '' },
  { label: 'Студенты', value: 'student' },
  { label: 'Преподаватели', value: 'teacher' },
  { label: 'Абитуриенты', value: 'applicant' },
  { label: 'Выпускники', value: 'graduate' },
  { label: 'Пользователи', value: 'user' },
]

const columns = [
  { name: 'full_name', label: 'ФИО', field: 'full_name', align: 'left', sortable: true },
  { name: 'contacts', label: 'Контакты', field: 'email', align: 'left' },
  { name: 'profiles', label: 'Профили', field: 'profiles', align: 'left' },
  { name: 'status', label: 'Статус', field: 'status', align: 'left', sortable: true },
]

const selected = computed(() => store.selected)
const tableSubtitle = computed(() => `Найдено людей: ${store.people.length}`)
const metrics = computed(() => {
  const counts = selected.value?.profiles_count || {}
  return [
    { label: 'Студент', value: counts.students || 0 },
    { label: 'Преподаватель', value: counts.teachers || 0 },
    { label: 'Абитуриент', value: counts.applicant_applications || 0 },
    { label: 'Выпускник', value: counts.graduates || 0 },
    { label: 'Пользователь', value: counts.users || 0 },
    { label: 'QR', value: counts.digital_identities || 0 },
  ]
})
const actions = computed(() => {
  const person = selected.value
  if (!person) return []
  const items = []
  const student = person.students?.[0]
  const teacher = person.teachers?.[0]
  const applicant = person.applicant_applications?.[0]
  const graduate = person.graduates?.[0]
  if (student) items.push({ label: 'Открыть студента', to: { path: '/students', query: { selected: student.id } } })
  if (teacher) items.push({ label: 'Открыть преподавателя', to: { path: '/teachers', query: { selected: teacher.id } } })
  if (applicant) items.push({ label: 'Открыть заявление', to: { path: '/admissions', query: { selected: applicant.id } } })
  if (graduate) items.push({ label: 'Открыть выпускника', to: { path: '/graduation', query: { selected: graduate.id } } })
  if (person.digital_identities?.length) items.push({ label: 'Цифровой пропуск', to: '/identity/digital-passes' })
  return items
})

function profileCount(person, key) { return person?.profiles_count?.[key] || 0 }
function statusTone(status) { return status === 'active' ? 'success' : status === 'inactive' ? 'warning' : 'info' }
function statusLabel(status) {
  const labels = { active: 'Активен', inactive: 'Неактивен', archived: 'Архив' }
  return labels[status] || status || '—'
}
function tableRowClass(row) { return Number(row.id) === Number(store.selectedId) ? 'people-row--selected' : '' }
function updatePagination(pagination) { tablePagination.value = pagination; persistTablePagination(rowsKey, pagination) }
function routeSelectedId() { return route.query.selected ? String(route.query.selected) : '' }
async function syncQuery(selectedId = routeSelectedId()) {
  const query = { ...route.query }
  selectedId ? query.selected = selectedId : delete query.selected
  store.filters.search ? query.search = store.filters.search : delete query.search
  store.filters.profile ? query.profile = store.filters.profile : delete query.profile
  syncingQuery.value = true
  await router.replace({ path: '/people', query })
  syncingQuery.value = false
}
async function applyFilters() { await store.load(); await syncQuery('') }
async function resetFilters() { store.resetFilters(); await store.load(); await syncQuery('') }
async function selectPerson(person) { await store.select(person?.id); await syncQuery(person?.id || '') }

watch(() => route.query.selected, (value) => { if (!syncingQuery.value) store.select(value ? String(value) : '') })
watch(() => [route.query.search, route.query.profile], async () => {
  if (syncingQuery.value) return
  store.filters.search = route.query.search ? String(route.query.search) : ''
  store.filters.profile = route.query.profile ? String(route.query.profile) : ''
  await store.load()
}, { deep: true })

onMounted(async () => {
  store.filters.search = route.query.search ? String(route.query.search) : ''
  store.filters.profile = route.query.profile ? String(route.query.profile) : ''
  await store.load()
  if (routeSelectedId()) await store.select(routeSelectedId())
})

</script>

<template>
  <AppPage>
    <PageHeader title="Люди" subtitle="Единая карточка человека для студентов, преподавателей, абитуриентов, выпускников, пользователей и цифровой идентичности." />
    <AppToolbar>
      <span>{{ tableSubtitle }}</span>
      <template #actions>
        <AppLoading v-if="store.loading || store.detailsLoading" label="Загрузка людей..." />
        <q-btn flat @click="resetSplitter">Сбросить размер</q-btn>
        <q-btn flat :disable="store.loading" @click="store.load"><RefreshCw :size="16" class="q-mr-xs" /> Обновить</q-btn>
      </template>
    </AppToolbar>
    <AppErrorBanner :message="store.error" />

    <AppFilterBar @apply="applyFilters" @reset="resetFilters">
      <q-input v-model="store.filters.search" dense outlined clearable label="ФИО, телефон, email" @keyup.enter="applyFilters">
        <template #prepend><Search :size="16" /></template>
      </q-input>
      <q-select v-model="store.filters.profile" dense outlined clearable emit-value map-options label="Профиль" :options="profileOptions" />
    </AppFilterBar>

    <div ref="workspaceRef" class="people-workspace" :style="workspaceStyle">
      <section class="people-main">
        <AppTable
          v-if="store.people.length || store.loading"
          :rows="store.people"
          :columns="columns"
          :loading="store.loading"
          :pagination="tablePagination"
          :rows-per-page-options="TABLE_ROWS_PER_PAGE_OPTIONS"
          :table-row-class-fn="tableRowClass"
          @update:pagination="updatePagination"
          @row-click="(_, row) => selectPerson(row)"
        >
          <template #body-cell-full_name="props">
            <q-td :props="props"><q-btn flat dense no-caps color="primary" @click.stop="selectPerson(props.row)">{{ props.row.full_name }}</q-btn></q-td>
          </template>
          <template #body-cell-contacts="props">
            <q-td :props="props"><div>{{ props.row.phone || '—' }}</div><small>{{ props.row.email || 'email не указан' }}</small></q-td>
          </template>
          <template #body-cell-profiles="props">
            <q-td :props="props" class="people-profile-chips">
              <q-chip v-if="profileCount(props.row, 'students')" dense>Студент</q-chip>
              <q-chip v-if="profileCount(props.row, 'teachers')" dense>Преподаватель</q-chip>
              <q-chip v-if="profileCount(props.row, 'applicant_applications')" dense>Абитуриент</q-chip>
              <q-chip v-if="profileCount(props.row, 'graduates')" dense>Выпускник</q-chip>
              <q-chip v-if="profileCount(props.row, 'users')" dense>Пользователь</q-chip>
            </q-td>
          </template>
          <template #body-cell-status="props"><q-td :props="props"><AppStatusBadge :label="statusLabel(props.row.status)" :tone="statusTone(props.row.status)" /></q-td></template>
        </AppTable>
        <AppEmptyState v-else title="Люди не найдены" description="Измените фильтры или выполните привязку существующих профилей." />
      </section>

      <WorkspaceSplitter label="Изменить ширину карточки человека" @resize-start="startResize" @reset="resetSplitter" />

      <aside class="people-side">
        <AppEmptyState v-if="!selected" title="Человек не выбран" description="Выберите строку, чтобы открыть связанные профили." />
        <WorkspacePanel v-else :title="selected.full_name" :subtitle="[selected.phone, selected.email].filter(Boolean)" :metrics="metrics" :actions="actions">
          <template #photo>
            <q-avatar size="72px" color="grey-2" text-color="grey-8">
              <img v-if="selected.photo_url" :src="selected.photo_url" alt="Фото" />
              <UserRound v-else :size="32" />
            </q-avatar>
          </template>
          <template #status><AppStatusBadge :label="statusLabel(selected.status)" :tone="statusTone(selected.status)" /></template>
          <dl class="people-details">
            <div><dt>Дата рождения</dt><dd>{{ selected.birth_date || '—' }}</dd></div>
            <div><dt>Гражданство</dt><dd>{{ selected.citizenship || '—' }}</dd></div>
            <div><dt>СНИЛС</dt><dd>{{ selected.snils || '—' }}</dd></div>
            <div><dt>ИНН</dt><dd>{{ selected.inn || '—' }}</dd></div>
          </dl>
        </WorkspacePanel>
      </aside>
    </div>
  </AppPage>
</template>

<style scoped>
.people-workspace { display: grid; gap: 0; align-items: start; }
.people-main, .people-side { min-width: 0; }
.people-main { padding-right: 10px; }
.people-side { padding-left: 10px; }
.people-profile-chips { white-space: normal; }
.people-details { display: grid; gap: 10px; margin: 0; }
.people-details div { display: grid; gap: 2px; }
.people-details dt { color: #64748b; font-size: 12px; }
.people-details dd { margin: 0; color: #0f172a; overflow-wrap: anywhere; }
:deep(.people-row--selected) { background: #eef6ff; }
@media (max-width: 1100px) {
  .people-workspace { grid-template-columns: 1fr !important; gap: 16px; }
  .people-main, .people-side { padding: 0; }
}
</style>
