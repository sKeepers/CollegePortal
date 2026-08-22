<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useQuasar } from 'quasar'
import { useRoute, useRouter } from 'vue-router'
import { Download, Edit3, Plus, RefreshCw, Trash2, Upload } from '@lucide/vue'
import WorkspaceBackBar from '../../components/workspace/WorkspaceBackBar.vue'
import AppPage from '../../components/ui/AppPage.vue'
import PageHeader from '../../components/ui/PageHeader.vue'
import AppToolbar from '../../components/ui/AppToolbar.vue'
import AppTable from '../../components/ui/AppTable.vue'
import AppEmptyState from '../../components/ui/AppEmptyState.vue'
import AppLoading from '../../components/ui/AppLoading.vue'
import AppErrorBanner from '../../components/ui/AppErrorBanner.vue'
import AppConfirmDialog from '../../components/ui/AppConfirmDialog.vue'
import ClassroomDetailsPanel from './ClassroomDetailsPanel.vue'
import ClassroomFilters from './ClassroomFilters.vue'
import ClassroomFormPanel from './ClassroomFormPanel.vue'
import { useClassroomsStore } from '../../stores/classrooms'
import { usePermissions } from '../../composables/usePermissions'
import {
  TABLE_ROWS_PER_PAGE_OPTIONS,
  createTablePagination,
  persistTablePagination,
} from '../../services/tableSettings'

const permissions = usePermissions()
const store = useClassroomsStore()
const $q = useQuasar()
const route = useRoute()
const router = useRouter()
const CLASSROOMS_ROWS_PER_PAGE_KEY = 'collegePortal.classrooms.rowsPerPage'
const syncingQueryFromUi = ref(false)
const canCreate = computed(() => permissions.hasPermission('classrooms.create') || permissions.hasPermission('classrooms.edit'))
const canUpdate = computed(() => permissions.hasPermission('classrooms.update') || permissions.hasPermission('classrooms.edit'))
const canDelete = computed(() => permissions.hasPermission('classrooms.delete') || permissions.hasPermission('classrooms.edit'))
const canImport = computed(() => canUpdate.value)
const canExport = computed(() => permissions.hasPermission('classrooms.update') || permissions.hasPermission('classrooms.edit') || permissions.hasPermission('classrooms.view'))

const importFile = ref(null)
const formVisible = ref(false)
const editingClassroom = ref(null)
const deletingClassroom = ref(null)
const deleteDialogVisible = ref(false)
const tablePagination = ref(createTablePagination(CLASSROOMS_ROWS_PER_PAGE_KEY, {
  sortBy: 'number',
  rowsPerPage: 20,
}))

const columns = [
  { name: 'number', label: 'Аудитория', field: 'number', align: 'left', sortable: true },
  { name: 'building', label: 'Корпус / этаж', field: 'building', align: 'left', sortable: true },
  { name: 'type', label: 'Тип', field: 'type', align: 'left', sortable: true },
  { name: 'capacity', label: 'Вместимость', field: 'capacity', align: 'left', sortable: true },
  { name: 'actions', label: '', field: 'actions', align: 'right' },
]

const tableSubtitle = computed(() => `Найдено аудиторий: ${store.filteredClassrooms.length}`)

function classroomTitle(classroom) {
  return [
    classroom?.number,
    classroom?.building ? `корп. ${classroom.building}` : '',
  ].filter(Boolean).join(' · ')
}

function notifySuccess(message) {
  $q.notify({
    type: 'positive',
    message,
    position: 'top-right',
    timeout: 1800,
  })
}

function tableRowClass(row) {
  return Number(row.id) === Number(store.selectedId) ? 'classrooms-row--selected' : ''
}

function updateTablePagination(pagination) {
  tablePagination.value = pagination
  persistTablePagination(CLASSROOMS_ROWS_PER_PAGE_KEY, pagination)
}

function routeSelectedId() {
  return route.params.id ? String(route.params.id) : ''
}

function routeSearchText() {
  return route.query.search ? String(route.query.search) : ''
}

function routeAction() {
  return route.query.action ? String(route.query.action) : ''
}

async function syncClassroomQuery({ selectedId = routeSelectedId(), searchText = routeSearchText() }) {
  const query = { ...route.query }

  if (searchText) {
    query.search = searchText
  } else {
    delete query.search
  }

  syncingQueryFromUi.value = true
  await router.replace({ path: selectedId ? `/classrooms/${selectedId}` : '/classrooms', query })
  syncingQueryFromUi.value = false
}

