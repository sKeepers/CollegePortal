<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { formatPhone } from '../../utils/phone'
import { useQuasar } from 'quasar'
import { useRoute, useRouter } from 'vue-router'
import { Download, Edit3, Plus, RefreshCw, Trash2, Upload } from '@lucide/vue'
import AppPage from '../../components/ui/AppPage.vue'
import PageHeader from '../../components/ui/PageHeader.vue'
import AppToolbar from '../../components/ui/AppToolbar.vue'
import AppTable from '../../components/ui/AppTable.vue'
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'
import AppEmptyState from '../../components/ui/AppEmptyState.vue'
import AppLoading from '../../components/ui/AppLoading.vue'
import AppErrorBanner from '../../components/ui/AppErrorBanner.vue'
import AppConfirmDialog from '../../components/ui/AppConfirmDialog.vue'
import WorkspaceSplitter from '../../components/workspace/WorkspaceSplitter.vue'
import TeacherDetailsPanel from './TeacherDetailsPanel.vue'
import TeacherFilters from './TeacherFilters.vue'
import TeacherFormPanel from './TeacherFormPanel.vue'
import { useTeachersStore } from '../../stores/teachers'
import { usePermissions } from '../../composables/usePermissions'
import { useResizableWorkspace } from '../../composables/useResizableWorkspace'
import {
  TABLE_ROWS_PER_PAGE_OPTIONS,
  createTablePagination,
  persistTablePagination,
} from '../../services/tableSettings'

const permissions = usePermissions()
const store = useTeachersStore()
const $q = useQuasar()
const route = useRoute()
const router = useRouter()
const TEACHERS_ROWS_PER_PAGE_KEY = 'collegePortal.teachers.rowsPerPage'
const syncingQueryFromUi = ref(false)
const { resetSplitter, startResize, workspaceRef, workspaceStyle } = useResizableWorkspace({ storageKey: 'collegePortal.teachers.splitter.v1', resizeBodyClass: 'teachers-splitter-resizing' })
const canCreate = computed(() => permissions.hasPermission('teachers.create') || permissions.hasPermission('teachers.edit'))
const canUpdate = computed(() => permissions.hasPermission('teachers.update') || permissions.hasPermission('teachers.edit'))
const canDelete = computed(() => permissions.hasPermission('teachers.delete') || permissions.hasPermission('teachers.edit'))
const canImport = computed(() => canUpdate.value)
const canExport = computed(() => permissions.hasPermission('teachers.update') || permissions.hasPermission('teachers.edit') || permissions.hasPermission('teachers.view'))

const importFile = ref(null)
const formVisible = ref(false)
const editingTeacher = ref(null)
const deletingTeacher = ref(null)
const deleteDialogVisible = ref(false)
const tablePagination = ref(createTablePagination(TEACHERS_ROWS_PER_PAGE_KEY, {
  sortBy: 'full_name',
  rowsPerPage: 20,
}))

const columns = [
  {
    name: 'full_name',
    label: 'ФИО',
    field: (row) => fullName(row),
    align: 'left',
    sortable: true,
  },
  {
    name: 'department',
    label: 'Отделение / должность',
    field: 'department',
    align: 'left',
    sortable: true,
  },
  {
    name: 'status',
    label: 'Статус',
    field: 'is_active',
    align: 'left',
    sortable: true,
  },
  {
    name: 'contacts',
    label: 'Контакты',
    field: 'email',
    align: 'left',
  },
  {
    name: 'actions',
    label: '',
    field: 'actions',
    align: 'right',
  },
]

const tableSubtitle = computed(() => (
  `Найдено преподавателей: ${store.filteredTeachers.length}`
))

function fullName(teacher) {
  return [teacher?.last_name, teacher?.first_name, teacher?.middle_name].filter(Boolean).join(' ')
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
  return Number(row.id) === Number(store.selectedId) ? 'teachers-row--selected' : ''
}

function updateTablePagination(pagination) {
  tablePagination.value = pagination
  persistTablePagination(TEACHERS_ROWS_PER_PAGE_KEY, pagination)
}

function routeSelectedId() {
  return route.query.selected ? String(route.query.selected) : ''
}

function routeSearchText() {
  return route.query.search ? String(route.query.search) : ''
}

