<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { formatPhone } from '../../utils/phone'
import { useQuasar } from 'quasar'
import { useRoute, useRouter } from 'vue-router'
import { Download, Edit3, Plus, RefreshCw, Trash2, Upload } from '@lucide/vue'
import WorkspaceBackBar from '../../components/workspace/WorkspaceBackBar.vue'
import AppPage from '../../components/ui/AppPage.vue'
import PageHeader from '../../components/ui/PageHeader.vue'
import AppToolbar from '../../components/ui/AppToolbar.vue'
import AppTable from '../../components/ui/AppTable.vue'
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'
import AppEmptyState from '../../components/ui/AppEmptyState.vue'
import AppLoading from '../../components/ui/AppLoading.vue'
import AppErrorBanner from '../../components/ui/AppErrorBanner.vue'
import AppConfirmDialog from '../../components/ui/AppConfirmDialog.vue'
import DeletionRequestDialog from '../../components/trash/DeletionRequestDialog.vue'
import StudentDetailsPanel from './StudentDetailsPanel.vue'
import StudentFilters from './StudentFilters.vue'
import StudentFormPanel from './StudentFormPanel.vue'
import { useStudentsStore } from '../../stores/students'
import { useReferenceOptionsStore } from '../../stores/referenceOptions'
import { usePermissions } from '../../composables/usePermissions'
import {
  TABLE_ROWS_PER_PAGE_OPTIONS,
  createTablePagination,
  persistTablePagination,
} from '../../services/tableSettings'

const permissions = usePermissions()
const store = useStudentsStore()
const referenceOptions = useReferenceOptionsStore()
const $q = useQuasar()
const route = useRoute()
const router = useRouter()
const STUDENTS_ROWS_PER_PAGE_KEY = 'collegePortal.students.rowsPerPage'
const syncingQueryFromUi = ref(false)
const canCreate = computed(() => permissions.hasPermission('students.create') || permissions.hasPermission('students.edit'))
const canUpdate = computed(() => permissions.hasPermission('students.update') || permissions.hasPermission('students.edit'))
// Удаление в два шага: помечает тот, кто ведёт карточку, удаляет администратор.
const canDelete = computed(() => permissions.hasPermission('trash.manage'))
const canRequestDeletion = computed(() => !canDelete.value && permissions.hasPermission('trash.request'))
const canImport = computed(() => canUpdate.value)
const canExport = computed(() => permissions.hasPermission('students.update') || permissions.hasPermission('students.edit') || permissions.hasPermission('students.view'))

const importFile = ref(null)
const formVisible = ref(false)
const editingStudent = ref(null)
const deletingStudent = ref(null)
const deleteDialogVisible = ref(false)
const deletionRequestStudent = ref(null)
const deletionRequestVisible = ref(false)
const tablePagination = ref(createTablePagination(STUDENTS_ROWS_PER_PAGE_KEY, {
  sortBy: 'full_name',
  rowsPerPage: 20,
}))

const selectedRows = ref([])
const selectAllFiltered = ref(false)
const bulkDialogVisible = ref(false)
const bulkAction = ref('')
const bulkPayload = ref({})
const bulkPreview = ref(null)
const credentialsDialogVisible = ref(false)
const issuedCredentials = ref([])

const bulkActions = computed(() => [
  { label: 'Назначить группу', value: 'assign_group', permission: 'students.bulk_group' },
  { label: 'Изменить статус', value: 'change_status', permission: 'students.bulk_status' },
  { label: 'Изменить курс', value: 'change_course', permission: 'students.bulk_course' },
  { label: 'Изменить форму обучения', value: 'change_education_form', permission: 'students.bulk_education' },
  { label: 'Изменить финансирование', value: 'change_funding_form', permission: 'students.bulk_education' },
  { label: 'Выпустить QR', value: 'issue_digital_passes', permission: 'students.bulk_passes' },
  { label: 'Создать учётные записи', value: 'issue_accounts', permission: 'students.bulk_accounts' },
  { label: 'Архивировать', value: 'archive_selected', permission: 'students.bulk_archive' },
  { label: 'Экспортировать', value: 'export_selected', permission: 'students.bulk_export' },
].filter((action) => permissions.hasPermission(action.permission)))

