<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useQuasar } from 'quasar'
import { useRoute, useRouter } from 'vue-router'
import { Download, Edit3, Plus, RefreshCw, Trash2, Upload, Wand2 } from '@lucide/vue'
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
import { useAuthStore } from '../../stores/auth'
import { TABLE_ROWS_PER_PAGE_OPTIONS, createTablePagination, persistTablePagination } from '../../services/tableSettings'
import { useReferenceOptionsStore } from '../../stores/referenceOptions'
import { ASSIGNMENT_STATUS_OPTIONS, LOAD_STATUS_OPTIONS, assignmentLabel, assignmentTone, statusLabel, statusTone, teacherName, useTeachingLoadStore } from '../../stores/teachingLoad'

const store = useTeachingLoadStore()
const permissions = usePermissions()
const auth = useAuthStore()
const isOwnView = computed(() => auth.hasRole('teacher') && !permissions.hasPermission('teachingload.view'))
const canCreate = computed(() => permissions.hasPermission('teachingload.edit'))
const canUpdate = computed(() => permissions.hasPermission('teachingload.edit'))
const canDelete = computed(() => permissions.hasPermission('teachingload.edit'))
const canGenerate = computed(() => permissions.hasAnyPermission(['teaching_load.generate', 'teachingload.edit']))
const canAssign = computed(() => permissions.hasAnyPermission(['teaching_load.assign', 'teachingload.edit']))
const canImport = computed(() => permissions.hasPermission('teachingload.edit'))
const canExport = computed(() => permissions.hasAnyPermission(['teachingload.view', 'teachingload.edit']))

const referenceOptions = useReferenceOptionsStore()
const $q = useQuasar()
const route = useRoute()
const router = useRouter()
const rowsKey = 'collegePortal.teachingLoad.rowsPerPage'
const syncingQuery = ref(false)
const formVisible = ref(false)
const itemFormVisible = ref(false)
const generateDialogVisible = ref(false)
const previewDialogVisible = ref(false)
const deleteDialogVisible = ref(false)
const itemDeleteDialogVisible = ref(false)
const editingLoad = ref(null)
const deletingLoad = ref(null)
const deletingItem = ref(null)
const importFile = ref(null)
const assignTeacherId = ref(null)
const tablePagination = ref(createTablePagination(rowsKey, { sortBy: 'academic_year', descending: true, rowsPerPage: 20 }))
const loadForm = reactive({ academic_year: '2026/2027', teacher_id: '', status: 'draft', description: '' })
const itemForm = reactive({ subject_id: '', group_id: '', semester: 1, hours_total: 0, load_type: 'Аудиторная', sort_order: 0 })
const generateForm = reactive({ group_id: '', academic_year: '2026/2027' })

const columns = [
  { name: 'teacher', label: 'Нагрузка', field: 'teacher', align: 'left', sortable: true },
  { name: 'academic_year', label: 'Учебный год', field: 'academic_year', align: 'left', sortable: true },
  { name: 'status', label: 'Статус', field: 'status', align: 'left', sortable: true },
  { name: 'coverage', label: 'Покрытие', field: 'coverage', align: 'left' },
  { name: 'actions', label: '', field: 'actions', align: 'right' },
]
const itemColumns = [
  { name: 'subject', label: 'Дисциплина', field: 'subject', align: 'left' },
  { name: 'semester', label: 'Семестр', field: 'semester', align: 'left' },
  { name: 'planned', label: 'План', field: 'planned_hours', align: 'left' },
  { name: 'assigned', label: 'Назначено', field: 'assigned_hours', align: 'left' },
  { name: 'teacher', label: 'Преподаватель', field: 'teacher', align: 'left' },
  { name: 'assignment_status', label: 'Статус', field: 'assignment_status', align: 'left' },
  { name: 'actions', label: '', field: 'actions', align: 'right' },
]