function routeAction() {
  return route.query.action ? String(route.query.action) : ''
}

async function syncTeacherQuery({ selectedId = routeSelectedId(), searchText = routeSearchText() }) {
  const query = { ...route.query }

  if (selectedId) {
    query.selected = selectedId
  } else {
    delete query.selected
  }

  if (searchText) {
    query.search = searchText
  } else {
    delete query.search
  }

  syncingQueryFromUi.value = true
  await router.replace({ path: '/teachers', query })
  syncingQueryFromUi.value = false
}

async function selectTeacher(teacher) {
  store.selectTeacher(teacher)
  await syncTeacherQuery({ selectedId: teacher?.id || '' })
}

function openCreateForm() {
  if (!canCreate.value) return
  editingTeacher.value = null
  formVisible.value = true
}

function openEditForm(teacher) {
  if (!canUpdate.value) return
  editingTeacher.value = teacher
  formVisible.value = true
}

async function saveTeacher(payload) {
  const isEdit = Boolean(editingTeacher.value?.id)
  await store.save(payload, editingTeacher.value?.id || null)
  formVisible.value = false
  editingTeacher.value = null
  notifySuccess(isEdit ? 'Преподаватель обновлен' : 'Преподаватель создан')
}

function requestDelete(teacher) {
  if (!canDelete.value) return
  deletingTeacher.value = teacher
  deleteDialogVisible.value = true
}

async function confirmDelete() {
  const name = deletingTeacher.value ? fullName(deletingTeacher.value) : 'Преподаватель'
  await store.remove(deletingTeacher.value)
  deletingTeacher.value = null
  notifySuccess(`${name}: запись удалена`)
}

async function applyFilters(filters) {
  store.setFilters(filters)
  await syncTeacherQuery({ selectedId: '', searchText: filters.search })
}

async function resetFilters() {
  store.resetFilters()
  await syncTeacherQuery({ selectedId: '', searchText: '' })
}

async function handleImport(file) {
  if (!canImport.value) return
  if (!file) {
    return
  }

  await store.importCsv(file)
  importFile.value = null
  notifySuccess('Импорт преподавателей завершен')
}

async function exportTeachers() {
  if (!canExport.value) return
  await store.exportCsv()
  notifySuccess('Экспорт преподавателей подготовлен')
}

watch(
  () => [route.query.selected, route.query.search, route.query.action],
  async () => {
    if (syncingQueryFromUi.value) {
      return
    }

    store.setFilters({
      search: routeSearchText(),
    })
    store.selectTeacherById(routeSelectedId())

    if (routeAction() === 'create') {
      openCreateForm()
    }
  },
)

onMounted(async () => {
  store.setFilters({
    search: routeSearchText(),
  })
  store.selectTeacherById(routeSelectedId())
  await store.load()

  if (routeAction() === 'create') {
    openCreateForm()
  }
})
</script>