const selectedCount = computed(() => (selectAllFiltered.value ? (store.pagination?.total ?? store.students.length) : selectedRows.value.length))
const hasBulkSelection = computed(() => selectedCount.value > 0)

const statusOptions = computed(() => referenceOptions.options('student_statuses'))
// Пустой список статусов обязан объяснить причину: нет прав, справочник пуст
// или запрос не прошёл. Раньше все три случая выглядели одинаково.
const statusHint = computed(() => referenceOptions.hint('student_statuses'))
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
    name: 'card_completeness',
    label: 'Карточка',
    field: (row) => (row.card_completeness?.complete ? 'complete' : 'incomplete'),
    align: 'left',
  },
  {
    name: 'actions',
    label: '',
    field: 'actions',
    align: 'right',
  },
]

// Чего не хватает в карточке — считает бэкенд, здесь только подпись к признаку.
function missingParts(row) {
  return (row.card_completeness?.blocking_reasons || [])
}

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

function clearSelection() {
  selectedRows.value = []
  selectAllFiltered.value = false
  bulkPreview.value = null
}

function requestSelectionReset() {
  if (!hasBulkSelection.value) return true
  clearSelection()
  $q.notify({ type: 'info', message: 'Выбор очищен из-за изменения фильтров', position: 'top-right' })
  return true
}

function openBulkDialog(action = '') {
  if (!hasBulkSelection.value) return
  bulkAction.value = action || bulkActions.value[0]?.value || ''
  bulkPayload.value = {}
  bulkPreview.value = null
  bulkDialogVisible.value = true
}

function bulkRequest() {
  return {
    ids: selectAllFiltered.value ? [] : selectedRows.value.map((row) => row.id),
    filter: selectAllFiltered.value ? { ...store.filters } : {},
    action: bulkAction.value,
    payload: { ...bulkPayload.value },
  }
}

async function previewBulkAction() {
  bulkPreview.value = await store.previewBulk(bulkRequest())
}

async function applyBulkAction() {
  const result = await store.applyBulk(bulkRequest())
  bulkPreview.value = result?.action === 'export_selected' ? bulkPreview.value : result
  notifySuccess(bulkAction.value === 'export_selected' ? 'Экспорт выбранных студентов подготовлен' : 'Массовая операция выполнена')

  // Пароли приходят один раз и только здесь: окно результата закрывать нельзя,
  // пока оператор не распечатал карточки или не выгрузил CSV.
  if (bulkAction.value === 'issue_accounts' && result?.credentials?.length) {
    issuedCredentials.value = result.credentials
    credentialsDialogVisible.value = true
  }

  if (bulkAction.value !== 'export_selected') {
    clearSelection()
    bulkDialogVisible.value = false
  }
}

function credentialsCsv() {
  const rows = [['ФИО', 'Группа', 'Логин', 'Пароль']]
  issuedCredentials.value.forEach((row) => rows.push([row.name, row.group || '', row.login, row.password]))
  // BOM, иначе Excel открывает кириллицу как набор символов.
  const csv = '﻿' + rows.map((row) => row.map((cell) => `"${String(cell).replaceAll('"', '""')}"`).join(';')).join('\r\n')
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = `accounts-${new Date().toISOString().slice(0, 10)}.csv`
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
}

function printCredentials() {
  window.print()
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
  return route.params.id ? String(route.params.id) : ''
}

function routeSearchText() {
  return route.query.search ? String(route.query.search) : ''
}

function routeAction() {
  return route.query.action ? String(route.query.action) : ''
}

// Напоминание «Учебной части» ведёт сюда со списком неполных карточек.
function routeCompleteness() {
  return route.query.completeness === 'incomplete' ? 'incomplete' : ''
}

async function syncStudentQuery({ groupId = routeGroupId(), selectedId = routeSelectedId(), searchText = routeSearchText() }) {
  const query = { ...route.query }

  if (groupId) {
    query.group = groupId
  } else {
    delete query.group
  }

  if (searchText) {
    query.search = searchText
  } else {
    delete query.search
  }

  syncingQueryFromUi.value = true
  await router.replace({ path: selectedId ? `/students/${selectedId}` : '/students', query })
  syncingQueryFromUi.value = false
}

