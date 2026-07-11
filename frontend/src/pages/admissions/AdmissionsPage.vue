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
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'
import AdmissionDetailsPanel from './AdmissionDetailsPanel.vue'
import AdmissionFilters from './AdmissionFilters.vue'
import AdmissionFormPanel from './AdmissionFormPanel.vue'
import { useReferenceOptionsStore } from '../../stores/referenceOptions'
import { usePermissions } from '../../composables/usePermissions'
import {
  applicantName,
  documentsCompleteness,
  documentsCompletenessLabel,
  educationBaseLabel,
  formatDate,
  programLabel,
  statusLabel,
  statusTone,
  useAdmissionsStore,
} from '../../stores/admissions'
import {
  TABLE_ROWS_PER_PAGE_OPTIONS,
  createTablePagination,
  persistTablePagination,
} from '../../services/tableSettings'

const permissions = usePermissions()
const store = useAdmissionsStore()
const referenceOptions = useReferenceOptionsStore()
const $q = useQuasar()
const route = useRoute()
const router = useRouter()
const ADMISSIONS_ROWS_PER_PAGE_KEY = 'collegePortal.admissions.rowsPerPage'
const syncingQueryFromUi = ref(false)
const canCreate = computed(() => permissions.hasPermission('admissions.create') || permissions.hasPermission('admissions.edit'))
const canUpdate = computed(() => permissions.hasPermission('admissions.update') || permissions.hasPermission('admissions.edit'))
const canDelete = computed(() => permissions.hasPermission('admissions.delete') || permissions.hasPermission('admissions.edit'))
const canImport = computed(() => canUpdate.value)
const canExport = computed(() => permissions.hasPermission('admissions.update') || permissions.hasPermission('admissions.edit') || permissions.hasPermission('admissions.view'))

const importFile = ref(null)
const formVisible = ref(false)
const editingApplication = ref(null)
const deletingApplication = ref(null)
const deleteDialogVisible = ref(false)
const tablePagination = ref(createTablePagination(ADMISSIONS_ROWS_PER_PAGE_KEY, {
  sortBy: 'submitted_at',
  descending: true,
  rowsPerPage: 20,
}))

const selectedRows = ref([])
const selectAllFiltered = ref(false)
const bulkDialogVisible = ref(false)
const bulkAction = ref('')
const bulkPayload = ref({})
const bulkPreview = ref(null)

const bulkActions = computed(() => [
  { label: 'Изменить статус', value: 'change_status', permission: 'admissions.bulk_status' },
  { label: 'Документы предоставлены', value: 'mark_documents_provided', permission: 'admissions.bulk_documents' },
  { label: 'Рекомендовать', value: 'mark_recommended', permission: 'admissions.bulk_recommend' },
  { label: 'Назначить направление', value: 'assign_program', permission: 'admissions.bulk_assign' },
  { label: 'Зачислить', value: 'enroll_selected', permission: 'admissions.bulk_enroll' },
  { label: 'Экспортировать', value: 'export_selected', permission: 'admissions.bulk_export' },
].filter((action) => permissions.hasPermission(action.permission)))

const selectedCount = computed(() => (selectAllFiltered.value ? store.stats.total : selectedRows.value.length))
const pageSelectionCount = computed(() => selectedRows.value.length)
const currentPageCount = computed(() => store.filteredApplications.length)
const filterTotal = computed(() => store.stats.total || store.pagination?.total || store.filteredApplications.length)
const canSelectAllFiltered = computed(() => !selectAllFiltered.value && pageSelectionCount.value > 0 && filterTotal.value > pageSelectionCount.value)
const hasBulkSelection = computed(() => selectedCount.value > 0)

const columns = [
  { name: 'row_number', label: '№', field: 'row_number', align: 'right', style: 'width: 52px;', headerStyle: 'width: 52px;' },
  { name: 'applicant', label: 'Абитуриент', field: 'last_name', align: 'left', sortable: true, style: 'width: 30%; max-width: 250px;', headerStyle: 'width: 30%;' },
  { name: 'program', label: 'Специальность / программа', field: 'education_program_id', align: 'left', sortable: true, style: 'width: 34%; max-width: 300px;', headerStyle: 'width: 34%;' },
  { name: 'status', label: 'Статус и документы', field: 'status', align: 'left', sortable: true, style: 'width: 26%; min-width: 170px;', headerStyle: 'width: 26%;' },
  { name: 'actions', label: '', field: 'actions', align: 'right', style: 'width: 76px;', headerStyle: 'width: 76px;' },
]