async function selectClassroom(classroom) {
  store.selectClassroom(classroom)
  await syncClassroomQuery({ selectedId: classroom?.id || '' })
}

function openCreateForm() {
  if (!canCreate.value) return
  editingClassroom.value = null
  formVisible.value = true
}

function openEditForm(classroom) {
  if (!canUpdate.value) return
  editingClassroom.value = classroom
  formVisible.value = true
}

async function saveClassroom(payload) {
  const isEdit = Boolean(editingClassroom.value?.id)
  await store.save(payload, editingClassroom.value?.id || null)
  formVisible.value = false
  editingClassroom.value = null
  notifySuccess(isEdit ? 'Аудитория обновлена' : 'Аудитория создана')
}

function requestDelete(classroom) {
  if (!canDelete.value) return
  deletingClassroom.value = classroom
  deleteDialogVisible.value = true
}

async function confirmDelete() {
  const name = deletingClassroom.value ? classroomTitle(deletingClassroom.value) : 'Аудитория'
  await store.remove(deletingClassroom.value)
  deletingClassroom.value = null
  notifySuccess(`${name}: запись удалена`)
}

async function applyFilters(filters) {
  store.setFilters(filters)
  await syncClassroomQuery({ selectedId: '', searchText: filters.search })
}

async function resetFilters() {
  store.resetFilters()
  await syncClassroomQuery({ selectedId: '', searchText: '' })
}

async function handleImport(file) {
  if (!canImport.value) return
  if (!file) {
    return
  }

  await store.importCsv(file)
  importFile.value = null
  notifySuccess('Импорт аудиторий завершен')
}

async function exportClassrooms() {
  if (!canExport.value) return
  await store.exportCsv()
  notifySuccess('Экспорт аудиторий подготовлен')
}

watch(
  () => [route.params.id, route.query.search, route.query.action],
  async () => {
    if (syncingQueryFromUi.value) {
      return
    }

    store.setFilters({
      search: routeSearchText(),
    })
    store.selectClassroomById(routeSelectedId())

    if (routeAction() === 'create') {
      openCreateForm()
    }
  },
)

onMounted(async () => {
  store.setFilters({
    search: routeSearchText(),
  })
  store.selectClassroomById(routeSelectedId())
  await store.load()

  if (routeAction() === 'create') {
    openCreateForm()
  }
})
</script>

