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
import SpecialtyDetailsPanel from './SpecialtyDetailsPanel.vue'
import SpecialtyFilters from './SpecialtyFilters.vue'
import SpecialtyFormPanel from './SpecialtyFormPanel.vue'
import { specialtyTitle, useSpecialtiesStore } from '../../stores/specialties'
import { usePermissions } from '../../composables/usePermissions'
import { useResizableWorkspace } from '../../composables/useResizableWorkspace'
import {
  TABLE_ROWS_PER_PAGE_OPTIONS,
  createTablePagination,
  persistTablePagination,
} from '../../services/tableSettings'

const permissions = usePermissions()
const store = useSpecialtiesStore()
const $q = useQuasar()
const route = useRoute()
const router = useRouter()
const ROWS_PER_PAGE_KEY = 'collegePortal.specialties.rowsPerPage'
const syncingQueryFromUi = ref(false)
const { resetSplitter, startResize, workspaceRef, workspaceStyle } = useResizableWorkspace({
  storageKey: 'collegePortal.specialties.splitter.v1',
  resizeBodyClass: 'specialties-splitter-resizing',
})

// Справочник целиком закрыт одним правом: и чтение, и правка — reference.manage.
const canManage = computed(() => permissions.hasPermission('reference.manage'))

const importFile = ref(null)
const formVisible = ref(false)
const editingSpecialty = ref(null)
const deletingSpecialty = ref(null)
const deleteDialogVisible = ref(false)
const tablePagination = ref(createTablePagination(ROWS_PER_PAGE_KEY, { sortBy: 'code', rowsPerPage: 20 }))

const columns = [
  { name: 'code', label: 'Код и наименование', field: 'code', align: 'left', sortable: true },
  { name: 'education_level', label: 'Уровень', field: 'education_level', align: 'left', sortable: true },
  { name: 'qualification', label: 'Квалификация', field: 'qualification', align: 'left', sortable: true },
  { name: 'programs', label: 'Программ', field: 'programs', align: 'left' },
  { name: 'actions', label: '', field: 'actions', align: 'right' },
]

const tableSubtitle = computed(() => `Найдено специальностей: ${store.filteredSpecialties.length}`)

function programCount(specialty) {
  return store.programs.filter((program) => Number(program.specialty_id) === Number(specialty.id)).length
}

function notifySuccess(message) {
  $q.notify({ type: 'positive', message, position: 'top-right', timeout: 1800 })
}