const loadTypeOptions = computed(() => referenceOptions.options('teaching_load_types', { valueField: 'name' }))
const tableSubtitle = computed(() => `Найдено нагрузок: ${store.filteredLoads.length}`)
const teachingLoadMetrics = computed(() => [
  { label: 'План', value: store.coverage?.planned_hours ?? store.selectedHours },
  { label: 'Назначено', value: store.coverage?.assigned_hours ?? 0 },
  { label: 'Остаток', value: store.coverage?.unassigned_hours ?? 0 },
  { label: 'Превышение', value: store.coverage?.overassigned_hours ?? 0 },
])
const teachingLoadActions = computed(() => [
  ...(!isOwnView.value ? [{ label: 'Преподаватель', to: { path: '/teachers', query: { selected: store.selectedLoad?.teacher_id } } }] : []),
  { label: 'Расписание', to: { path: '/schedule', query: { group: store.selectedLoad?.group_id, teacher: store.selectedLoad?.teacher_id } } },
  { label: 'Журнал', to: { path: '/journal', query: { group: store.selectedLoad?.group_id, teacher: store.selectedLoad?.teacher_id } } },
])

function subjectName(item) { return [item?.subject?.code, item?.subject?.name].filter(Boolean).join(' · ') || '—' }
function groupName(load) { return load?.group?.name || load?.items?.[0]?.group?.name || '—' }
function loadTitle(load) { return load?.teacher_id ? teacherName(load.teacher) : `Группа ${groupName(load)}` }
function loadCoverage(load) { const c = load?.coverage || {}; return `${c.assigned_hours ?? 0}/${c.planned_hours ?? loadHours(load)} ч.` }
function loadHours(load) { return (load?.items || []).reduce((sum, item) => sum + Number(item.planned_hours || item.hours_total || 0), 0) }
function notify(message) { $q.notify({ type: 'positive', message, position: 'top-right', timeout: 1800 }) }
function rowClass(row) { return Number(row.id) === Number(store.selectedId) ? 'teaching-load-row--selected' : '' }
function updatePagination(p) { tablePagination.value = p; persistTablePagination(rowsKey, p) }
function routeSelectedId() { return route.query.selected ? String(route.query.selected) : '' }
async function syncQuery(selectedId = routeSelectedId()) { const query = { ...route.query }; selectedId ? query.selected = selectedId : delete query.selected; syncingQuery.value = true; await router.replace({ path: '/teaching-load', query }); syncingQuery.value = false }
async function selectLoad(load) { store.select(load, { includeCoverage: !isOwnView.value }); await syncQuery(load?.id || '') }
function openCreateForm() { if (!canCreate.value) return; editingLoad.value = null; Object.assign(loadForm, { academic_year: '2026/2027', teacher_id: '', status: 'draft', description: '' }); formVisible.value = true }
function openEditForm(load) { if (!canUpdate.value) return; editingLoad.value = load; Object.assign(loadForm, { academic_year: load.academic_year, teacher_id: load.teacher_id, status: load.status || 'draft', description: load.description || '' }); formVisible.value = true }
async function saveLoad() { if (!canUpdate.value) return; const isEdit = Boolean(editingLoad.value?.id); await store.save(loadForm, editingLoad.value?.id || null); formVisible.value = false; notify(isEdit ? 'Нагрузка обновлена' : 'Нагрузка создана') }
function requestDelete(load) { if (!canDelete.value) return; deletingLoad.value = load; deleteDialogVisible.value = true }
async function confirmDelete() { await store.remove(deletingLoad.value); deletingLoad.value = null; notify('Нагрузка удалена') }
function openItemForm() { if (!canUpdate.value) return; Object.assign(itemForm, { subject_id: '', group_id: '', semester: 1, hours_total: 0, load_type: 'Аудиторная', sort_order: store.selectedItems.length + 1 }); itemFormVisible.value = true }
async function addItem() { if (!canUpdate.value) return; await store.addItem(itemForm); itemFormVisible.value = false; notify('Строка нагрузки добавлена') }
function requestItemDelete(item) { deletingItem.value = item; itemDeleteDialogVisible.value = true }
async function confirmItemDelete() { await store.removeItem(deletingItem.value); deletingItem.value = null; notify('Строка нагрузки удалена') }
function openGenerateDialog() { if (!canGenerate.value) return; Object.assign(generateForm, { group_id: route.query.group || '', academic_year: '2026/2027' }); generateDialogVisible.value = true }
async function previewGenerate() { await store.generatePreview(generateForm); generateDialogVisible.value = false; previewDialogVisible.value = true }
async function applyGenerate() { await store.generateApply(generateForm); previewDialogVisible.value = false; notify('Нагрузка сформирована из учебного плана') }
async function assignItemTeacher(item) { if (!canAssign.value || !assignTeacherId.value) return; await store.assignTeacher(item, assignTeacherId.value); notify('Преподаватель назначен') }
async function bulkAssignUnassigned() { if (!canAssign.value || !assignTeacherId.value) return; const ids = store.selectedItems.filter((item) => item.assignment_status === 'unassigned').map((item) => item.id); if (!ids.length) return; await store.bulkAssignTeacher(ids, assignTeacherId.value); notify('Преподаватель назначен неназначенным строкам') }
async function applyFilters() { store.setFilters({ ...store.filters }); await syncQuery('') }
async function resetFilters() { store.resetFilters(); await syncQuery('') }
async function handleImport(file) { if (!canImport.value || !file) return; await store.importCsv(file); importFile.value = null; notify('Импорт нагрузки завершен') }
async function exportCsv() { if (!canExport.value) return; await store.exportCsv(); notify('Экспорт нагрузки подготовлен') }
watch(() => route.query.selected, () => { if (!syncingQuery.value) store.selectById(routeSelectedId(), { includeCoverage: !isOwnView.value }) })
onMounted(async () => { if (!isOwnView.value) await referenceOptions.loadCatalog('teaching_load_types'); store.selectById(routeSelectedId(), { includeCoverage: !isOwnView.value }); await store.load({ includeReferenceData: !isOwnView.value }); if (!store.selectedLoad && store.filteredLoads[0]) await selectLoad(store.filteredLoads[0]) })
</script>

