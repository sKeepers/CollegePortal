<script setup>
import { computed, onMounted, ref, watch } from 'vue'
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
import StudentDetailsPanel from './StudentDetailsPanel.vue'
import StudentFilters from './StudentFilters.vue'
import StudentFormPanel from './StudentFormPanel.vue'
import { useStudentsStore } from '../../stores/students'
import { useReferenceOptionsStore } from '../../stores/referenceOptions'
import {
  TABLE_ROWS_PER_PAGE_OPTIONS,
  createTablePagination,
  persistTablePagination,
} from '../../services/tableSettings'

const store = useStudentsStore()
const referenceOptions = useReferenceOptionsStore()
const $q = useQuasar()
const route = useRoute()
const router = useRouter()
const STUDENTS_ROWS_PER_PAGE_KEY = 'collegePortal.students.rowsPerPage'
const syncingQueryFromUi = ref(false)

const importFile = ref(null)
const formVisible = ref(false)
const editingStudent = ref(null)
const deletingStudent = ref(null)
const deleteDialogVisible = ref(false)
const tablePagination = ref(createTablePagination(STUDENTS_ROWS_PER_PAGE_KEY, {
  sortBy: 'full_name',
  rowsPerPage: 20,
}))

const statusOptions = computed(() => referenceOptions.options('student_statuses'))
const statusLabels = computed(() => Object.fromEntries(statusOptions.value.map((option) => [option.value, option.label])))
const statusTones = computed(() => Object.fromEntries(statusOptions.value.map((option) => [option.value, option.tone || 'neutral'])))