async function selectStudent(student) {
  store.selectStudent(student)
  await syncStudentQuery({ selectedId: student?.id || '' })
}

function openCreateForm() {
  if (!canCreate.value) return
  editingStudent.value = null
  formVisible.value = true
}

function openEditForm(student) {
  if (!canUpdate.value) return
  editingStudent.value = student
  formVisible.value = true
}

async function saveStudent(payload) {
  const isEdit = Boolean(editingStudent.value?.id)
  await store.save(payload, editingStudent.value?.id || null)
  formVisible.value = false
  editingStudent.value = null
  notifySuccess(isEdit ? 'Студент обновлен' : 'Студент создан')
  notifySaveWarnings()
}

/**
 * Пустой СНИЛС и вероятные дубли по ФИО и дате рождения — предупреждения, а не ошибки:
 * карточка сохранена, но неполна, а совпадения оператор объединяет вручную.
 */
function notifySaveWarnings() {
  const warnings = store.lastWarnings

  if (!warnings) return

  if (warnings.snils_missing) {
    $q.notify({
      type: 'warning',
      message: 'СНИЛС не заполнен',
      caption: 'Карточка сохранена как неполная. Без СНИЛС заблокированы ФИС ГИА, ФРДО, приказ и диплом.',
      position: 'top-right',
      timeout: 8000,
    })
  }

  const candidates = warnings.duplicate_candidates || []
  if (candidates.length) {
    $q.notify({
      type: 'warning',
      message: `Возможные дубли: ${candidates.length}`,
      caption: candidates
        .map((candidate) => [candidate.full_name, candidate.birth_date].filter(Boolean).join(', '))
        .join('; '),
      position: 'top-right',
      timeout: 12000,
      actions: [{ label: 'Открыть реестр людей', color: 'white', handler: () => router.push('/people') }],
    })
  }
}

async function saveEducationDocument(payload) {
  const studentId = store.selectedId
  if (!studentId) return

  await store.addEducationDocument(studentId, payload)
  notifySuccess('Документ об образовании сохранён')
}

function requestDelete(student) {
  if (!canDelete.value) return
  deletingStudent.value = student
  deleteDialogVisible.value = true
}

async function confirmDelete() {
  const name = deletingStudent.value ? fullName(deletingStudent.value) : 'Студент'
  await store.remove(deletingStudent.value)
  deletingStudent.value = null
  notifySuccess(`${name}: запись удалена`)
}

function askDeletionRequest(student) {
  if (!canRequestDeletion.value) return
  deletionRequestStudent.value = student
  deletionRequestVisible.value = true
}

function onDeletionRequested() {
  const name = deletionRequestStudent.value ? fullName(deletionRequestStudent.value) : 'Карточка'
  deletionRequestStudent.value = null
  notifySuccess(`${name}: заявка на удаление отправлена администратору`)
}

async function applyFilters(filters) {
  requestSelectionReset()
  store.setFilters(filters)
  await syncStudentQuery({
    groupId: filters.group_id,
    selectedId: '',
    searchText: filters.search,
  })
  await store.load()
}

async function resetFilters() {
  requestSelectionReset()
  store.resetFilters()
  await syncStudentQuery({ groupId: '', selectedId: '', searchText: '' })
  await store.load()
}

async function handleImport(file) {
  if (!canImport.value) return
  if (!file) {
    return
  }

  await store.importCsv(file)
  importFile.value = null
  notifySuccess('Импорт студентов завершен')
}

async function exportStudents() {
  if (!canExport.value) return
  await store.exportCsv()
  notifySuccess('Экспорт студентов подготовлен')
}

