<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useQuasar } from 'quasar'
import { useRoute, useRouter } from 'vue-router'
import { Download, Edit3, Plus, RefreshCw, Trash2, Upload } from '@lucide/vue'
import AppPage from '../../components/ui/AppPage.vue'
import PageHeader from '../../components/ui/PageHeader.vue'
import AppToolbar from '../../components/ui/AppToolbar.vue'
import AppFilterBar from '../../components/ui/AppFilterBar.vue'
import AppTable from '../../components/ui/AppTable.vue'
import AppEmptyState from '../../components/ui/AppEmptyState.vue'
import AppLoading from '../../components/ui/AppLoading.vue'
import AppErrorBanner from '../../components/ui/AppErrorBanner.vue'
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'
import AppConfirmDialog from '../../components/ui/AppConfirmDialog.vue'
import WorkspacePanel from '../../components/workspace/WorkspacePanel.vue'
import { usePermissions } from '../../composables/usePermissions'
import { TABLE_ROWS_PER_PAGE_OPTIONS, createTablePagination, persistTablePagination } from '../../services/tableSettings'
import { useReferenceOptionsStore } from '../../stores/referenceOptions'
import { LOAD_STATUS_OPTIONS, statusLabel, statusTone, teacherName, useTeachingLoadStore } from '../../stores/teachingLoad'

const store = useTeachingLoadStore()
const permissions = usePermissions()
const canCreate = computed(() => permissions.hasPermission('teachingload.edit'))
const canUpdate = computed(() => permissions.hasPermission('teachingload.edit'))
const canDelete = computed(() => permissions.hasPermission('teachingload.edit'))
const canImport = computed(() => permissions.hasPermission('teachingload.edit'))
const canExport = computed(() => permissions.hasAnyPermission(['teachingload.view', 'teachingload.edit']))
const referenceOptions = useReferenceOptionsStore()
const $q = useQuasar(), route = useRoute(), router = useRouter()
const rowsKey = 'collegePortal.teachingLoad.rowsPerPage'
const syncingQuery = ref(false), formVisible = ref(false), itemFormVisible = ref(false), deleteDialogVisible = ref(false), itemDeleteDialogVisible = ref(false)
const editingLoad = ref(null), deletingLoad = ref(null), deletingItem = ref(null), importFile = ref(null)
const tablePagination = ref(createTablePagination(rowsKey, { sortBy: 'academic_year', descending: true, rowsPerPage: 20 }))
const loadForm = reactive({ academic_year: '2026/2027', teacher_id: '', status: 'draft', description: '' })
const itemForm = reactive({ subject_id: '', group_id: '', semester: 1, hours_total: 0, load_type: 'Аудиторная', sort_order: 0 })
const columns = [
  { name: 'teacher', label: 'Преподаватель', field: 'teacher', align: 'left', sortable: true },
  { name: 'academic_year', label: 'Учебный год', field: 'academic_year', align: 'left', sortable: true },
  { name: 'status', label: 'Статус', field: 'status', align: 'left', sortable: true },
  { name: 'items_count', label: 'Строк', field: 'items_count', align: 'left', sortable: true },
  { name: 'hours', label: 'Часы', field: 'hours', align: 'left' },
  { name: 'actions', label: '', field: 'actions', align: 'right' },
]
const itemColumns = [
  { name: 'subject', label: 'Дисциплина', field: 'subject', align: 'left' },
  { name: 'group', label: 'Группа', field: 'group', align: 'left' },
  { name: 'semester', label: 'Семестр', field: 'semester', align: 'left' },
  { name: 'hours_total', label: 'Часы', field: 'hours_total', align: 'left' },
  { name: 'load_type', label: 'Тип', field: 'load_type', align: 'left' },
  { name: 'actions', label: '', field: 'actions', align: 'right' },
]
const loadTypeOptions = computed(() => referenceOptions.options('teaching_load_types', { valueField: 'name' }))
const tableSubtitle = computed(() => `Найдено нагрузок: ${store.filteredLoads.length}`)
const teacherRoute = computed(() => ({ path: '/teachers', query: { selected: store.selectedLoad?.teacher_id } }))
const scheduleRoute = computed(() => ({ path: '/schedule', query: { teacher: store.selectedLoad?.teacher_id } }))