<template>
  <AppPage>
    <PageHeader title="Нагрузка преподавателей" :subtitle="isOwnView ? 'Ваша учебная нагрузка.' : 'Формирование из учебного плана, распределение часов и назначение преподавателей.'">
      <template #actions>
        <q-btn v-if="canGenerate" color="secondary" @click="openGenerateDialog"><Wand2 :size="16" class="q-mr-xs" /> Сформировать из учебного плана</q-btn>
        <q-btn v-if="canCreate" color="primary" @click="openCreateForm"><Plus :size="16" class="q-mr-xs" /> Новая нагрузка</q-btn>
      </template>
    </PageHeader>

    <AppToolbar><span>{{ tableSubtitle }}</span><template #actions><AppLoading v-if="store.loading" label="Загрузка нагрузки..." /><q-btn flat :disable="store.loading" @click="store.load({ includeReferenceData: !isOwnView })"><RefreshCw :size="16" class="q-mr-xs" /> Обновить</q-btn><q-file v-if="canImport" v-model="importFile" dense outlined accept=".csv,text/csv" label="Импорт" style="max-width: 180px" @update:model-value="handleImport"><template #prepend><Upload :size="16" /></template></q-file><q-btn v-if="canExport" color="primary" @click="exportCsv"><Download :size="16" class="q-mr-xs" /> Экспорт</q-btn></template></AppToolbar>
    <AppErrorBanner :message="store.error" />

    <AppFilterBar>
      <q-select v-model="store.filters.academic_year" dense outlined clearable emit-value map-options label="Учебный год" :options="store.academicYearOptions" />
      <q-select v-if="!isOwnView" v-model="store.filters.group_id" dense outlined clearable emit-value map-options label="Группа" :options="store.groupOptions" />
      <q-select v-model="store.filters.semester" dense outlined clearable emit-value map-options label="Семестр" :options="store.semesterOptions" />
      <q-select v-if="!isOwnView" v-model="store.filters.subject_id" dense outlined clearable emit-value map-options label="Дисциплина" :options="store.subjectOptions" />
      <q-select v-if="!isOwnView" v-model="store.filters.assignment_teacher_id" dense outlined clearable emit-value map-options label="Преподаватель" :options="store.teacherOptions" />
      <q-select v-if="!isOwnView" v-model="store.filters.assignment_status" dense outlined clearable emit-value map-options label="Распределение" :options="ASSIGNMENT_STATUS_OPTIONS" />
      <template #actions><q-btn color="primary" @click="applyFilters">Применить</q-btn><q-btn flat @click="resetFilters">Сбросить</q-btn></template>
    </AppFilterBar>

    <div class="teaching-load-workspace">
      <div class="teaching-load-main">
        <AppTable v-if="store.filteredLoads.length || store.loading" :rows="store.filteredLoads" :columns="columns" :loading="store.loading" :pagination="tablePagination" :rows-per-page-options="TABLE_ROWS_PER_PAGE_OPTIONS" :table-row-class-fn="rowClass" @update:pagination="updatePagination" @row-click="(_, row) => selectLoad(row)">
          <template #body-cell-teacher="props"><q-td :props="props"><button class="teaching-load-row-link" type="button" @click.stop="selectLoad(props.row)">{{ loadTitle(props.row) }}</button><div class="teaching-load-secondary-cell">{{ props.row.description || props.row.curriculum?.name || '—' }}</div></q-td></template>
          <template #body-cell-status="props"><q-td :props="props"><AppStatusBadge :label="statusLabel(props.row.status)" :tone="statusTone(props.row.status)" /></q-td></template>
          <template #body-cell-coverage="props"><q-td :props="props">{{ loadCoverage(props.row) }}</q-td></template>
          <template #body-cell-actions="props"><q-td :props="props"><q-btn v-if="canUpdate" flat round dense title="Редактировать" @click.stop="openEditForm(props.row)"><Edit3 :size="16" /></q-btn><q-btn v-if="canDelete" flat round dense color="negative" title="Удалить" @click.stop="requestDelete(props.row)"><Trash2 :size="16" /></q-btn></q-td></template>
        </AppTable>
        <AppEmptyState v-else title="Нагрузка не найдена" description="Создайте нагрузку или сформируйте ее из учебного плана." />
      </div>

      <aside class="teaching-load-side">
        <AppEmptyState v-if="!store.selectedLoad" title="Нагрузка не выбрана" description="Выберите строку в таблице, чтобы открыть карточку нагрузки." />
        <WorkspacePanel v-else class="teaching-load-card" :title="loadTitle(store.selectedLoad)" :subtitle="store.selectedLoad.academic_year" :metrics="teachingLoadMetrics" :actions="teachingLoadActions">
          <template #status><AppStatusBadge :label="statusLabel(store.selectedLoad.status)" :tone="statusTone(store.selectedLoad.status)" /></template>
          <div class="teaching-load-details">
            <div v-if="canAssign" class="assign-bar"><q-select v-model="assignTeacherId" dense outlined clearable emit-value map-options label="Назначить преподавателя" :options="store.teacherOptions" /><q-btn dense color="primary" :disable="!assignTeacherId" @click="bulkAssignUnassigned">Назначить неназначенным</q-btn></div>
            <section><div class="teaching-load-section-header"><h3>Строки нагрузки</h3><q-btn v-if="canUpdate" dense color="primary" :disable="store.saving" @click="openItemForm"><Plus :size="15" class="q-mr-xs" /> Добавить</q-btn></div>
              <AppTable v-if="store.selectedItems.length" :rows="store.selectedItems" :columns="itemColumns" :pagination="{ rowsPerPage: 0 }" :rows-per-page-options="[0]">
                <template #body-cell-subject="props"><q-td :props="props">{{ subjectName(props.row) }}</q-td></template>
                <template #body-cell-planned="props"><q-td :props="props">{{ props.row.planned_hours || props.row.hours_total }}</q-td></template>
                <template #body-cell-assigned="props"><q-td :props="props"><strong>{{ props.row.assigned_hours || 0 }}</strong><div class="teaching-load-secondary-cell">остаток {{ props.row.unassigned_hours || 0 }} · сверх {{ props.row.overassigned_hours || 0 }}</div></q-td></template>
                <template #body-cell-teacher="props"><q-td :props="props">{{ teacherName(props.row.teacher) }}</q-td></template>
                <template #body-cell-assignment_status="props"><q-td :props="props"><AppStatusBadge :label="assignmentLabel(props.row.assignment_status)" :tone="assignmentTone(props.row.assignment_status)" /></q-td></template>
                <template #body-cell-actions="props"><q-td :props="props"><q-btn v-if="canAssign" flat dense :disable="!assignTeacherId" @click="assignItemTeacher(props.row)">Назначить</q-btn><q-btn v-if="canUpdate" flat round dense color="negative" title="Удалить" @click="requestItemDelete(props.row)"><Trash2 :size="15" /></q-btn></q-td></template>
              </AppTable><p v-else class="teaching-load-muted">Строки нагрузки пока не добавлены.</p>
            </section>
          </div>
        </WorkspacePanel>
      </aside>
    </div>

    <q-dialog v-model="generateDialogVisible"><q-card class="teaching-load-dialog"><q-card-section><div class="text-h6">Сформировать нагрузку из учебного плана</div></q-card-section><q-card-section class="teaching-load-dialog__body"><q-select v-model="generateForm.group_id" outlined dense emit-value map-options label="Группа" :options="store.groupOptions" /><q-input v-model="generateForm.academic_year" outlined dense label="Учебный год" /><p class="teaching-load-muted">Preview не изменяет данные. Apply создаст или обновит строки с источником curriculum_engine.</p></q-card-section><q-card-actions align="right"><q-btn flat label="Отмена" @click="generateDialogVisible = false" /><q-btn color="primary" :loading="store.saving" :disable="!generateForm.group_id || !generateForm.academic_year" @click="previewGenerate">Preview</q-btn></q-card-actions></q-card></q-dialog>
    <q-dialog v-model="previewDialogVisible"><q-card class="teaching-load-preview"><q-card-section><div class="text-h6">Preview генерации</div><div class="teaching-load-muted">{{ store.generationPreview?.group?.name }} · {{ store.generationPreview?.curriculum?.name || store.generationPreview?.reason }}</div></q-card-section><q-card-section><div class="preview-grid"><div><span>Найдено</span><strong>{{ store.generationPreview?.found || 0 }}</strong></div><div><span>Будет создано</span><strong>{{ store.generationPreview?.will_create || 0 }}</strong></div><div><span>Будет обновлено</span><strong>{{ store.generationPreview?.will_update || 0 }}</strong></div><div><span>Конфликты</span><strong>{{ store.generationPreview?.conflicts || 0 }}</strong></div><div><span>Без преподавателя</span><strong>{{ store.generationPreview?.unassigned_teachers || 0 }}</strong></div></div><div class="preview-list"><div v-for="item in store.generationPreview?.items || []" :key="item.curriculum_subject_id" class="preview-row"><strong>{{ item.subject_name }}</strong><span>{{ item.semester }} семестр · {{ item.planned_hours }} ч. · {{ item.operation }}</span><em v-if="item.warning">{{ item.warning }}</em></div></div></q-card-section><q-card-actions align="right"><q-btn flat label="Закрыть" @click="previewDialogVisible = false" /><q-btn color="primary" :loading="store.saving" :disable="!store.generationPreview?.found" @click="applyGenerate">Apply</q-btn></q-card-actions></q-card></q-dialog>

    <q-dialog v-model="formVisible"><q-card class="teaching-load-dialog"><q-card-section><div class="text-h6">{{ editingLoad ? 'Редактировать нагрузку' : 'Новая нагрузка' }}</div></q-card-section><q-card-section class="teaching-load-dialog__body"><q-input v-model="loadForm.academic_year" outlined dense label="Учебный год" /><q-select v-model="loadForm.teacher_id" outlined dense emit-value map-options label="Преподаватель" :options="store.teacherOptions" /><q-select v-model="loadForm.status" outlined dense emit-value map-options label="Статус" :options="LOAD_STATUS_OPTIONS" /><q-input v-model="loadForm.description" outlined dense type="textarea" label="Комментарий" /></q-card-section><q-card-actions align="right"><q-btn flat label="Отмена" @click="formVisible = false" /><q-btn color="primary" :loading="store.saving" :disable="!loadForm.academic_year || !loadForm.teacher_id" @click="saveLoad">Сохранить</q-btn></q-card-actions></q-card></q-dialog>
    <q-dialog v-model="itemFormVisible"><q-card class="teaching-load-dialog"><q-card-section><div class="text-h6">Добавить строку нагрузки</div></q-card-section><q-card-section class="teaching-load-dialog__body"><q-select v-model="itemForm.subject_id" outlined dense emit-value map-options label="Дисциплина" :options="store.subjectOptions" /><q-select v-model="itemForm.group_id" outlined dense emit-value map-options label="Группа" :options="store.groupOptions" /><q-input v-model="itemForm.semester" outlined dense type="number" label="Семестр" /><q-input v-model="itemForm.hours_total" outlined dense type="number" label="Часы" /><q-select v-model="itemForm.load_type" outlined dense label="Тип нагрузки" :options="loadTypeOptions" /><q-input v-model="itemForm.sort_order" outlined dense type="number" label="Порядок" /></q-card-section><q-card-actions align="right"><q-btn flat label="Отмена" @click="itemFormVisible = false" /><q-btn color="primary" :loading="store.saving" :disable="!itemForm.subject_id || !itemForm.group_id" @click="addItem">Добавить</q-btn></q-card-actions></q-card></q-dialog>
    <AppConfirmDialog v-model="deleteDialogVisible" title="Удалить нагрузку?" :message="deletingLoad ? `Будет удалена нагрузка: ${loadTitle(deletingLoad)}.` : 'Будет удалена выбранная нагрузка.'" confirm-label="Удалить" tone="negative" @confirm="confirmDelete" />
    <AppConfirmDialog v-model="itemDeleteDialogVisible" title="Удалить строку нагрузки?" message="Строка будет удалена из нагрузки преподавателя." confirm-label="Удалить" tone="negative" @confirm="confirmItemDelete" />
  </AppPage>