<template>
  <AppPage>
    <PageHeader
      title="Аудитории"
      subtitle="Аудиторный фонд, корпуса, этажи, вместимость, CSV-обмен и быстрые переходы."
    >
      <template #actions>
        <q-btn v-if="canCreate" color="primary" @click="openCreateForm">
          <template #default>
            <Plus :size="16" />
            <span>Новая аудитория</span>
          </template>
        </q-btn>
      </template>
    </PageHeader>

    <ClassroomFilters
      :model-value="store.filters"
      :building-options="store.buildingOptions"
      :type-options="store.typeOptions"
      :loading="store.loading"
      @apply="applyFilters"
      @reset="resetFilters"
      @update:model-value="store.setFilters"
    />

    <AppToolbar>
      <span>{{ tableSubtitle }}</span>
      <template #actions>
        <AppLoading v-if="store.loading" label="Загрузка аудиторий..." />
        <q-file
          v-if="canImport"
          v-model="importFile"
          dense
          outlined
          clearable
          accept=".csv,text/csv,text/plain"
          class="classrooms-import-field"
          label="Импорт"
          :disable="store.loading"
          @update:model-value="handleImport"
        >
          <template #prepend>
            <Upload :size="16" />
          </template>
          <q-tooltip>Загрузка файла CSV</q-tooltip>
        </q-file>
        <q-btn flat :disable="store.loading" @click="store.load">
          <template #default>
            <RefreshCw :size="16" />
            <span>Обновить</span>
          </template>
        </q-btn>
        <q-btn flat @click="resetSplitter">Сбросить размер</q-btn>
        <q-btn v-if="canExport" color="secondary" :disable="store.loading" @click="exportClassrooms">
          <template #default>
            <Download :size="16" />
            <span>Экспорт</span>
            <q-tooltip>Скачать CSV</q-tooltip>
          </template>
        </q-btn>
      </template>
    </AppToolbar>

    <AppErrorBanner :message="store.error" />

    <q-banner v-if="store.importSummary" rounded class="classrooms-import-summary">
      <div>
        Импорт завершен:
        создано {{ store.importSummary.created }},
        обновлено {{ store.importSummary.updated }},
        строк с ошибками {{ store.importSummary.errors?.length || 0 }}.
      </div>
      <ul v-if="store.importSummary.errors?.length" class="classrooms-import-summary__errors">
        <li v-for="errorRow in store.importSummary.errors" :key="errorRow.line">
          Строка {{ errorRow.line }}: {{ errorRow.messages?.join('; ') || 'ошибка импорта' }}
        </li>
      </ul>
    </q-banner>

    <div class="classrooms-layout workspace-page" :class="{ 'workspace-page--card': Boolean(route.params.id) }">
      <div class="classrooms-main workspace-page__list">
        <AppTable
          v-if="store.filteredClassrooms.length || store.loading"
          :rows="store.filteredClassrooms"
          :columns="columns"
          :loading="store.loading"
          :pagination="tablePagination"
          :rows-per-page-options="TABLE_ROWS_PER_PAGE_OPTIONS"
          :table-row-class-fn="tableRowClass"
          @update:pagination="updateTablePagination"
          :row-link="(row) => `/classrooms/${row.id}`"
          @row-click="(_, row) => selectClassroom(row)"
        >
          <template #body-cell-number="props">
            <q-td :props="props">
              <button class="classrooms-row-link" type="button" @click.stop="selectClassroom(props.row)">
                {{ classroomTitle(props.row) }}
              </button>
              <div v-if="props.row.description" class="classrooms-row-description">
                {{ props.row.description }}
              </div>
            </q-td>
          </template>

          <template #body-cell-building="props">
            <q-td :props="props">
              <div class="classrooms-secondary-cell">
                <span>{{ props.row.building || '—' }}</span>
                <small>Этаж: {{ props.row.floor ?? '—' }}</small>
              </div>
            </q-td>
          </template>

          <template #body-cell-type="props">
            <q-td :props="props">
              {{ props.row.type || '—' }}
            </q-td>
          </template>

          <template #body-cell-capacity="props">
            <q-td :props="props">
              {{ props.row.capacity || '—' }}
            </q-td>
          </template>

          <template #body-cell-actions="props">
            <q-td :props="props">
              <div class="classrooms-row-actions">
                <q-btn v-if="canUpdate" flat round dense title="Редактировать" @click.stop="openEditForm(props.row)">
                  <Edit3 :size="16" />
                </q-btn>
                <q-btn v-if="canDelete" flat round dense color="negative" title="Удалить" @click.stop="requestDelete(props.row)">
                  <Trash2 :size="16" />
                </q-btn>
              </div>
            </q-td>
          </template>
        </AppTable>

        <AppEmptyState
          v-else
          title="Аудитории не найдены"
          description="Измените фильтры, импортируйте CSV или создайте аудиторию вручную."
        >
          <q-btn v-if="canCreate" color="primary" label="Новая аудитория" @click="openCreateForm" />
        </AppEmptyState>
      </div>

      <aside class="classrooms-side workspace-page__card">
        <WorkspaceBackBar />
        <ClassroomDetailsPanel
          :classroom="store.selectedClassroom"
          :lessons="store.selectedClassroomLessons"
        />
      </aside>
    </div>

    <q-dialog v-model="formVisible" persistent>
      <div class="classrooms-form-dialog">
        <ClassroomFormPanel
          :classroom="editingClassroom"
          :saving="store.saving"
          @save="saveClassroom"
          @cancel="formVisible = false"
        />
      </div>
    </q-dialog>

    <AppConfirmDialog
      v-model="deleteDialogVisible"
      title="Удалить аудиторию?"
      :message="deletingClassroom ? `Будет удалена запись: ${classroomTitle(deletingClassroom)}.` : 'Будет удалена выбранная запись.'"
      confirm-label="Удалить"
      tone="negative"
      @confirm="confirmDelete"
    />
  </AppPage>
</template>

<style scoped>
.classrooms-layout { gap: 0; }
.classrooms-main, .classrooms-side { min-width: 0; }
.classrooms-main { padding-right: 10px; }
.classrooms-side { max-width: none; padding-left: 10px; }
@media (max-width: 1100px) { .classrooms-layout { grid-template-columns: 1fr !important; gap: 16px; } .classrooms-main, .classrooms-side { padding: 0; } }
</style>
