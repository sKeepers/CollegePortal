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
import WorkspaceSplitter from '../../components/workspace/WorkspaceSplitter.vue'
import EducationProgramDetailsPanel from './EducationProgramDetailsPanel.vue'
import EducationProgramFilters from './EducationProgramFilters.vue'
import EducationProgramFormPanel from './EducationProgramFormPanel.vue'
import { programTitle, useEducationProgramsStore } from '../../stores/educationPrograms'
import { usePermissions } from '../../composables/usePermissions'
import { useResizableWorkspace } from '../../composables/useResizableWorkspace'
import {
  TABLE_ROWS_PER_PAGE_OPTIONS,
  createTablePagination,
  persistTablePagination,
} from '../../services/tableSettings'

const permissions = usePermissions()
const store = useEducationProgramsStore()
const $q = useQuasar()
const route = useRoute()
const router = useRouter()
const ROWS_PER_PAGE_KEY = 'collegePortal.educationPrograms.rowsPerPage'
const syncingQueryFromUi = ref(false)
const { resetSplitter, startResize, workspaceRef, workspaceStyle } = useResizableWorkspace({
  storageKey: 'collegePortal.educationPrograms.splitter.v1',
  resizeBodyClass: 'programs-splitter-resizing',
})

// Справочник целиком закрыт одним правом: и чтение, и правка — reference.manage.
const canManage = computed(() => permissions.hasPermission('reference.manage'))

const importFile = ref(null)
const formVisible = ref(false)
const editingProgram = ref(null)
const deletingProgram = ref(null)
const deleteDialogVisible = ref(false)
const tablePagination = ref(createTablePagination(ROWS_PER_PAGE_KEY, { sortBy: 'name', rowsPerPage: 20 }))

const columns = [
  { name: 'name', label: 'Программа', field: 'name', align: 'left', sortable: true },
  { name: 'specialty', label: 'Специальность', field: 'specialty', align: 'left' },
  { name: 'year_start', label: 'Год набора', field: 'year_start', align: 'left', sortable: true },
  { name: 'study_form', label: 'Форма и срок', field: 'study_form', align: 'left', sortable: true },
  { name: 'is_active', label: 'Статус', field: 'is_active', align: 'left', sortable: true },
  { name: 'actions', label: '', field: 'actions', align: 'right' },
]

const tableSubtitle = computed(() => `Найдено программ: ${store.filteredPrograms.length}`)

function specialtyLabel(program) {
  return [program?.specialty?.code, program?.specialty?.name].filter(Boolean).join(' · ') || '—'
}

function notifySuccess(message) {
  $q.notify({ type: 'positive', message, position: 'top-right', timeout: 1800 })
}

function tableRowClass(row) {
  return Number(row.id) === Number(store.selectedId) ? 'programs-row--selected' : ''
}

function updateTablePagination(pagination) {
  tablePagination.value = pagination
  persistTablePagination(ROWS_PER_PAGE_KEY, pagination)
}

function routeSelectedId() {
  return route.query.selected ? String(route.query.selected) : ''
}

function routeSearchText() {
  return route.query.search ? String(route.query.search) : ''
}

function routeSpecialtyId() {
  return route.query.specialty ? String(route.query.specialty) : ''
}

function routeAction() {
  return route.query.action ? String(route.query.action) : ''
}

async function syncQuery({ selectedId = routeSelectedId(), searchText = routeSearchText() }) {
  const query = { ...route.query }

  if (selectedId) { query.selected = selectedId } else { delete query.selected }
  if (searchText) { query.search = searchText } else { delete query.search }

  syncingQueryFromUi.value = true
  await router.replace({ path: '/education-programs', query })
  syncingQueryFromUi.value = false
}

async function selectProgram(program) {
  store.select(program)
  await syncQuery({ selectedId: program?.id || '' })
}

function openCreateForm() {
  if (!canManage.value) return
  editingProgram.value = null
  formVisible.value = true
}

function openEditForm(program) {
  if (!canManage.value) return
  editingProgram.value = program
  formVisible.value = true
}

async function saveProgram(payload) {
  const isEdit = Boolean(editingProgram.value?.id)
  await store.save(payload, editingProgram.value?.id || null)
  formVisible.value = false
  editingProgram.value = null
  notifySuccess(isEdit ? 'Программа обновлена' : 'Программа создана')
}

