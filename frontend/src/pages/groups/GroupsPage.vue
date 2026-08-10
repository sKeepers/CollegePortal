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
import GroupDetailsPanel from './GroupDetailsPanel.vue'
import GroupFilters from './GroupFilters.vue'
import GroupFormPanel from './GroupFormPanel.vue'
import { useGroupsStore } from '../../stores/groups'
import { usePermissions } from '../../composables/usePermissions'
import { useResizableWorkspace } from '../../composables/useResizableWorkspace'
import {
  TABLE_ROWS_PER_PAGE_OPTIONS,
  createTablePagination,
  persistTablePagination,
} from '../../services/tableSettings'

const permissions = usePermissions()
const store = useGroupsStore()
const $q = useQuasar()
const route = useRoute()
const router = useRouter()
const GROUPS_ROWS_PER_PAGE_KEY = 'collegePortal.groups.rowsPerPage'
const syncingQueryFromUi = ref(false)
const { resetSplitter, startResize, workspaceRef, workspaceStyle } = useResizableWorkspace({ storageKey: 'collegePortal.groups.splitter.v1', resizeBodyClass: 'groups-splitter-resizing' })
const canCreate = computed(() => permissions.hasPermission('groups.create') || permissions.hasPermission('groups.edit'))
const canUpdate = computed(() => permissions.hasPermission('groups.update') || permissions.hasPermission('groups.edit'))
// Удаляет группу только администратор. Кнопки «Пометить на удаление» здесь нет
// намеренно: в корзину кладутся карточки людей, а группа — не карточка, у
// `groups` нет мягкого удаления, и на неё ссылается `students.group_id`.
const canDelete = computed(() => permissions.hasPermission('trash.manage'))
const canImport = computed(() => canUpdate.value)
const canExport = computed(() => permissions.hasPermission('groups.update') || permissions.hasPermission('groups.edit') || permissions.hasPermission('groups.view'))

const importFile = ref(null)
const formVisible = ref(false)
const editingGroup = ref(null)
const deletingGroup = ref(null)
const deleteDialogVisible = ref(false)
const tablePagination = ref(createTablePagination(GROUPS_ROWS_PER_PAGE_KEY, {
  sortBy: 'name',
  rowsPerPage: 20,
}))

const columns = [
  {
    name: 'name',
    label: 'Группа',
    field: 'name',
    align: 'left',
    sortable: true,
  },
  {
    name: 'course',
    label: 'Курс',
    field: 'course',
    align: 'left',
    sortable: true,
  },
  {
    name: 'year_start',
    label: 'Год набора',
    field: 'year_start',
    align: 'left',
    sortable: true,
  },
  {
    name: 'specialty',
    label: 'Специальность',
    field: 'specialty',
    align: 'left',
  },
  {
    name: 'program',
    label: 'Программа',
    field: (row) => programLabel(row.education_program),
    align: 'left',
  },
  {
    name: 'curator',
    label: 'Куратор',
    field: (row) => teacherName(row.curator),
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
  `Найдено групп: ${store.filteredGroups.length}`
))

function teacherName(teacher) {
  return [teacher?.last_name, teacher?.first_name, teacher?.middle_name].filter(Boolean).join(' ')
}