const columns = [
  {
    name: 'full_name',
    label: 'ФИО',
    field: (row) => fullName(row),
    align: 'left',
    sortable: true,
  },
  {
    name: 'group',
    label: 'Группа',
    field: (row) => row.group?.name || '',
    align: 'left',
    sortable: true,
  },
  {
    name: 'status',
    label: 'Статус',
    field: 'status',
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

const tableSubtitle = computed(() => {
  const total = store.pagination?.total ?? store.students.length
  return `Найдено записей: ${total}`
})

function fullName(student) {
  return [student?.last_name, student?.first_name, student?.middle_name].filter(Boolean).join(' ')
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
  return Number(row.id) === Number(store.selectedId) ? 'students-row--selected' : ''
}

function updateTablePagination(pagination) {
  tablePagination.value = pagination
  persistTablePagination(STUDENTS_ROWS_PER_PAGE_KEY, pagination)
}

function routeGroupId() {
  return route.query.group ? String(route.query.group) : ''
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

async function syncStudentQuery({ groupId = routeGroupId(), selectedId = routeSelectedId(), searchText = routeSearchText() }) {
  const query = { ...route.query }

  if (groupId) {
    query.group = groupId
  } else {
    delete query.group
  }

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
  await router.replace({ path: '/students', query })
  syncingQueryFromUi.value = false
}

async function selectStudent(student) {
  store.selectStudent(student)
  await syncStudentQuery({ selectedId: student?.id || '' })
}

function openCreateForm() {
  editingStudent.value = null
  formVisible.value = true
}

function openEditForm(student) {
  editingStudent.value = student
  formVisible.value = true
}

async function saveStudent(payload) {
  const isEdit = Boolean(editingStudent.value?.id)
  await store.save(payload, editingStudent.value?.id || null)
  formVisible.value = false
  editingStudent.value = null
  notifySuccess(isEdit ? 'Студент обновлен' : 'Студент создан')
}

function requestDelete(student) {
  deletingStudent.value = student
  deleteDialogVisible.value = true
}

async function confirmDelete() {
  const name = deletingStudent.value ? fullName(deletingStudent.value) : 'Студент'
  await store.remove(deletingStudent.value)
  deletingStudent.value = null
  notifySuccess(`${name}: запись удалена`)
}

async function applyFilters(filters) {
  store.setFilters(filters)
  await syncStudentQuery({
    groupId: filters.group_id,
    selectedId: '',
    searchText: filters.search,
  })
  await store.load()
}

async function resetFilters() {
  store.resetFilters()
  await syncStudentQuery({ groupId: '', selectedId: '', searchText: '' })
  await store.load()
}

async function handleImport(file) {
  if (!file) {
    return
  }

  await store.importCsv(file)
  importFile.value = null
  notifySuccess('Импорт студентов завершен')
}

async function exportStudents() {
  await store.exportCsv()
  notifySuccess('Экспорт студентов подготовлен')
}

watch(
  () => [route.query.group, route.query.selected, route.query.search, route.query.action],
  async () => {
    if (syncingQueryFromUi.value) {
      return
    }

    store.setFilters({
      group_id: routeGroupId(),
      search: routeSearchText(),
    })
    await store.load()
    store.selectStudentById(routeSelectedId())

    if (routeAction() === 'create') {
      openCreateForm()
    }
  },
)

onMounted(async () => {
  await referenceOptions.loadCatalog('student_statuses')
  store.setFilters({
    group_id: routeGroupId(),
    search: routeSearchText(),
  })
  await store.load()
  store.selectStudentById(routeSelectedId())

  if (routeAction() === 'create') {
    openCreateForm()
  }
})
</script>

<template>
  <AppPage>
    <PageHeader
      title="Студенты"
      subtitle="Учет студентов, фильтры, карточка, импорт и экспорт."
    >
      <template #actions>
        <q-btn color="primary" @click="openCreateForm">
          <template #default>
            <Plus :size="16" />
            <span>Новый студент</span>
          </template>
        </q-btn>
      </template>
    </PageHeader>

    <StudentFilters
      :model-value="store.filters"
      :group-options="store.groupOptions"
      :status-options="statusOptions"
      :loading="store.loading"
      @apply="applyFilters"
      @reset="resetFilters"
      @update:model-value="store.setFilters"
    />

    <AppToolbar>
      <span>{{ tableSubtitle }}</span>
      <template #actions>
        <AppLoading v-if="store.loading" label="Загрузка студентов..." />
        <q-file
          v-model="importFile"
          dense
          outlined
          clearable
          accept=".csv,text/csv,text/plain"
          class="students-import-field"
          label="Импорт"
          :disable="store.loading"
          @update:model-value="handleImport"
        >
          <template #prepend>
            <Upload :size="16" />
          </template>
          <q-tooltip>Загрузка файла CSV</q-tooltip>
        </q-file>
        <q-btn flat disable>
          <span>Массовые действия</span>
          <q-tooltip>Будет доступно после добавления выбора строк</q-tooltip>
        </q-btn>
        <q-btn flat :disable="store.loading" @click="store.load">
          <template #default>
            <RefreshCw :size="16" />
            <span>Обновить</span>
          </template>
        </q-btn>
        <q-btn color="secondary" :disable="store.loading" @click="exportStudents">
          <template #default>
            <Download :size="16" />
            <span>Экспорт</span>
            <q-tooltip>Скачать CSV</q-tooltip>
          </template>
        </q-btn>
      </template>
    </AppToolbar>

    <AppErrorBanner :message="store.error" />

    <q-banner v-if="store.importSummary" rounded class="students-import-summary">
      <div>
        Импорт завершен:
        создано {{ store.importSummary.created }},
        обновлено {{ store.importSummary.updated }},
        строк с ошибками {{ store.importSummary.errors?.length || 0 }}.
      </div>
      <ul v-if="store.importSummary.errors?.length" class="students-import-summary__errors">
        <li v-for="errorRow in store.importSummary.errors" :key="errorRow.line">
          Строка {{ errorRow.line }}: {{ errorRow.messages?.join('; ') || 'ошибка импорта' }}
        </li>
      </ul>
    </q-banner>

    <div class="students-layout">
      <div class="students-main">
        <AppTable
          v-if="store.students.length || store.loading"
          :rows="store.students"
          :columns="columns"
          :loading="store.loading"
          :pagination="tablePagination"
          :rows-per-page-options="TABLE_ROWS_PER_PAGE_OPTIONS"
          :table-row-class-fn="tableRowClass"
          @update:pagination="updateTablePagination"
          @row-click="(_, row) => selectStudent(row)"
        >
          <template #body-cell-full_name="props">
            <q-td :props="props">
              <button class="students-row-link" type="button" @click.stop="selectStudent(props.row)">
                {{ fullName(props.row) }}
              </button>
            </q-td>
          </template>

          <template #body-cell-status="props">
            <q-td :props="props">
              <AppStatusBadge
                :label="statusLabels[props.row.status] || props.row.status"
                :tone="statusTones[props.row.status] || 'neutral'"
              />
            </q-td>
          </template>

          <template #body-cell-contacts="props">
            <q-td :props="props">
              <div class="students-contact-cell">
                <span>{{ props.row.phone || '—' }}</span>
                <small>{{ props.row.email || '—' }}</small>
              </div>
            </q-td>
          </template>

          <template #body-cell-actions="props">
            <q-td :props="props">
              <div class="students-row-actions">
                <q-btn flat round dense title="Редактировать" @click.stop="openEditForm(props.row)">
                  <Edit3 :size="16" />
                </q-btn>
                <q-btn flat round dense color="negative" title="Удалить" @click.stop="requestDelete(props.row)">
                  <Trash2 :size="16" />
                </q-btn>
              </div>
            </q-td>
          </template>
        </AppTable>

        <AppEmptyState
          v-else
          title="Студенты не найдены"
          description="Измените фильтры, импортируйте CSV или создайте новую запись вручную."
        >
          <q-btn color="primary" label="Новый студент" @click="openCreateForm" />
        </AppEmptyState>
      </div>

      <aside class="students-side">
        <StudentDetailsPanel
          :student="store.selectedStudent"
          :status-labels="statusLabels"
          :status-tones="statusTones"
          :attendance-summary="store.attendanceSummary"
          :grade-summary="store.gradeSummary"
          :loading="store.detailsLoading"
        />
      </aside>
    </div>

    <q-dialog v-model="formVisible" persistent>
      <div class="students-form-dialog">
        <StudentFormPanel
          :student="editingStudent"
          :group-options="store.groupOptions"
          :status-options="statusOptions"
          :saving="store.saving"
          @save="saveStudent"
          @cancel="formVisible = false"
        />
      </div>
    </q-dialog>

    <AppConfirmDialog
      v-model="deleteDialogVisible"
      title="Удалить студента?"
      :message="deletingStudent ? `Будет удалена запись: ${fullName(deletingStudent)}.` : 'Будет удалена выбранная запись.'"
      confirm-label="Удалить"
      tone="negative"
      @confirm="confirmDelete"
    />
  </AppPage>
</template>