function requestDelete(program) {
  if (!canManage.value) return
  deletingProgram.value = program
  deleteDialogVisible.value = true
}

async function confirmDelete() {
  const name = deletingProgram.value ? programTitle(deletingProgram.value) : 'Программа'
  await store.remove(deletingProgram.value)
  deletingProgram.value = null
  notifySuccess(`${name}: запись удалена`)
}

async function applyFilters(filters) {
  store.setFilters(filters)
  await syncQuery({ selectedId: '', searchText: filters.search })
}

async function resetFilters() {
  store.resetFilters()
  await syncQuery({ selectedId: '', searchText: '' })
}

async function handleImport(file) {
  if (!canManage.value || !file) return
  await store.importCsv(file)
  importFile.value = null
  notifySuccess('Импорт программ завершен')
}

async function exportPrograms() {
  await store.exportCsv()
  notifySuccess('Экспорт программ подготовлен')
}

watch(
  () => [route.query.selected, route.query.search, route.query.specialty, route.query.action],
  async () => {
    if (syncingQueryFromUi.value) return

    store.setFilters({ search: routeSearchText(), specialty_id: routeSpecialtyId() })
    store.selectById(routeSelectedId())

    if (routeAction() === 'create') {
      openCreateForm()
    }
  },
)

onMounted(async () => {
  // Специальность приходит ссылкой из карточки специальности.
  store.setFilters({ search: routeSearchText(), specialty_id: routeSpecialtyId() })
  store.selectById(routeSelectedId())
  await store.load()

  if (routeAction() === 'create') {
    openCreateForm()
  }
})
</script>

