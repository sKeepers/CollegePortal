<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useQuasar } from 'quasar'
import { useRoute, useRouter } from 'vue-router'
import { Download, Edit3, Plus, RefreshCw, Trash2, Upload } from '@lucide/vue'
import AppPage from '../../components/ui/AppPage.vue'
import PageHeader from '../../components/ui/PageHeader.vue'
import AppToolbar from '../../components/ui/AppToolbar.vue'
import AppTable from '../../components/ui/AppTable.vue'
import AppEmptyState from '../../components/ui/AppEmptyState.vue'
import AppLoading from '../../components/ui/AppLoading.vue'
import AppErrorBanner from '../../components/ui/AppErrorBanner.vue'
import AppConfirmDialog from '../../components/ui/AppConfirmDialog.vue'
import SubjectDetailsPanel from './SubjectDetailsPanel.vue'
import SubjectFilters from './SubjectFilters.vue'
import SubjectFormPanel from './SubjectFormPanel.vue'
import { useSubjectsStore } from '../../stores/subjects'
import {
  TABLE_ROWS_PER_PAGE_OPTIONS,
  createTablePagination,
  persistTablePagination,
} from '../../services/tableSettings'

const store = useSubjectsStore()
const $q = useQuasar()
const route = useRoute()
const router = useRouter()
const SUBJECTS_ROWS_PER_PAGE_KEY = 'collegePortal.subjects.rowsPerPage'
const syncingQueryFromUi = ref(false)

const importFile = ref(null)
const formVisible = ref(false)
const editingSubject = ref(null)
const deletingSubject = ref(null)
const deleteDialogVisible = ref(false)
const tablePagination = ref(createTablePagination(SUBJECTS_ROWS_PER_PAGE_KEY, {
  sortBy: 'name',
  rowsPerPage: 20,
}))

const columns = [
  { name: 'name', label: 'Название', field: 'name', align: 'left', sortable: true },
  { name: 'code', label: 'Код', field: 'code', align: 'left', sortable: true },
  { name: 'department', label: 'Отделение', field: 'department', align: 'left', sortable: true },
  { name: 'teachers', label: 'Преподаватели', field: 'teachers', align: 'left' },
  { name: 'actions', label: '', field: 'actions', align: 'right' },
]

const tableSubtitle = computed(() => `Найдено дисциплин: ${store.filteredSubjects.length}`)

function teacherName(teacher) {
  return [teacher?.last_name, teacher?.first_name, teacher?.middle_name].filter(Boolean).join(' ')
}

function teachersSummary(subject) {
  const teachers = Array.isArray(subject?.teachers) ? subject.teachers : []

  if (!teachers.length) {
    return '—'
  }

  return teachers.map(teacherName).filter(Boolean).join(', ')
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
  return Number(row.id) === Number(store.selectedId) ? 'subjects-row--selected' : ''
}

function updateTablePagination(pagination) {
  tablePagination.value = pagination
  persistTablePagination(SUBJECTS_ROWS_PER_PAGE_KEY, pagination)
}

function routeSelectedId() {
  return route.query.selected ? String(route.query.selected) : ''
}

function routeSearchText() {
  return route.query.search ? String(route.query.search) : ''
}

function routeTeacherId() {
  return route.query.teacher ? String(route.query.teacher) : ''
}

function routeAction() {
  return route.query.action ? String(route.query.action) : ''
}

async function syncSubjectQuery({
  selectedId = routeSelectedId(),
  searchText = routeSearchText(),
  teacherId = routeTeacherId(),
}) {
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

  if (teacherId) {
    query.teacher = teacherId
  } else {
    delete query.teacher
  }

  syncingQueryFromUi.value = true
  await router.replace({ path: '/subjects', query })
  syncingQueryFromUi.value = false
}

async function selectSubject(subject) {
  store.selectSubject(subject)
  await syncSubjectQuery({ selectedId: subject?.id || '' })
}

function openCreateForm() {
  editingSubject.value = null
  formVisible.value = true
}

function openEditForm(subject) {
  editingSubject.value = subject
  formVisible.value = true
}

async function saveSubject(payload) {
  const isEdit = Boolean(editingSubject.value?.id)
  await store.save(payload, editingSubject.value?.id || null)
  formVisible.value = false
  editingSubject.value = null
  notifySuccess(isEdit ? 'Дисциплина обновлена' : 'Дисциплина создана')
}

function requestDelete(subject) {
  deletingSubject.value = subject
  deleteDialogVisible.value = true
}

async function confirmDelete() {
  const name = deletingSubject.value?.name || 'Дисциплина'
  await store.remove(deletingSubject.value)
  deletingSubject.value = null
  notifySuccess(`${name}: запись удалена`)
}

async function applyFilters(filters) {
  store.setFilters(filters)
  await syncSubjectQuery({
    selectedId: '',
    searchText: filters.search,
    teacherId: filters.teacher_id,
  })
}

async function resetFilters() {
  store.resetFilters()
  await syncSubjectQuery({ selectedId: '', searchText: '', teacherId: '' })
}

async function handleImport(file) {
  if (!file) {
    return
  }

  await store.importCsv(file)
  importFile.value = null
  notifySuccess('Импорт дисциплин завершен')
}

async function exportSubjects() {
  await store.exportCsv()
  notifySuccess('Экспорт дисциплин подготовлен')
}