const tableSubtitle = computed(() => `Найдено заявлений: ${filterTotal.value}; на странице: ${currentPageCount.value}`)
const statusOptions = computed(() => referenceOptions.options('applicant_application_statuses'))

function syncTableRowsNumber() {
  tablePagination.value = {
    ...tablePagination.value,
    rowsNumber: filterTotal.value,
  }
}

async function loadAdmissions(pagination = tablePagination.value) {
  await store.load(pagination)
  syncTableRowsNumber()
}

function notifySuccess(message) {
  $q.notify({
    type: 'positive',
    message,
    position: 'top-right',
    timeout: 1800,
  })
}

function notifyWarning(message) {
  $q.notify({
    type: 'warning',
    message,
    position: 'top-right',
    timeout: 2200,
  })
}

function clearSelection() {
  selectedRows.value = []
  selectAllFiltered.value = false
  bulkPreview.value = null
}

function selectAllByFilter() {
  selectAllFiltered.value = true
  bulkPreview.value = null
}

function requestSelectionReset() {
  if (!hasBulkSelection.value) return true
  clearSelection()
  notifyWarning('Выбор очищен из-за изменения фильтров')
  return true
}

function openBulkDialog(action = '') {
  if (!hasBulkSelection.value) return
  bulkAction.value = action || bulkActions.value[0]?.value || ''
  bulkPayload.value = {}
  bulkPreview.value = null
  bulkDialogVisible.value = true
}

function selectionScope() {
  if (selectAllFiltered.value) return 'filter'
  if (selectedRows.value.length === currentPageCount.value) return 'current_page'
  return 'selected_ids'
}