<template>
  <AppPage>
    <PageHeader
      title="Преподаватели"
      subtitle="Педагогический состав, контакты, отделения, CSV-обмен и быстрые переходы."
    >
      <template #actions>
        <q-btn v-if="canCreate" color="primary" @click="openCreateForm">
          <template #default>
            <Plus :size="16" />
            <span>Новый преподаватель</span>
          </template>
        </q-btn>
      </template>
    </PageHeader>

    <TeacherFilters
      :model-value="store.filters"
      :status-options="store.statusOptions"
      :department-options="store.departmentOptions"
      :loading="store.loading"
      @apply="applyFilters"
      @reset="resetFilters"
      @update:model-value="store.setFilters"
    />

    <AppToolbar>
      <span>{{ tableSubtitle }}</span>
      <template #actions>
        <AppLoading v-if="store.loading" label="Загрузка преподавателей..." />
        <q-file
          v-if="canImport"
          v-model="importFile"
          dense
          outlined
          clearable
          accept=".csv,text/csv,text/plain"
          class="teachers-import-field"
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
        <q-btn v-if="canExport" color="secondary" :disable="store.loading" @click="exportTeachers">
          <template #default>
            <Download :size="16" />
            <span>Экспорт</span>
            <q-tooltip>Скачать CSV</q-tooltip>
          </template>
        </q-btn>
      </template>
    </AppToolbar>

    <AppErrorBanner :message="store.error" />

    <q-banner v-if="store.importSummary" rounded class="teachers-import-summary">
      <div>
        Импорт завершен:
        создано {{ store.importSummary.created }},
        обновлено {{ store.importSummary.updated }},
        строк с ошибками {{ store.importSummary.errors?.length || 0 }}.
      </div>
      <ul v-if="store.importSummary.errors?.length" class="teachers-import-summary__errors">
        <li v-for="errorRow in store.importSummary.errors" :key="errorRow.line">
          Строка {{ errorRow.line }}: {{ errorRow.messages?.join('; ') || 'ошибка импорта' }}
        </li>
      </ul>
    </q-banner>

    <div ref="workspaceRef" class="teachers-layout" :style="workspaceStyle">
      <div class="teachers-main">
        <AppTable
          v-if="store.filteredTeachers.length || store.loading"
          :rows="store.filteredTeachers"
          :columns="columns"
          :loading="store.loading"
          :pagination="tablePagination"
          :rows-per-page-options="TABLE_ROWS_PER_PAGE_OPTIONS"
          :table-row-class-fn="tableRowClass"
          @update:pagination="updateTablePagination"
          @row-click="(_, row) => selectTeacher(row)"
        >
          <template #body-cell-full_name="props">
            <q-td :props="props">
              <button class="teachers-row-link" type="button" @click.stop="selectTeacher(props.row)">
                {{ fullName(props.row) }}
              </button>
            </q-td>
          </template>

          <template #body-cell-status="props">
            <q-td :props="props">
              <AppStatusBadge
                :label="props.row.is_active ? 'Активен' : 'Неактивен'"
                :tone="props.row.is_active ? 'success' : 'neutral'"
              />
            </q-td>
          </template>

          <template #body-cell-department="props">
            <q-td :props="props">
              <div class="teachers-secondary-cell">
                <span>{{ props.row.department || '—' }}</span>
                <small>{{ props.row.position || 'Должность не указана' }}</small>
              </div>
            </q-td>
          </template>

          <template #body-cell-contacts="props">
            <q-td :props="props">
              <div class="teachers-contact-cell">
                <span>{{ formatPhone(props.row.phone, "—") }}</span>
                <small>{{ props.row.email || '—' }}</small>
              </div>
            </q-td>
          </template>

          <template #body-cell-actions="props">
            <q-td :props="props">
              <div class="teachers-row-actions">
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
          title="Преподаватели не найдены"
          description="Измените фильтры, импортируйте CSV или создайте преподавателя вручную."
        >
          <q-btn v-if="canCreate" color="primary" label="Новый преподаватель" @click="openCreateForm" />
        </AppEmptyState>
      </div>

      <WorkspaceSplitter label="Изменить ширину карточки преподавателя" @resize-start="startResize" @reset="resetSplitter" />

      <aside class="teachers-side">
        <TeacherDetailsPanel
          :teacher="store.selectedTeacher"
          :subjects="store.selectedTeacherSubjects"
          :lessons="store.selectedTeacherLessons"
        />
      </aside>
    </div>

    <q-dialog v-model="formVisible" persistent>
      <div class="teachers-form-dialog">
        <TeacherFormPanel
          :teacher="editingTeacher"
          :saving="store.saving"
          @save="saveTeacher"
          @cancel="formVisible = false"
        />
      </div>
    </q-dialog>

    <AppConfirmDialog
      v-model="deleteDialogVisible"
      title="Удалить преподавателя?"
      :message="deletingTeacher ? `Будет удалена запись: ${fullName(deletingTeacher)}.` : 'Будет удалена выбранная запись.'"
      confirm-label="Удалить"
      tone="negative"
      @confirm="confirmDelete"
    />
  </AppPage>
</template>

<style scoped>
.teachers-layout { gap: 0; }
.teachers-main, .teachers-side { min-width: 0; }
.teachers-main { padding-right: 10px; }
.teachers-side { max-width: none; padding-left: 10px; }
@media (max-width: 1100px) { .teachers-layout { grid-template-columns: 1fr !important; gap: 16px; } .teachers-main, .teachers-side { padding: 0; } }
</style>