watch(
  () => [route.query.selected, route.query.search, route.query.teacher, route.query.action],
  async () => {
    if (syncingQueryFromUi.value) {
      return
    }

    store.setFilters({
      search: routeSearchText(),
      teacher_id: routeTeacherId(),
    })
    store.selectSubjectById(routeSelectedId())

    if (routeAction() === 'create') {
      openCreateForm()
    }
  },
)

onMounted(async () => {
  store.setFilters({
    search: routeSearchText(),
    teacher_id: routeTeacherId(),
  })
  store.selectSubjectById(routeSelectedId())
  await store.load()

  if (routeAction() === 'create') {
    openCreateForm()
  }
})
</script>

<template>
  <AppPage>
    <PageHeader
      title="Дисциплины"
      subtitle="Учебные дисциплины, коды, отделения, преподаватели, CSV-обмен и быстрые переходы."
    >
      <template #actions>
        <q-btn color="primary" @click="openCreateForm">
          <template #default>
            <Plus :size="16" />
            <span>Новая дисциплина</span>
          </template>
        </q-btn>
      </template>
    </PageHeader>

    <SubjectFilters
      :model-value="store.filters"
      :department-options="store.departmentOptions"
      :teacher-options="store.teacherOptions"
      :loading="store.loading"
      @apply="applyFilters"
      @reset="resetFilters"
      @update:model-value="store.setFilters"
    />

    <AppToolbar>
      <span>{{ tableSubtitle }}</span>
      <template #actions>
        <AppLoading v-if="store.loading" label="Загрузка дисциплин..." />
        <q-file
          v-model="importFile"
          dense
          outlined
          clearable
          accept=".csv,text/csv,text/plain"
          class="subjects-import-field"
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
        <q-btn color="secondary" :disable="store.loading" @click="exportSubjects">
          <template #default>
            <Download :size="16" />
            <span>Экспорт</span>
            <q-tooltip>Скачать CSV</q-tooltip>
          </template>
        </q-btn>
      </template>
    </AppToolbar>

    <AppErrorBanner :message="store.error" />

    <q-banner v-if="store.importSummary" rounded class="subjects-import-summary">
      <div>
        Импорт завершен:
        создано {{ store.importSummary.created }},
        обновлено {{ store.importSummary.updated }},
        строк с ошибками {{ store.importSummary.errors?.length || 0 }}.
      </div>
      <ul v-if="store.importSummary.errors?.length" class="subjects-import-summary__errors">
        <li v-for="errorRow in store.importSummary.errors" :key="errorRow.line">
          Строка {{ errorRow.line }}: {{ errorRow.messages?.join('; ') || 'ошибка импорта' }}
        </li>
      </ul>
    </q-banner>

    <div class="subjects-layout">
      <div class="subjects-main">
        <AppTable
          v-if="store.filteredSubjects.length || store.loading"
          :rows="store.filteredSubjects"
          :columns="columns"
          :loading="store.loading"
          :pagination="tablePagination"
          :rows-per-page-options="TABLE_ROWS_PER_PAGE_OPTIONS"
          :table-row-class-fn="tableRowClass"
          @update:pagination="updateTablePagination"
          @row-click="(_, row) => selectSubject(row)"
        >
          <template #body-cell-name="props">
            <q-td :props="props">
              <button class="subjects-row-link" type="button" @click.stop="selectSubject(props.row)">
                {{ props.row.name }}
              </button>
              <div v-if="props.row.description" class="subjects-row-description">
                {{ props.row.description }}
              </div>
            </q-td>
          </template>

          <template #body-cell-code="props">
            <q-td :props="props">
              <q-chip v-if="props.row.code" dense>{{ props.row.code }}</q-chip>
              <span v-else>—</span>
            </q-td>
          </template>

          <template #body-cell-department="props">
            <q-td :props="props">
              {{ props.row.department || '—' }}
            </q-td>
          </template>

          <template #body-cell-teachers="props">
            <q-td :props="props">
              <div class="subjects-secondary-cell">
                <span>{{ teachersSummary(props.row) }}</span>
              </div>
            </q-td>
          </template>

          <template #body-cell-actions="props">
            <q-td :props="props">
              <div class="subjects-row-actions">
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
          title="Дисциплины не найдены"
          description="Измените фильтры, импортируйте CSV или создайте дисциплину вручную."
        >
          <q-btn color="primary" label="Новая дисциплина" @click="openCreateForm" />
        </AppEmptyState>
      </div>

      <aside class="subjects-side">
        <SubjectDetailsPanel
          :subject="store.selectedSubject"
          :teachers="store.selectedSubjectTeachers"
          :lessons="store.selectedSubjectLessons"
        />
      </aside>
    </div>

    <q-dialog v-model="formVisible" persistent>
      <div class="subjects-form-dialog">
        <SubjectFormPanel
          :subject="editingSubject"
          :teacher-options="store.teacherOptions"
          :saving="store.saving"
          @save="saveSubject"
          @cancel="formVisible = false"
        />
      </div>
    </q-dialog>

    <AppConfirmDialog
      v-model="deleteDialogVisible"
      title="Удалить дисциплину?"
      :message="deletingSubject ? `Будет удалена запись: ${deletingSubject.name}.` : 'Будет удалена выбранная запись.'"
      confirm-label="Удалить"
      tone="negative"
      @confirm="confirmDelete"
    />
  </AppPage>
</template>