<template>
  <AppPage>
    <PageHeader
      title="Образовательные программы"
      subtitle="Программы по специальностям: год набора, форма и срок обучения, CSV-обмен."
    >
      <template #actions>
        <q-btn v-if="canManage" color="primary" @click="openCreateForm">
          <template #default>
            <Plus :size="16" />
            <span>Новая программа</span>
          </template>
        </q-btn>
      </template>
    </PageHeader>

    <EducationProgramFilters
      :model-value="store.filters"
      :specialty-options="store.specialtyOptions"
      :year-options="store.yearOptions"
      :study-form-options="store.studyFormOptions"
      :loading="store.loading"
      @apply="applyFilters"
      @reset="resetFilters"
      @update:model-value="store.setFilters"
    />

    <AppToolbar>
      <span>{{ tableSubtitle }}</span>
      <template #actions>
        <AppLoading v-if="store.loading" label="Загрузка программ..." />
        <q-file
          v-if="canManage"
          v-model="importFile"
          dense
          outlined
          clearable
          accept=".csv,text/csv,text/plain"
          class="programs-import-field"
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
        <q-btn color="secondary" :disable="store.loading" @click="exportPrograms">
          <template #default>
            <Download :size="16" />
            <span>Экспорт</span>
            <q-tooltip>Скачать CSV</q-tooltip>
          </template>
        </q-btn>
      </template>
    </AppToolbar>

    <AppErrorBanner :message="store.error" />

    <q-banner v-if="store.importSummary" rounded class="programs-import-summary">
      <div>
        Импорт завершен:
        создано {{ store.importSummary.created }},
        обновлено {{ store.importSummary.updated }},
        строк с ошибками {{ store.importSummary.errors?.length || 0 }}.
      </div>
      <ul v-if="store.importSummary.errors?.length" class="programs-import-summary__errors">
        <li v-for="errorRow in store.importSummary.errors" :key="errorRow.line">
          Строка {{ errorRow.line }}: {{ errorRow.messages?.join('; ') || 'ошибка импорта' }}
        </li>
      </ul>
    </q-banner>

    <div ref="workspaceRef" class="programs-layout" :style="workspaceStyle">
      <div class="programs-main">
        <AppTable
          v-if="store.filteredPrograms.length || store.loading"
          :rows="store.filteredPrograms"
          :columns="columns"
          :loading="store.loading"
          :pagination="tablePagination"
          :rows-per-page-options="TABLE_ROWS_PER_PAGE_OPTIONS"
          :table-row-class-fn="tableRowClass"
          @update:pagination="updateTablePagination"
          @row-click="(_, row) => selectProgram(row)"
        >
          <template #body-cell-name="props">
            <q-td :props="props">
              <button class="programs-row-link" type="button" @click.stop="selectProgram(props.row)">
                {{ props.row.name }}
              </button>
              <div v-if="props.row.description" class="programs-row-description">
                {{ props.row.description }}
              </div>
            </q-td>
          </template>

          <template #body-cell-specialty="props">
            <q-td :props="props">{{ specialtyLabel(props.row) }}</q-td>
          </template>

          <template #body-cell-year_start="props">
            <q-td :props="props">{{ props.row.year_start || '—' }}</q-td>
          </template>

          <template #body-cell-study_form="props">
            <q-td :props="props">
              <div class="programs-secondary-cell">
                <span>{{ props.row.study_form || '—' }}</span>
                <small>Срок: {{ props.row.study_years ?? '—' }}</small>
              </div>
            </q-td>
          </template>

          <template #body-cell-is_active="props">
            <q-td :props="props">{{ props.row.is_active ? 'Действует' : 'Не действует' }}</q-td>
          </template>

          <template #body-cell-actions="props">
            <q-td :props="props">
              <div class="programs-row-actions">
                <q-btn v-if="canManage" flat round dense title="Редактировать" @click.stop="openEditForm(props.row)">
                  <Edit3 :size="16" />
                </q-btn>
                <q-btn v-if="canManage" flat round dense color="negative" title="Удалить" @click.stop="requestDelete(props.row)">
                  <Trash2 :size="16" />
                </q-btn>
              </div>
            </q-td>
          </template>
        </AppTable>

        <AppEmptyState
          v-else
          title="Образовательные программы не найдены"
          description="Измените фильтры, импортируйте CSV или создайте программу вручную. Без программы нельзя завести группу."
        >
          <q-btn v-if="canManage" color="primary" label="Новая программа" @click="openCreateForm" />
        </AppEmptyState>
      </div>

      <WorkspaceSplitter label="Изменить ширину карточки программы" @resize-start="startResize" @reset="resetSplitter" />

      <aside class="programs-side">
        <EducationProgramDetailsPanel :program="store.selectedProgram" :groups="store.selectedProgramGroups" />
      </aside>
    </div>

    <q-dialog v-model="formVisible" persistent>
      <div class="programs-form-dialog">
        <EducationProgramFormPanel
          :program="editingProgram"
          :specialty-options="store.specialtyOptions"
          :saving="store.saving"
          @save="saveProgram"
          @cancel="formVisible = false"
        />
      </div>
    </q-dialog>

    <AppConfirmDialog
      v-model="deleteDialogVisible"
      title="Удалить образовательную программу?"
      :message="deletingProgram ? `Будет удалена запись: ${programTitle(deletingProgram)}. Группы и учебные планы, привязанные к ней, останутся без программы.` : 'Будет удалена выбранная запись.'"
      confirm-label="Удалить"
      tone="negative"
      @confirm="confirmDelete"
    />
  </AppPage>
</template>

<style scoped>
.programs-layout { gap: 0; }
.programs-main, .programs-side { min-width: 0; }
.programs-main { padding-right: 10px; }
.programs-side { max-width: none; padding-left: 10px; }
.programs-row-link { background: none; border: 0; padding: 0; color: inherit; font: inherit; cursor: pointer; text-align: left; }
.programs-row-link:hover { text-decoration: underline; }
.programs-row-description { color: var(--cp-text-muted, #64748b); font-size: 12px; }
.programs-secondary-cell { display: flex; flex-direction: column; }
.programs-secondary-cell small { color: var(--cp-text-muted, #64748b); }
.programs-row-actions { display: flex; justify-content: flex-end; gap: 4px; }
.programs-form-dialog { width: min(720px, 96vw); }
.programs-import-field { max-width: 180px; }
.programs-import-summary__errors { margin: 8px 0 0; padding-left: 18px; }
@media (max-width: 1100px) { .programs-layout { grid-template-columns: 1fr !important; gap: 16px; } .programs-main, .programs-side { padding: 0; } }
</style>