function tableRowClass(row) {
  return Number(row.id) === Number(store.selectedId) ? 'specialties-row--selected' : ''
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

function routeAction() {
  return route.query.action ? String(route.query.action) : ''
}

async function syncQuery({ selectedId = routeSelectedId(), searchText = routeSearchText() }) {
  const query = { ...route.query }

  if (selectedId) { query.selected = selectedId } else { delete query.selected }
  if (searchText) { query.search = searchText } else { delete query.search }

  syncingQueryFromUi.value = true
  await router.replace({ path: '/specialties', query })
  syncingQueryFromUi.value = false
}

async function selectSpecialty(specialty) {
  store.select(specialty)
  await syncQuery({ selectedId: specialty?.id || '' })
}

function openCreateForm() {
  if (!canManage.value) return
  editingSpecialty.value = null
  formVisible.value = true
}

function openEditForm(specialty) {
  if (!canManage.value) return
  editingSpecialty.value = specialty
  formVisible.value = true
}

async function saveSpecialty(payload) {
  const isEdit = Boolean(editingSpecialty.value?.id)
  await store.save(payload, editingSpecialty.value?.id || null)
  formVisible.value = false
  editingSpecialty.value = null
  notifySuccess(isEdit ? 'Специальность обновлена' : 'Специальность создана')
}

function requestDelete(specialty) {
  if (!canManage.value) return
  deletingSpecialty.value = specialty
  deleteDialogVisible.value = true
}

async function confirmDelete() {
  const name = deletingSpecialty.value ? specialtyTitle(deletingSpecialty.value) : 'Специальность'
  await store.remove(deletingSpecialty.value)
  deletingSpecialty.value = null
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
  notifySuccess('Импорт специальностей завершен')
}

async function exportSpecialties() {
  await store.exportCsv()
  notifySuccess('Экспорт специальностей подготовлен')
}

watch(
  () => [route.query.selected, route.query.search, route.query.action],
  async () => {
    if (syncingQueryFromUi.value) return

    store.setFilters({ search: routeSearchText() })
    store.selectById(routeSelectedId())

    if (routeAction() === 'create') {
      openCreateForm()
    }
  },
)

onMounted(async () => {
  store.setFilters({ search: routeSearchText() })
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
      title="Специальности"
      subtitle="Классификатор специальностей: код, уровень образования, квалификация и CSV-обмен."
    >
      <template #actions>
        <q-btn v-if="canManage" color="primary" @click="openCreateForm">
          <template #default>
            <Plus :size="16" />
            <span>Новая специальность</span>
          </template>
        </q-btn>
      </template>
    </PageHeader>

    <SpecialtyFilters
      :model-value="store.filters"
      :education-level-options="store.educationLevelOptions"
      :loading="store.loading"
      @apply="applyFilters"
      @reset="resetFilters"
      @update:model-value="store.setFilters"
    />

    <AppToolbar>
      <span>{{ tableSubtitle }}</span>
      <template #actions>
        <AppLoading v-if="store.loading" label="Загрузка специальностей..." />
        <q-file
          v-if="canManage"
          v-model="importFile"
          dense
          outlined
          clearable
          accept=".csv,text/csv,text/plain"
          class="specialties-import-field"
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
        <q-btn color="secondary" :disable="store.loading" @click="exportSpecialties">
          <template #default>
            <Download :size="16" />
            <span>Экспорт</span>
            <q-tooltip>Скачать CSV</q-tooltip>
          </template>
        </q-btn>
      </template>
    </AppToolbar>

    <AppErrorBanner :message="store.error" />

    <q-banner v-if="store.importSummary" rounded class="specialties-import-summary">
      <div>
        Импорт завершен:
        создано {{ store.importSummary.created }},
        обновлено {{ store.importSummary.updated }},
        строк с ошибками {{ store.importSummary.errors?.length || 0 }}.
      </div>
      <ul v-if="store.importSummary.errors?.length" class="specialties-import-summary__errors">
        <li v-for="errorRow in store.importSummary.errors" :key="errorRow.line">
          Строка {{ errorRow.line }}: {{ errorRow.messages?.join('; ') || 'ошибка импорта' }}
        </li>
      </ul>
    </q-banner>

    <div ref="workspaceRef" class="specialties-layout" :style="workspaceStyle">
      <div class="specialties-main">
        <AppTable
          v-if="store.filteredSpecialties.length || store.loading"
          :rows="store.filteredSpecialties"
          :columns="columns"
          :loading="store.loading"
          :pagination="tablePagination"
          :rows-per-page-options="TABLE_ROWS_PER_PAGE_OPTIONS"
          :table-row-class-fn="tableRowClass"
          @update:pagination="updateTablePagination"
          @row-click="(_, row) => selectSpecialty(row)"
        >
          <template #body-cell-code="props">
            <q-td :props="props">
              <button class="specialties-row-link" type="button" @click.stop="selectSpecialty(props.row)">
                {{ specialtyTitle(props.row) }}
              </button>
              <div v-if="props.row.description" class="specialties-row-description">
                {{ props.row.description }}
              </div>
            </q-td>
          </template>

          <template #body-cell-education_level="props">
            <q-td :props="props">{{ props.row.education_level || '—' }}</q-td>
          </template>

          <template #body-cell-qualification="props">
            <q-td :props="props">
              <div class="specialties-secondary-cell">
                <span>{{ props.row.qualification || '—' }}</span>
                <small>Срок: {{ props.row.normative_study_years ?? '—' }}</small>
              </div>
            </q-td>
          </template>

          <template #body-cell-programs="props">
            <q-td :props="props">{{ programCount(props.row) }}</q-td>
          </template>

          <template #body-cell-actions="props">
            <q-td :props="props">
              <div class="specialties-row-actions">
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
          title="Специальности не найдены"
          description="Измените фильтры, импортируйте CSV или создайте специальность вручную."
        >
          <q-btn v-if="canManage" color="primary" label="Новая специальность" @click="openCreateForm" />
        </AppEmptyState>
      </div>

      <WorkspaceSplitter label="Изменить ширину карточки специальности" @resize-start="startResize" @reset="resetSplitter" />

      <aside class="specialties-side">
        <SpecialtyDetailsPanel :specialty="store.selectedSpecialty" :programs="store.selectedSpecialtyPrograms" />
      </aside>
    </div>

    <q-dialog v-model="formVisible" persistent>
      <div class="specialties-form-dialog">
        <SpecialtyFormPanel
          :specialty="editingSpecialty"
          :saving="store.saving"
          @save="saveSpecialty"
          @cancel="formVisible = false"
        />
      </div>
    </q-dialog>

    <AppConfirmDialog
      v-model="deleteDialogVisible"
      title="Удалить специальность?"
      :message="deletingSpecialty ? `Будет удалена запись: ${specialtyTitle(deletingSpecialty)}. Программы и группы, привязанные к ней, останутся без специальности.` : 'Будет удалена выбранная запись.'"
      confirm-label="Удалить"
      tone="negative"
      @confirm="confirmDelete"
    />
  </AppPage>
</template>

<style scoped>
.specialties-layout { gap: 0; }
.specialties-main, .specialties-side { min-width: 0; }
.specialties-main { padding-right: 10px; }
.specialties-side { max-width: none; padding-left: 10px; }
.specialties-row-link { background: none; border: 0; padding: 0; color: inherit; font: inherit; cursor: pointer; text-align: left; }
.specialties-row-link:hover { text-decoration: underline; }
.specialties-row-description { color: var(--cp-text-muted, #64748b); font-size: 12px; }
.specialties-secondary-cell { display: flex; flex-direction: column; }
.specialties-secondary-cell small { color: var(--cp-text-muted, #64748b); }
.specialties-row-actions { display: flex; justify-content: flex-end; gap: 4px; }
.specialties-form-dialog { width: min(720px, 96vw); }
.specialties-import-field { max-width: 180px; }
.specialties-import-summary__errors { margin: 8px 0 0; padding-left: 18px; }
@media (max-width: 1100px) { .specialties-layout { grid-template-columns: 1fr !important; gap: 16px; } .specialties-main, .specialties-side { padding: 0; } }
</style>