watch(
  () => [route.query.group, route.params.id, route.query.search, route.query.action],
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
    completeness: routeCompleteness(),
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
        <q-btn v-if="canCreate" color="primary" @click="openCreateForm">
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
      :course-options="store.courseOptions"
      :specialty-options="store.specialtyOptions"
      :status-hint="statusHint"
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
          v-if="canImport"
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
        <q-btn flat :disable="!hasBulkSelection || store.loading" @click="openBulkDialog()">
          <span>Групповые действия</span>
          <q-tooltip>{{ hasBulkSelection ? `Выбрано: ${selectedCount}` : 'Выберите студентов в таблице' }}</q-tooltip>
        </q-btn>
        <q-btn flat :disable="store.loading" @click="store.load">
          <template #default>
            <RefreshCw :size="16" />
            <span>Обновить</span>
          </template>
        </q-btn>
        <q-btn flat @click="resetSplitter">Сбросить размер</q-btn>
        <q-btn v-if="canExport" color="secondary" :disable="store.loading" @click="exportStudents">
          <template #default>
            <Download :size="16" />
            <span>Экспорт</span>
            <q-tooltip>Скачать CSV</q-tooltip>
          </template>
        </q-btn>
      </template>
    </AppToolbar>

    <q-banner v-if="hasBulkSelection" rounded class="students-import-summary">
      <div class="row items-center justify-between q-gutter-sm">
        <div>
          Выбрано записей: <strong>{{ selectedCount }}</strong>
          <span v-if="selectAllFiltered"> · все записи по текущему фильтру</span>
        </div>
        <div class="row q-gutter-sm">
          <q-btn flat size="sm" label="Выбрать все по фильтру" @click="selectAllFiltered = true" />
          <q-btn flat size="sm" label="Снять выделение" @click="clearSelection" />
          <q-btn color="primary" size="sm" label="Групповые действия" @click="openBulkDialog()" />
        </div>
      </div>
    </q-banner>

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
      <!--
        Не ошибка: студент загружен, не хватает только документа об образовании.
        Строка стоит здесь потому, что до 24.08.2026 её не было вовсе: 580
        названий школ ушли в загрузку и исчезли молча, а ноль документов об
        образовании полтора месяца объясняли тем, что данных нет.
      -->
      <div v-if="store.importSummary.education_documents_skipped?.length" class="students-import-summary__notice">
        Документ об образовании не создан у
        {{ store.importSummary.education_documents_skipped.length }} строк: указано
        только учебное заведение, без серии и номера аттестата. Студенты загружены,
        школы — нет.
        <small>Строки: {{ store.importSummary.education_documents_skipped.slice(0, 20).join(', ')
          }}{{ store.importSummary.education_documents_skipped.length > 20 ? ' и другие' : '' }}.</small>
      </div>
    </q-banner>

    <div class="students-layout workspace-page" :class="{ 'workspace-page--card': Boolean(route.params.id) }">
      <div class="students-main workspace-page__list">
        <AppTable
          v-if="store.students.length || store.loading"
          :rows="store.students"
          :columns="columns"
          :loading="store.loading"
          :pagination="tablePagination"
          :rows-per-page-options="TABLE_ROWS_PER_PAGE_OPTIONS"
          v-model:selected="selectedRows"
          selection="multiple"
          :table-row-class-fn="tableRowClass"
          @update:pagination="updateTablePagination"
          :row-link="(row) => `/students/${row.id}`"
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
                <span>{{ formatPhone(props.row.phone, "—") }}</span>
                <small>{{ props.row.email || '—' }}</small>
              </div>
            </q-td>
          </template>

          <template #body-cell-card_completeness="props">
            <q-td :props="props">
              <span v-if="props.row.card_completeness?.complete" class="student-card-flag">
                <AppStatusBadge label="Полная" tone="success" />
              </span>
              <span v-else class="student-card-flag">
                <AppStatusBadge label="Неполная" tone="warning" />
                <q-tooltip v-if="missingParts(props.row).length">
                  {{ missingParts(props.row).join(' ') }}
                </q-tooltip>
              </span>
            </q-td>
          </template>

          <template #body-cell-actions="props">
            <q-td :props="props">
              <div class="students-row-actions">
                <q-btn v-if="canUpdate" flat round dense title="Редактировать" @click.stop="openEditForm(props.row)">
                  <Edit3 :size="16" />
                </q-btn>
                <q-btn v-if="canDelete" flat round dense color="negative" title="Удалить" @click.stop="requestDelete(props.row)">
                  <Trash2 :size="16" />
                </q-btn>
                <q-btn v-else-if="canRequestDeletion" flat round dense color="negative" title="Пометить на удаление" @click.stop="askDeletionRequest(props.row)">
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
          <q-btn v-if="canCreate" color="primary" label="Новый студент" @click="openCreateForm" />
        </AppEmptyState>
      </div>

      <aside class="students-side workspace-page__card">
        <WorkspaceBackBar />
        <StudentDetailsPanel
          :student="store.selectedStudent"
          :documents="store.selectedDocuments"
          :documents-loading="store.documentsLoading"
          :can-edit-documents="canUpdate"
          :saving-document="store.saving"
          :status-labels="statusLabels"
          :status-tones="statusTones"
          :attendance-summary="store.attendanceSummary"
          :grade-summary="store.gradeSummary"
          :loading="store.detailsLoading"
          @save-education-document="saveEducationDocument"
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

    <q-dialog v-model="bulkDialogVisible" persistent>
      <q-card style="min-width: 520px; max-width: 760px;">
        <q-card-section>
          <div class="text-h6">Групповые действия студентов</div>
          <div class="text-caption text-grey-7">Выбрано записей: {{ selectedCount }}</div>
        </q-card-section>
        <q-card-section class="q-gutter-md">
          <q-select v-model="bulkAction" outlined dense :options="bulkActions" emit-value map-options label="Действие" @update:model-value="bulkPreview = null" />
          <q-select v-if="bulkAction === 'assign_group'" v-model="bulkPayload.group_id" outlined dense :options="store.groupOptions" emit-value map-options label="Группа" />
          <q-select v-if="bulkAction === 'change_status'" v-model="bulkPayload.status" outlined dense :options="statusOptions" emit-value map-options label="Статус" />
          <q-input v-if="bulkAction === 'change_course'" v-model.number="bulkPayload.course" outlined dense type="number" min="1" max="6" label="Курс" />
          <q-input v-if="bulkAction === 'change_education_form'" v-model="bulkPayload.education_form" outlined dense label="Форма обучения" />
          <q-input v-if="bulkAction === 'change_funding_form'" v-model="bulkPayload.funding_form" outlined dense label="Форма финансирования" />
          <q-banner v-if="bulkAction === 'issue_digital_passes'" rounded class="bg-blue-1 text-blue-10">Активные QR-пропуска не дублируются: такие студенты будут пропущены.</q-banner>
          <q-banner v-if="bulkAction === 'issue_accounts'" rounded class="bg-orange-1 text-orange-10">
            Пароль показывается один раз, сразу после создания. Восстановить его нельзя — только сбросить и выдать новый.
            Распечатайте карточки или выгрузите CSV, не закрывая окно с результатом. Студенты, у которых учётная запись уже есть, будут пропущены.
          </q-banner>
          <q-banner v-if="bulkAction === 'archive_selected'" rounded class="bg-orange-1 text-orange-10">Архивирование не удаляет студентов и не затрагивает legacy.</q-banner>
          <q-card v-if="bulkPreview" flat bordered>
            <q-card-section>
              <div class="text-subtitle2">Предпросмотр</div>
              <div>Будет изменено: {{ bulkPreview.will_change }} · пропущено: {{ bulkPreview.skipped }} · ошибок: {{ bulkPreview.errors }}</div>
              <q-list dense separator class="q-mt-sm">
                <q-item v-for="item in bulkPreview.sample" :key="item.id">
                  <q-item-section>
                    <q-item-label>{{ item.name }}</q-item-label>
                    <q-item-label caption>{{ item.reason || 'Готово к изменению' }}</q-item-label>
                  </q-item-section>
                  <q-item-section side>{{ item.result }}</q-item-section>
                </q-item>
              </q-list>
            </q-card-section>
          </q-card>
        </q-card-section>
        <q-card-actions align="right">
          <q-btn flat label="Отмена" @click="bulkDialogVisible = false" />
          <q-btn flat label="Предпросмотр" :loading="store.saving" @click="previewBulkAction" />
          <q-btn color="primary" label="Применить" :loading="store.saving" :disable="!bulkPreview && bulkAction !== 'export_selected'" @click="applyBulkAction" />
        </q-card-actions>
      </q-card>
    </q-dialog>

    <q-dialog v-model="credentialsDialogVisible" persistent maximized>
      <q-card class="issued-accounts">
        <q-card-section class="issued-accounts__head">
          <div class="text-h6">Учётные записи созданы: {{ issuedCredentials.length }}</div>
          <q-banner rounded class="bg-orange-1 text-orange-10 q-mt-sm">
            Пароль показан один раз. Он нигде не сохранен и восстановить его нельзя — если карточка потеряна,
            остается только сбросить пароль и выдать новый. Распечатайте карточки или выгрузите CSV прямо сейчас.
          </q-banner>
        </q-card-section>

        <q-card-section class="issued-accounts__sheet">
          <div class="account-card-grid">
            <div v-for="row in issuedCredentials" :key="row.id" class="account-card">
              <div class="account-card__name">{{ row.name }}</div>
              <div v-if="row.group" class="account-card__group">{{ row.group }}</div>
              <dl class="account-card__creds">
                <dt>Логин</dt>
                <dd>{{ row.login }}</dd>
                <dt>Пароль</dt>
                <dd>{{ row.password }}</dd>
              </dl>
              <div class="account-card__hint">
                portal.skki.ru · смените пароль после первого входа
              </div>
            </div>
          </div>
        </q-card-section>

        <q-card-actions align="right" class="issued-accounts__actions">
          <q-btn flat label="Скачать CSV" @click="credentialsCsv" />
          <q-btn flat label="Печать карточек" @click="printCredentials" />
          <q-btn color="primary" label="Я сохранил пароли, закрыть" @click="credentialsDialogVisible = false" />
        </q-card-actions>
      </q-card>
    </q-dialog>

    <AppConfirmDialog
      v-model="deleteDialogVisible"
      title="Удалить студента?"
      :message="deletingStudent ? `Будет удалена запись: ${fullName(deletingStudent)}.` : 'Будет удалена выбранная запись.'"
      confirm-label="Удалить"
      tone="negative"
      @confirm="confirmDelete"
    />

    <DeletionRequestDialog
      v-model="deletionRequestVisible"
      subject-type="student"
      :subject-id="deletionRequestStudent?.id ?? null"
      :subject-label="deletionRequestStudent ? fullName(deletionRequestStudent) : ''"
      @requested="onDeletionRequested"
    />
  </AppPage>
</template>

<style scoped>
.students-layout { gap: 0; }
.students-main, .students-side { min-width: 0; }
.students-main { padding-right: 10px; }
.students-side { max-width: none; padding-left: 10px; }
@media (max-width: 1100px) { .students-layout { grid-template-columns: 1fr !important; gap: 16px; } .students-main, .students-side { padding: 0; } }

.issued-accounts__sheet { overflow: auto; }

.account-card-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
  gap: 12px;
}