function bulkRequest() {
  return {
    ids: selectAllFiltered.value ? [] : selectedRows.value.map((row) => row.id),
    filter: selectAllFiltered.value ? { ...store.filters } : {},
    selection_scope: selectionScope(),
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
  notifySuccess(bulkAction.value === 'export_selected' ? 'Экспорт выбранных заявлений подготовлен' : 'Массовая операция выполнена')
  if (bulkAction.value !== 'export_selected') {
    await loadAdmissions()
    clearSelection()
    bulkDialogVisible.value = false
  }
}

function documentTone(application) {
  const status = documentsCompleteness(application)

  if (status === 'complete') return 'success'
  if (status === 'no_documents') return 'danger'
  return 'warning'
}

function rowNumber(props) {
  const rowsPerPage = Number(tablePagination.value.rowsPerPage || 0)
  const page = Number(tablePagination.value.page || 1)
  const index = Number(props.pageIndex ?? props.rowIndex ?? 0)

  return rowsPerPage === 0 ? index + 1 : ((page - 1) * rowsPerPage) + index + 1
}

function applicationTitle(application) {
  return applicantName(application) || `Заявление #${application?.id}`
}

function tableRowClass(row) {
  return Number(row.id) === Number(store.selectedId) ? 'admissions-row--selected' : ''
}

function updateTablePagination(pagination) {
  tablePagination.value = { ...pagination, rowsNumber: filterTotal.value }
  persistTablePagination(ADMISSIONS_ROWS_PER_PAGE_KEY, tablePagination.value)
}

async function handleTableRequest({ pagination }) {
  requestSelectionReset()
  tablePagination.value = { ...pagination, rowsNumber: filterTotal.value }
  persistTablePagination(ADMISSIONS_ROWS_PER_PAGE_KEY, tablePagination.value)
  await loadAdmissions(tablePagination.value)
}

function routeSelectedId() {
  return route.query.selected ? String(route.query.selected) : ''
}

function routeSearchText() {
  return route.query.search ? String(route.query.search) : ''
}

function routeStatus() {
  return route.query.status ? String(route.query.status) : ''
}

function routeProgram() {
  return route.query.program ? String(route.query.program) : ''
}

function routeDocumentsStatus() {
  return route.query.documents ? String(route.query.documents) : ''
}

function routeAction() {
  return route.query.action ? String(route.query.action) : ''
}

async function syncAdmissionQuery({ selectedId = routeSelectedId(), searchText = routeSearchText(), status = routeStatus(), program = routeProgram(), documentsStatus = routeDocumentsStatus() }) {
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

  if (status) {
    query.status = status
  } else {
    delete query.status
  }

  if (program) {
    query.program = program
  } else {
    delete query.program
  }

  if (documentsStatus) {
    query.documents = documentsStatus
  } else {
    delete query.documents
  }

  syncingQueryFromUi.value = true
  await router.replace({ path: '/admissions', query })
  syncingQueryFromUi.value = false
}

async function selectApplication(application) {
  store.selectApplication(application)
  await syncAdmissionQuery({ selectedId: application?.id || '' })
}

function openCreateForm() {
  if (!canCreate.value) return
  editingApplication.value = null
  formVisible.value = true
}

function openEditForm(application) {
  if (!canUpdate.value) return
  editingApplication.value = application
  formVisible.value = true
}

async function saveApplication(payload) {
  const isEdit = Boolean(editingApplication.value?.id)
  await store.save(payload, editingApplication.value?.id || null)
  formVisible.value = false
  editingApplication.value = null
  notifySuccess(isEdit ? 'Заявление обновлено' : 'Заявление создано')
}

function requestDelete(application) {
  if (!canDelete.value) return
  deletingApplication.value = application
  deleteDialogVisible.value = true
}

async function confirmDelete() {
  const name = deletingApplication.value ? applicationTitle(deletingApplication.value) : 'Заявление'
  await store.remove(deletingApplication.value)
  deletingApplication.value = null
  notifySuccess(`${name}: заявление удалено`)
}

async function applyFilters(filters) {
  requestSelectionReset()
  store.setFilters(filters)
  await syncAdmissionQuery({
    selectedId: '',
    searchText: filters.search,
    status: filters.status,
    program: filters.educationProgramId,
    documentsStatus: filters.documentsStatus,
  })
  await loadAdmissions()
}

async function resetFilters() {
  requestSelectionReset()
  store.resetFilters()
  await syncAdmissionQuery({ selectedId: '', searchText: '', status: '', program: '', documentsStatus: '' })
  await loadAdmissions()
}

async function applyQuickQueue(queue) {
  requestSelectionReset()
  const filters = {
    ...store.filters,
    status: queue.status,
    documentsStatus: queue.documentsStatus || '',
  }

  if (queue.key === 'all') {
    store.resetFilters()
    await syncAdmissionQuery({ selectedId: '', searchText: '', status: '', program: '', documentsStatus: '' })
    await loadAdmissions()
    return
  }

  store.setFilters(filters)
  await syncAdmissionQuery({ selectedId: '', searchText: filters.search, status: filters.status, program: filters.educationProgramId, documentsStatus: filters.documentsStatus })
  await loadAdmissions()
}

async function handleImport(file) {
  if (!canImport.value) return
  if (!file) {
    return
  }

  await store.importCsv(file)
  importFile.value = null
  notifySuccess('Импорт заявлений завершен')

  if (store.importSummary?.errors?.length) {
    notifyWarning(`Есть строки с ошибками: ${store.importSummary.errors.length}`)
  }
}

async function exportAdmissions() {
  if (!canExport.value) return
  await store.exportCsv()
  notifySuccess('Экспорт заявлений подготовлен')
}

async function enrollApplication(payload) {
  if (!canUpdate.value) return
  const data = await store.enroll(store.selectedApplication, payload)
  notifySuccess(data?.student ? 'Абитуриент зачислен в студенты' : 'Зачисление выполнено')
}

async function updateDocument(document, payload) {
  await store.updateDocument(store.selectedApplication, document, payload)
  notifySuccess(payload.is_received ? 'Документ отмечен как полученный' : 'Отметка документа снята')
}

watch(
  () => [route.query.selected, route.query.search, route.query.status, route.query.program, route.query.documents, route.query.action],
  async () => {
    if (syncingQueryFromUi.value) {
      return
    }

    store.setFilters({
      search: routeSearchText(),
      status: routeStatus(),
      educationProgramId: routeProgram(),
      documentsStatus: routeDocumentsStatus(),
    })
    await loadAdmissions()
    store.selectApplicationById(routeSelectedId())

    if (routeAction() === 'create') {
      openCreateForm()
    }
  },
)

onMounted(async () => {
  await referenceOptions.loadCatalog('applicant_application_statuses')
  store.setFilters({
    search: routeSearchText(),
    status: routeStatus(),
    educationProgramId: routeProgram(),
    documentsStatus: routeDocumentsStatus(),
  })
  store.selectApplicationById(routeSelectedId())
  await loadAdmissions()

  if (routeAction() === 'create') {
    openCreateForm()
  }
})
</script>

<template>
  <AppPage>
    <PageHeader
      title="Приемная комиссия"
      subtitle="Реестр заявлений, документы, статусы, CSV-обмен и зачисление абитуриентов."
    >
      <template #actions>
        <q-btn v-if="canCreate" color="primary" @click="openCreateForm">
          <template #default>
            <Plus :size="16" />
            <span>Новое заявление</span>
          </template>
        </q-btn>
      </template>
    </PageHeader>

    <div class="admissions-quick-statuses">
      <button
        v-for="queue in store.quickQueues"
        :key="queue.key"
        type="button"
        class="admissions-quick-status"
        :class="`admissions-quick-status--${queue.tone}`"
        @click="applyQuickQueue(queue)"
      >
        <span>{{ queue.label }}</span>
        <strong>{{ queue.value }}</strong>
      </button>
    </div>

    <AdmissionFilters
      :model-value="store.filters"
      :specialty-options="store.specialtyOptions"
      :education-program-options="store.educationProgramOptions"
      :status-options="statusOptions"
      :loading="store.loading"
      @apply="applyFilters"
      @reset="resetFilters"
      @update:model-value="store.setFilters"
    />

    <AppToolbar>
      <span>{{ tableSubtitle }}</span>
      <template #actions>
        <AppLoading v-if="store.loading" label="Загрузка заявлений..." />
        <q-file
          v-if="canImport"
          v-model="importFile"
          dense
          outlined
          clearable
          accept=".csv,text/csv,text/plain"
          class="admissions-import-field"
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
          <q-tooltip>{{ hasBulkSelection ? `Выбрано: ${selectedCount}` : 'Выберите заявления в таблице' }}</q-tooltip>
        </q-btn>
        <q-btn flat :disable="store.loading" @click="loadAdmissions()">
          <template #default>
            <RefreshCw :size="16" />
            <span>Обновить</span>
          </template>
        </q-btn>
        <q-btn v-if="canExport" color="secondary" :disable="store.loading" @click="exportAdmissions">
          <template #default>
            <Download :size="16" />
            <span>Экспорт</span>
            <q-tooltip>Скачать CSV</q-tooltip>
          </template>
        </q-btn>
      </template>
    </AppToolbar>


    <q-banner v-if="hasBulkSelection" rounded class="admissions-import-summary">
      <div class="row items-center justify-between q-gutter-sm">
        <div>
          <template v-if="selectAllFiltered">
            Выбраны все результаты фильтра: <strong>{{ selectedCount }}</strong>
          </template>
          <template v-else>
            Выбрано {{ pageSelectionCount }} заявлений на текущей странице
          </template>
        </div>
        <div class="row q-gutter-sm">
          <q-btn
            v-if="canSelectAllFiltered"
            flat
            size="sm"
            :label="`Выбрать все ${filterTotal} заявлений по текущему фильтру`"
            @click="selectAllByFilter"
          />
          <q-btn flat size="sm" label="Снять выделение" @click="clearSelection" />
          <q-btn color="primary" size="sm" label="Групповые действия" @click="openBulkDialog()" />
        </div>
      </div>
    </q-banner>

    <AppErrorBanner :message="store.error" />

    <q-banner v-if="store.importSummary" rounded class="admissions-import-summary">
      <div>
        Импорт завершен:
        создано {{ store.importSummary.created }},
        обновлено {{ store.importSummary.updated }},
        строк с ошибками {{ store.importSummary.errors?.length || 0 }}.
      </div>
      <ul v-if="store.importSummary.errors?.length" class="admissions-import-summary__errors">
        <li v-for="errorRow in store.importSummary.errors" :key="errorRow.line">
          Строка {{ errorRow.line }}: {{ errorRow.messages?.join('; ') || 'ошибка импорта' }}
        </li>
      </ul>
    </q-banner>

    <div class="admissions-layout">
      <div class="admissions-main">
        <AppTable
          v-if="store.filteredApplications.length || store.loading"
          :rows="store.filteredApplications"
          :columns="columns"
          :loading="store.loading"
          :pagination="tablePagination"
          :rows-per-page-options="TABLE_ROWS_PER_PAGE_OPTIONS"
          v-model:selected="selectedRows"
          selection="multiple"
          :table-row-class-fn="tableRowClass"
          @update:pagination="updateTablePagination"
          @request="handleTableRequest"
          @row-click="(_, row) => selectApplication(row)"
        >
          <template #body-cell-row_number="props">
            <q-td :props="props" class="text-grey-7">
              {{ rowNumber(props) }}
            </q-td>
          </template>

          <template #body-cell-applicant="props">
            <q-td :props="props">
              <button class="admissions-row-link" type="button" @click.stop="selectApplication(props.row)">
                {{ applicationTitle(props.row) }}
              </button>
              <div class="admissions-secondary-cell">
                <small>{{ [props.row.phone, props.row.email].filter(Boolean).join(' · ') || 'Контакты не указаны' }}</small>
                <small>{{ educationBaseLabel(props.row.education_base) }} · подано {{ formatDate(props.row.submitted_at) }}</small>
              </div>
            </q-td>
          </template>

          <template #body-cell-program="props">
            <q-td :props="props">
              <div class="admissions-secondary-cell">
                <span>{{ props.row.education_program?.specialty?.name || '—' }}</span>
                <small>{{ programLabel(props.row.education_program) || 'Программа не указана' }}</small>
              </div>
            </q-td>
          </template>

          <template #body-cell-status="props">
            <q-td :props="props">
              <div class="admissions-status-cell">
                <AppStatusBadge :label="statusLabel(props.row.status)" :tone="statusTone(props.row.status)" />
                <AppStatusBadge
                  :label="documentsCompletenessLabel(props.row)"
                  :tone="documentTone(props.row)"
                />
              </div>
            </q-td>
          </template>

          <template #body-cell-actions="props">
            <q-td :props="props">
              <div class="admissions-row-actions">
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
          title="Заявления не найдены"
          description="Измените фильтры, импортируйте CSV или создайте заявление вручную."
        >
          <q-btn v-if="canCreate" color="primary" label="Новое заявление" @click="openCreateForm" />
        </AppEmptyState>
      </div>

      <aside class="admissions-side">
        <AdmissionDetailsPanel
          :application="store.selectedApplication"
          :documents="store.selectedApplicationDocuments"
          :events="store.selectedApplicationEvents"
          :group-options="store.groupOptions"
          :saving="store.saving"
          @enroll="enrollApplication"
          @update-document="updateDocument"
        />
      </aside>
    </div>

    <q-dialog v-model="formVisible" persistent>
      <div class="admissions-form-dialog">
        <AdmissionFormPanel
          :application="editingApplication"
          :education-program-options="store.educationProgramOptions"
          :status-options="statusOptions"
          :saving="store.saving"
          @save="saveApplication"
          @cancel="formVisible = false"
        />
      </div>
    </q-dialog>


    <q-dialog v-model="bulkDialogVisible" persistent>
      <q-card style="min-width: 520px; max-width: 760px;">
        <q-card-section>
          <div class="text-h6">Групповые действия приемной комиссии</div>
          <div class="text-caption text-grey-7">Выбрано заявлений: {{ selectedCount }}</div>
        </q-card-section>
        <q-card-section class="q-gutter-md">
          <q-select v-model="bulkAction" outlined dense :options="bulkActions" emit-value map-options label="Действие" @update:model-value="bulkPreview = null" />
          <q-select v-if="bulkAction === 'change_status'" v-model="bulkPayload.status" outlined dense :options="statusOptions" emit-value map-options label="Статус" />
          <template v-if="bulkAction === 'assign_program'">
            <q-select v-model="bulkPayload.education_program_id" outlined dense :options="store.educationProgramOptions" emit-value map-options label="Образовательная программа" />
            <q-input v-model="bulkPayload.competition_name" outlined dense label="Конкурс / направление" />
          </template>
          <template v-if="bulkAction === 'enroll_selected'">
            <q-select v-model="bulkPayload.group_id" outlined dense :options="store.groupOptions" emit-value map-options label="Группа для зачисления" />
            <q-input v-model="bulkPayload.enrollment_date" outlined dense type="date" label="Дата зачисления" />
          </template>
          <q-banner v-if="bulkAction === 'mark_documents_provided'" rounded class="bg-blue-1 text-blue-10">Все документы выбранных заявлений будут отмечены как полученные.</q-banner>
          <q-banner v-if="bulkAction === 'enroll_selected'" rounded class="bg-orange-1 text-orange-10">Перед зачислением будет проверена комплектность документов и отсутствие дублей студентов.</q-banner>
          <q-card v-if="bulkPreview" flat bordered>
            <q-card-section>
              <div class="text-subtitle2">Предпросмотр</div>
              <div>Область: {{ bulkPreview.scope_label }} · найдено: {{ bulkPreview.found }}</div>
              <div>Будет изменено: {{ bulkPreview.will_change }} · уже соответствует: {{ bulkPreview.already_set || 0 }} · пропущено: {{ bulkPreview.skipped }} · ошибок: {{ bulkPreview.errors }}</div>
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

    <AppConfirmDialog
      v-model="deleteDialogVisible"
      title="Удалить заявление?"
      :message="deletingApplication ? `Будет удалено заявление: ${applicationTitle(deletingApplication)}.` : 'Будет удалена выбранная запись.'"
      confirm-label="Удалить"
      tone="negative"
      @confirm="confirmDelete"
    />
  </AppPage>
</template>