const journalRoute = computed(() => ({ path: '/journal', query: { teacher: store.selectedLoad?.teacher_id } }))
const teachingLoadMetrics = computed(() => [
  { label: 'Учебный год', value: store.selectedLoad?.academic_year || '—' },
  { label: 'Часов', value: store.selectedHours },
  { label: 'Дисциплин', value: new Set(store.selectedItems.map((item) => item.subject_id).filter(Boolean)).size },
  { label: 'Групп', value: new Set(store.selectedItems.map((item) => item.group_id).filter(Boolean)).size },
])
const teachingLoadActions = computed(() => [
  { label: 'Преподаватель', to: teacherRoute.value },
  { label: 'Расписание', to: scheduleRoute.value },
  { label: 'Журнал', to: journalRoute.value },
])
function subjectName(item) { return [item?.subject?.code, item?.subject?.name].filter(Boolean).join(' · ') || '—' }
function groupName(item) { return item?.group?.name || '—' }
function loadHours(load) { return (load?.items || []).reduce((sum, item) => sum + Number(item.hours_total || 0), 0) }
function notify(message) { $q.notify({ type: 'positive', message, position: 'top-right', timeout: 1800 }) }
function rowClass(row) { return Number(row.id) === Number(store.selectedId) ? 'teaching-load-row--selected' : '' }
function updatePagination(p) { tablePagination.value = p; persistTablePagination(rowsKey, p) }
function routeSelectedId() { return route.query.selected ? String(route.query.selected) : '' }
async function syncQuery(selectedId = routeSelectedId()) { const query = { ...route.query }; selectedId ? query.selected = selectedId : delete query.selected; syncingQuery.value = true; await router.replace({ path: '/teaching-load', query }); syncingQuery.value = false }
async function selectLoad(load) { store.select(load); await syncQuery(load?.id || '') }
function openCreateForm() { if (!canCreate.value) return; editingLoad.value = null; Object.assign(loadForm, { academic_year: '2026/2027', teacher_id: '', status: 'draft', description: '' }); formVisible.value = true }
function openEditForm(load) { if (!canUpdate.value) return; editingLoad.value = load; Object.assign(loadForm, { academic_year: load.academic_year, teacher_id: load.teacher_id, status: load.status || 'draft', description: load.description || '' }); formVisible.value = true }
async function saveLoad() { if (!canUpdate.value) return; if (!canUpdate.value) return; const isEdit = Boolean(editingLoad.value?.id); await store.save(loadForm, editingLoad.value?.id || null); formVisible.value = false; notify(isEdit ? 'Нагрузка обновлена' : 'Нагрузка создана') }
function requestDelete(load) { if (!canDelete.value) return; deletingLoad.value = load; deleteDialogVisible.value = true }
async function confirmDelete() { await store.remove(deletingLoad.value); deletingLoad.value = null; notify('Нагрузка удалена') }
function openItemForm() { if (!canUpdate.value) return; Object.assign(itemForm, { subject_id: '', group_id: '', semester: 1, hours_total: 0, load_type: 'Аудиторная', sort_order: store.selectedItems.length + 1 }); itemFormVisible.value = true }
async function addItem() { if (!canUpdate.value) return; if (!canUpdate.value) return; await store.addItem(itemForm); itemFormVisible.value = false; notify('Строка нагрузки добавлена') }
function requestItemDelete(item) { deletingItem.value = item; itemDeleteDialogVisible.value = true }
async function confirmItemDelete() { await store.removeItem(deletingItem.value); deletingItem.value = null; notify('Строка нагрузки удалена') }
async function applyFilters() { store.setFilters({ ...store.filters }); await syncQuery('') }
async function resetFilters() { store.resetFilters(); await syncQuery('') }
async function handleImport(file) { if (!canImport.value || !file) return; await store.importCsv(file); importFile.value = null; notify('Импорт нагрузки завершен') }
async function exportCsv() { if (!canExport.value) return; await store.exportCsv(); notify('Экспорт нагрузки подготовлен') }
watch(() => route.query.selected, () => { if (!syncingQuery.value) store.selectById(routeSelectedId()) })
onMounted(async () => { await referenceOptions.loadCatalog('teaching_load_types'); store.selectById(routeSelectedId()); await store.load(); if (!store.selectedLoad && store.filteredLoads[0]) await selectLoad(store.filteredLoads[0]) })
</script>