.account-card {
  border: 1px solid #999;
  border-radius: 6px;
  padding: 12px;
  break-inside: avoid;
}

.account-card__name { font-size: 15px; font-weight: 600; }
.account-card__group { font-size: 12px; opacity: 0.75; }

.account-card__creds {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 2px 10px;
  margin: 10px 0 6px;
}

.account-card__creds dt { font-size: 12px; opacity: 0.7; }
.account-card__creds dd { margin: 0; font-family: monospace; font-size: 15px; font-weight: 600; }
.account-card__hint { font-size: 11px; opacity: 0.7; }
</style>

<!--
  Печать идет через браузер: PDF-библиотеки в бэкенде нет, а карточки все равно
  печатаются на бумагу и режутся. При печати со страницы убирается все, кроме
  листа карточек, иначе на бумагу уходит вся таблица студентов.
-->
<style>
@media print {
  body * { visibility: hidden; }
  .issued-accounts__sheet, .issued-accounts__sheet * { visibility: visible; }
  .issued-accounts__sheet {
    position: absolute;
    left: 0;
    top: 0;
    width: 100%;
    padding: 0;
  }
  .issued-accounts__head, .issued-accounts__actions { display: none !important; }
  .account-card-grid { grid-template-columns: repeat(3, 1fr); }
  .account-card { border-color: #000; }
}
</style>
