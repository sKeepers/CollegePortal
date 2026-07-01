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
import {
  applicantName,
  documentsCompleteness,
  documentsCompletenessLabel,
  educationBaseLabel,
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

const store = useAdmissionsStore()
const $q = useQuasar()
const route = useRoute()
const router = useRouter()
const ADMISSIONS_ROWS_PER_PAGE_KEY = 'collegePortal.admissions.rowsPerPage'
const syncingQueryFromUi = ref(false)

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

const columns = [
  { name: 'applicant', label: 'Абитуриент', field: 'last_name', align: 'left', sortable: true, style: 'width: 30%; max-width: 250px;', headerStyle: 'width: 30%;' },
  { name: 'program', label: 'Специальность / программа', field: 'education_program_id', align: 'left', sortable: true, style: 'width: 34%; max-width: 300px;', headerStyle: 'width: 34%;' },
  { name: 'status', label: 'Статус и документы', field: 'status', align: 'left', sortable: true, style: 'width: 26%; min-width: 170px;', headerStyle: 'width: 26%;' },
  { name: 'actions', label: '', field: 'actions', align: 'right', style: 'width: 76px;', headerStyle: 'width: 76px;' },
]

const tableSubtitle = computed(() => `Найдено заявлений: ${store.filteredApplications.length}`)

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

function applicationTitle(application) {
  return applicantName(application) || `Заявление #${application?.id}`
}

function tableRowClass(row) {
  return Number(row.id) === Number(store.selectedId) ? 'admissions-row--selected' : ''
}

function updateTablePagination(pagination) {
  tablePagination.value = pagination
  persistTablePagination(ADMISSIONS_ROWS_PER_PAGE_KEY, pagination)
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

function routeAction() {
  return route.query.action ? String(route.query.action) : ''
}

async function syncAdmissionQuery({ selectedId = routeSelectedId(), searchText = routeSearchText(), status = routeStatus(), program = routeProgram() }) {
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

  syncingQueryFromUi.value = true
  await router.replace({ path: '/admissions', query })
  syncingQueryFromUi.value = false
}

async function selectApplication(application) {
  store.selectApplication(application)
  await syncAdmissionQuery({ selectedId: application?.id || '' })
}

function openCreateForm() {
  editingApplication.value = null
  formVisible.value = true
}

function openEditForm(application) {
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
  store.setFilters(filters)
  await syncAdmissionQuery({
    selectedId: '',
    searchText: filters.search,
    status: filters.status,
    program: filters.educationProgramId,
  })
}

async function resetFilters() {
  store.resetFilters()
  await syncAdmissionQuery({ selectedId: '', searchText: '', status: '', program: '' })
}

async function applyQuickQueue(queue) {
  const filters = {
    ...store.filters,
    status: queue.status,
    completeness: queue.completeness,
  }

  if (queue.key === 'all') {
    store.resetFilters()
    await syncAdmissionQuery({ selectedId: '', searchText: '', status: '', program: '' })
    return
  }

  store.setFilters(filters)
  await syncAdmissionQuery({ selectedId: '', searchText: filters.search, status: filters.status, program: filters.educationProgramId })
}

async function handleImport(file) {
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
  await store.exportCsv()
  notifySuccess('Экспорт заявлений подготовлен')
}

async function enrollApplication(payload) {
  const data = await store.enroll(store.selectedApplication, payload)
  notifySuccess(data?.student ? 'Абитуриент зачислен в студенты' : 'Зачисление выполнено')
}

async function updateDocument(document, payload) {
  await store.updateDocument(store.selectedApplication, document, payload)
  notifySuccess(payload.is_received ? 'Документ отмечен как полученный' : 'Отметка документа снята')
}

watch(
  () => [route.query.selected, route.query.search, route.query.status, route.query.program, route.query.action],
  async () => {
    if (syncingQueryFromUi.value) {
      return
    }

    store.setFilters({
      search: routeSearchText(),
      status: routeStatus(),
      educationProgramId: routeProgram(),
    })
    store.selectApplicationById(routeSelectedId())

    if (routeAction() === 'create') {
      openCreateForm()
    }
  },
)

onMounted(async () => {
  store.setFilters({
    search: routeSearchText(),
    status: routeStatus(),
    educationProgramId: routeProgram(),
  })
  store.selectApplicationById(routeSelectedId())
  await store.load()

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
        <q-btn color="primary" @click="openCreateForm">
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
        <q-btn flat :disable="store.loading" @click="store.load">
          <template #default>
            <RefreshCw :size="16" />
            <span>Обновить</span>
          </template>
        </q-btn>
        <q-btn color="secondary" :disable="store.loading" @click="exportAdmissions">
          <template #default>
            <Download :size="16" />
            <span>Экспорт</span>
            <q-tooltip>Скачать CSV</q-tooltip>
          </template>
        </q-btn>
      </template>
    </AppToolbar>

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
          :table-row-class-fn="tableRowClass"
          @update:pagination="updateTablePagination"
          @row-click="(_, row) => selectApplication(row)"
        >
          <template #body-cell-applicant="props">
            <q-td :props="props">
              <button class="admissions-row-link" type="button" @click.stop="selectApplication(props.row)">
                {{ applicationTitle(props.row) }}
              </button>
              <div class="admissions-secondary-cell">
                <small>{{ [props.row.phone, props.row.email].filter(Boolean).join(' · ') || 'Контакты не указаны' }}</small>
                <small>{{ educationBaseLabel(props.row.education_base) }} · подано {{ props.row.submitted_at || '—' }}</small>
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
                  :tone="documentsCompleteness(props.row) === 'complete' ? 'success' : 'warning'"
                />
              </div>
            </q-td>
          </template>

          <template #body-cell-actions="props">
            <q-td :props="props">
              <div class="admissions-row-actions">
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
          title="Заявления не найдены"
          description="Измените фильтры, импортируйте CSV или создайте заявление вручную."
        >
          <q-btn color="primary" label="Новое заявление" @click="openCreateForm" />
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
          :saving="store.saving"
          @save="saveApplication"
          @cancel="formVisible = false"
        />
      </div>
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