</template>

<style scoped>
.teaching-load-workspace { display: grid; grid-template-columns: minmax(0, 1fr) 420px; gap: 16px; align-items: start; }
.teaching-load-main, .teaching-load-side { min-width: 0; }
.teaching-load-side { position: sticky; top: 76px; }
.teaching-load-row-link { border: 0; padding: 0; background: transparent; color: #0f172a; font-weight: 700; cursor: pointer; text-align: left; }
.teaching-load-secondary-cell, .teaching-load-muted { color: #64748b; font-size: 12px; }
.teaching-load-section-header, .assign-bar { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 10px; }
.assign-bar { align-items: stretch; }
.assign-bar .q-select { flex: 1; }
.teaching-load-dialog { width: min(640px, 96vw); }
.teaching-load-preview { width: min(860px, 96vw); }
.teaching-load-dialog__body { display: grid; gap: 12px; }
.preview-grid { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 8px; margin-bottom: 12px; }
.preview-grid div, .preview-row { border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px; }
.preview-grid span, .preview-row span, .preview-row em { display: block; color: #64748b; font-size: 12px; }
.preview-list { display: grid; gap: 8px; max-height: 360px; overflow: auto; }
:deep(.teaching-load-row--selected) { background: #f8fafc; }
@media (max-width: 1439px) { .teaching-load-workspace { grid-template-columns: minmax(0, 1fr) 380px; } }
@media (max-width: 1023px) { .teaching-load-workspace { grid-template-columns: 1fr; } .teaching-load-side { position: static; } }
</style>