<template>
  <AppPage>
    <PageHeader title="Нагрузка преподавателей" subtitle="Учебный год, преподаватель, дисциплины, группы, семестры, часы и типы нагрузки."><template #actions><q-btn v-if="canCreate" color="primary" @click="openCreateForm"><Plus :size="16" class="q-mr-xs" /> Новая нагрузка</q-btn></template></PageHeader>
    <AppToolbar><span>{{ tableSubtitle }}</span><template #actions><AppLoading v-if="store.loading" label="Загрузка нагрузки..." /><q-btn flat :disable="store.loading" @click="store.load"><RefreshCw :size="16" class="q-mr-xs" /> Обновить</q-btn><q-file v-if="canImport" v-model="importFile" dense outlined accept=".csv,text/csv" label="Импорт" style="max-width: 180px" @update:model-value="handleImport"><template #prepend><Upload :size="16" /></template></q-file><q-btn color="primary" @click="exportCsv"><Download :size="16" class="q-mr-xs" /> Экспорт</q-btn></template></AppToolbar>
    <AppErrorBanner :message="store.error" />
    <AppFilterBar><q-select v-model="store.filters.academic_year" dense outlined clearable emit-value map-options label="Учебный год" :options="store.academicYearOptions" /><q-select v-model="store.filters.teacher_id" dense outlined clearable emit-value map-options label="Преподаватель" :options="store.teacherOptions" /><q-select v-model="store.filters.group_id" dense outlined clearable emit-value map-options label="Группа" :options="store.groupOptions" /><q-select v-model="store.filters.subject_id" dense outlined clearable emit-value map-options label="Дисциплина" :options="store.subjectOptions" /><template #actions><q-btn color="primary" @click="applyFilters">Применить</q-btn><q-btn flat @click="resetFilters">Сбросить</q-btn></template></AppFilterBar>
    <div class="teaching-load-workspace"><div class="teaching-load-main"><AppTable v-if="store.filteredLoads.length || store.loading" :rows="store.filteredLoads" :columns="columns" :loading="store.loading" :pagination="tablePagination" :rows-per-page-options="TABLE_ROWS_PER_PAGE_OPTIONS" :table-row-class-fn="rowClass" @update:pagination="updatePagination" @row-click="(_, row) => selectLoad(row)"><template #body-cell-teacher="props"><q-td :props="props"><button class="teaching-load-row-link" type="button" @click.stop="selectLoad(props.row)">{{ teacherName(props.row.teacher) }}</button><div class="teaching-load-secondary-cell">{{ props.row.description || '—' }}</div></q-td></template><template #body-cell-status="props"><q-td :props="props"><AppStatusBadge :label="statusLabel(props.row.status)" :tone="statusTone(props.row.status)" /></q-td></template><template #body-cell-hours="props"><q-td :props="props">{{ loadHours(props.row) }}</q-td></template><template #body-cell-actions="props"><q-td :props="props"><q-btn v-if="canUpdate" flat round dense title="Редактировать" @click.stop="openEditForm(props.row)"><Edit3 :size="16" /></q-btn><q-btn v-if="canDelete" flat round dense color="negative" title="Удалить" @click.stop="requestDelete(props.row)"><Trash2 :size="16" /></q-btn></q-td></template></AppTable><AppEmptyState v-else title="Нагрузка не найдена" description="Создайте нагрузку или импортируйте CSV." /></div>
      <aside class="teaching-load-side"><AppEmptyState v-if="!store.selectedLoad" title="Нагрузка не выбрана" description="Выберите строку в таблице, чтобы открыть карточку нагрузки." /><WorkspacePanel v-else class="teaching-load-card" :title="teacherName(store.selectedLoad.teacher)" :subtitle="store.selectedLoad.academic_year" :metrics="teachingLoadMetrics" :actions="teachingLoadActions"><template #status><AppStatusBadge :label="statusLabel(store.selectedLoad.status)" :tone="statusTone(store.selectedLoad.status)" /></template><div class="teaching-load-details"><section><div class="teaching-load-section-header"><h3>Строки нагрузки</h3><q-btn dense color="primary" :disable="store.saving" @click="openItemForm"><Plus :size="15" class="q-mr-xs" /> Добавить</q-btn></div><AppTable v-if="store.selectedItems.length" :rows="store.selectedItems" :columns="itemColumns" :pagination="{ rowsPerPage: 0 }" :rows-per-page-options="[0]"><template #body-cell-subject="props"><q-td :props="props"><RouterLink :to="{ path: '/subjects', query: { selected: props.row.subject_id } }" class="entity-link-action">{{ subjectName(props.row) }}</RouterLink></q-td></template><template #body-cell-group="props"><q-td :props="props"><RouterLink :to="{ path: '/groups', query: { selected: props.row.group_id } }" class="entity-link-action">{{ groupName(props.row) }}</RouterLink></q-td></template><template #body-cell-actions="props"><q-td :props="props"><q-btn v-if="canUpdate" flat round dense color="negative" title="Удалить" @click="requestItemDelete(props.row)"><Trash2 :size="15" /></q-btn></q-td></template></AppTable><p v-else class="teaching-load-muted">Строки нагрузки пока не добавлены.</p></section></div></WorkspacePanel></aside></div>
    <q-dialog v-model="formVisible"><q-card class="teaching-load-dialog"><q-card-section><div class="text-h6">{{ editingLoad ? 'Редактировать нагрузку' : 'Новая нагрузка' }}</div></q-card-section><q-card-section class="teaching-load-dialog__body"><q-input v-model="loadForm.academic_year" outlined dense label="Учебный год" /><q-select v-model="loadForm.teacher_id" outlined dense emit-value map-options label="Преподаватель" :options="store.teacherOptions" /><q-select v-model="loadForm.status" outlined dense emit-value map-options label="Статус" :options="LOAD_STATUS_OPTIONS" /><q-input v-model="loadForm.description" outlined dense type="textarea" label="Комментарий" /></q-card-section><q-card-actions align="right"><q-btn flat label="Отмена" @click="formVisible = false" /><q-btn color="primary" :loading="store.saving" :disable="!loadForm.academic_year || !loadForm.teacher_id" @click="saveLoad">Сохранить</q-btn></q-card-actions></q-card></q-dialog>
    <q-dialog v-model="itemFormVisible"><q-card class="teaching-load-dialog"><q-card-section><div class="text-h6">Добавить строку нагрузки</div></q-card-section><q-card-section class="teaching-load-dialog__body"><q-select v-model="itemForm.subject_id" outlined dense emit-value map-options label="Дисциплина" :options="store.subjectOptions" /><q-select v-model="itemForm.group_id" outlined dense emit-value map-options label="Группа" :options="store.groupOptions" /><q-input v-model="itemForm.semester" outlined dense type="number" label="Семестр" /><q-input v-model="itemForm.hours_total" outlined dense type="number" label="Часы" /><q-select v-model="itemForm.load_type" outlined dense label="Тип нагрузки" :options="loadTypeOptions" /><q-input v-model="itemForm.sort_order" outlined dense type="number" label="Порядок" /></q-card-section><q-card-actions align="right"><q-btn flat label="Отмена" @click="itemFormVisible = false" /><q-btn color="primary" :loading="store.saving" :disable="!itemForm.subject_id || !itemForm.group_id" @click="addItem">Добавить</q-btn></q-card-actions></q-card></q-dialog>
    <AppConfirmDialog v-model="deleteDialogVisible" title="Удалить нагрузку?" :message="deletingLoad ? `Будет удалена нагрузка: ${teacherName(deletingLoad.teacher)}.` : 'Будет удалена выбранная нагрузка.'" confirm-label="Удалить" tone="negative" @confirm="confirmDelete" />
    <AppConfirmDialog v-model="itemDeleteDialogVisible" title="Удалить строку нагрузки?" message="Строка будет удалена из нагрузки преподавателя." confirm-label="Удалить" tone="negative" @confirm="confirmItemDelete" />
  </AppPage>
</template>