function programLabel(program) {
  return [
    program?.name,
    program?.specialty?.code,
    program?.year_start,
    program?.study_form,
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
  return Number(row.id) === Number(store.selectedId) ? 'groups-row--selected' : ''
}

function updateTablePagination(pagination) {
  tablePagination.value = pagination
  persistTablePagination(GROUPS_ROWS_PER_PAGE_KEY, pagination)
}

function routeSelectedId() {
  return route.query.selected ? String(route.query.selected) : ''
}

function routeAction() {
  return route.query.action ? String(route.query.action) : ''
}

async function syncSelectedQuery(groupId) {
  const query = { ...route.query }

  if (groupId) {
    query.selected = groupId
  } else {
    delete query.selected
  }

  syncingQueryFromUi.value = true
  await router.replace({ path: '/groups', query })
  syncingQueryFromUi.value = false
}

async function selectGroup(group) {
  store.selectGroup(group)
  await syncSelectedQuery(group?.id || '')
}

function openCreateForm() {
  if (!canCreate.value) return
  editingGroup.value = null
  formVisible.value = true
}

function openEditForm(group) {
  if (!canUpdate.value) return
  editingGroup.value = group
  formVisible.value = true
}

async function saveGroup(payload) {
  const isEdit = Boolean(editingGroup.value?.id)
  await store.save(payload, editingGroup.value?.id || null)
  formVisible.value = false
  editingGroup.value = null
  notifySuccess(isEdit ? 'Группа обновлена' : 'Группа создана')
}

function requestDelete(group) {
  if (!canDelete.value) return
  deletingGroup.value = group
  deleteDialogVisible.value = true
}

async function confirmDelete() {
  const name = deletingGroup.value?.name || 'Группа'
  await store.remove(deletingGroup.value)
  deletingGroup.value = null
  notifySuccess(`${name}: запись удалена`)
}

function applyFilters(filters) {
  store.setFilters(filters)
}

function resetFilters() {
  store.resetFilters()
}

async function handleImport(file) {
  if (!canImport.value) return
  if (!file) {
    return
  }

  await store.importCsv(file)
  importFile.value = null
  notifySuccess('Импорт групп завершен')
}

async function exportGroups() {
  if (!canExport.value) return
  await store.exportCsv()
  notifySuccess('Экспорт групп подготовлен')
}

watch(
  () => [route.query.selected, route.query.action],
  () => {
    if (syncingQueryFromUi.value) {
      return
    }

    store.selectGroupById(routeSelectedId())

    if (routeAction() === 'create') {
      openCreateForm()
    }
  },
)

onMounted(async () => {
  store.selectGroupById(routeSelectedId())
  await store.load()

  if (routeAction() === 'create') {
    openCreateForm()
  }
})
</script>

<template>
  <AppPage>
    <PageHeader
      title="Группы"
      subtitle="Учебные группы, курсы, программы, кураторы и CSV-обмен."
    >
      <template #actions>
        <q-btn v-if="canCreate" color="primary" @click="openCreateForm">
          <template #default>
            <Plus :size="16" />
            <span>Новая группа</span>
          </template>
        </q-btn>
      </template>
    </PageHeader>

    <GroupFilters
      :model-value="store.filters"
      :course-options="store.courseOptions"
      :education-program-options="store.educationProgramOptions"
      :loading="store.loading"
      @apply="applyFilters"
      @reset="resetFilters"
      @update:model-value="store.setFilters"
    />

    <AppToolbar>
      <span>{{ tableSubtitle }}</span>
      <template #actions>
        <AppLoading v-if="store.loading" label="Загрузка групп..." />
        <q-file
          v-if="canImport"
          v-model="importFile"
          dense
          outlined
          clearable
          accept=".csv,text/csv,text/plain"
          class="groups-import-field"
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
        <q-btn v-if="canExport" color="secondary" :disable="store.loading" @click="exportGroups">
          <template #default>
            <Download :size="16" />
            <span>Экспорт</span>
            <q-tooltip>Скачать CSV</q-tooltip>
          </template>
        </q-btn>
      </template>
    </AppToolbar>

    <AppErrorBanner :message="store.error" />

    <q-banner v-if="store.importSummary" rounded class="groups-import-summary">
      <div>
        Импорт завершен:
        создано {{ store.importSummary.created }},
        обновлено {{ store.importSummary.updated }},
        строк с ошибками {{ store.importSummary.errors?.length || 0 }}.
      </div>
      <ul v-if="store.importSummary.errors?.length" class="groups-import-summary__errors">
        <li v-for="errorRow in store.importSummary.errors" :key="errorRow.line">
          Строка {{ errorRow.line }}: {{ errorRow.messages?.join('; ') || 'ошибка импорта' }}
        </li>
      </ul>
    </q-banner>

    <div ref="workspaceRef" class="groups-layout" :style="workspaceStyle">
      <div class="groups-main">
        <AppTable
          v-if="store.filteredGroups.length || store.loading"
          :rows="store.filteredGroups"
          :columns="columns"
          :loading="store.loading"
          :pagination="tablePagination"
          :rows-per-page-options="TABLE_ROWS_PER_PAGE_OPTIONS"
          :table-row-class-fn="tableRowClass"
          @update:pagination="updateTablePagination"
          @row-click="(_, row) => selectGroup(row)"
        >
          <template #body-cell-name="props">
            <q-td :props="props">
              <button class="groups-row-link" type="button" @click.stop="selectGroup(props.row)">
                {{ props.row.name }}
              </button>
            </q-td>
          </template>

          <template #body-cell-program="props">
            <q-td :props="props">
              <div class="groups-secondary-cell">
                <span>{{ props.row.education_program?.name || '—' }}</span>
                <small>{{ props.row.education_program?.study_form || '—' }}</small>
              </div>
            </q-td>
          </template>

          <template #body-cell-curator="props">
            <q-td :props="props">
              {{ teacherName(props.row.curator) || '—' }}
            </q-td>
          </template>

          <template #body-cell-actions="props">
            <q-td :props="props">
              <div class="groups-row-actions">
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
          title="Группы не найдены"
          description="Измените фильтры, импортируйте CSV или создайте новую группу вручную."
        >
          <q-btn v-if="canCreate" color="primary" label="Новая группа" @click="openCreateForm" />
        </AppEmptyState>
      </div>

      <WorkspaceSplitter label="Изменить ширину карточки группы" @resize-start="startResize" @reset="resetSplitter" />

      <aside class="groups-side">
        <GroupDetailsPanel :group="store.selectedGroup" />
      </aside>
    </div>

    <q-dialog v-model="formVisible" persistent>
      <div class="groups-form-dialog">
        <GroupFormPanel
          :group="editingGroup"
          :education-program-options="store.educationProgramOptions"
          :teacher-options="store.teacherOptions"
          :saving="store.saving"
          @save="saveGroup"
          @cancel="formVisible = false"
        />
      </div>
    </q-dialog>

    <AppConfirmDialog
      v-model="deleteDialogVisible"
      title="Удалить группу?"
      :message="deletingGroup ? `Будет удалена запись: ${deletingGroup.name}.` : 'Будет удалена выбранная запись.'"
      confirm-label="Удалить"
      tone="negative"
      @confirm="confirmDelete"
    />
  </AppPage>
</template>

<style scoped>
.groups-layout { gap: 0; }
.groups-main, .groups-side { min-width: 0; }
.groups-main { padding-right: 10px; }
.groups-side { max-width: none; padding-left: 10px; }
@media (max-width: 1100px) { .groups-layout { grid-template-columns: 1fr !important; gap: 16px; } .groups-main, .groups-side { padding: 0; } }
</style>
