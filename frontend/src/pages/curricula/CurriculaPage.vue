<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useQuasar } from 'quasar'
import { useRoute, useRouter } from 'vue-router'
import { BookPlus, Download, Edit3, Plus, RefreshCw, Trash2, Upload } from '@lucide/vue'
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
import WorkspaceBackBar from '../../components/workspace/WorkspaceBackBar.vue'
import WorkspacePanel from '../../components/workspace/WorkspacePanel.vue'
import { usePermissions } from '../../composables/usePermissions'
import { TABLE_ROWS_PER_PAGE_OPTIONS, createTablePagination, persistTablePagination } from '../../services/tableSettings'
import { CONTROL_FORM_OPTIONS, CURRICULUM_STATUS_OPTIONS, statusLabel, statusTone, useCurriculaStore } from '../../stores/curricula'

const store = useCurriculaStore()
const permissions = usePermissions()
const canCreate = computed(() => permissions.hasPermission('curricula.edit'))
const canUpdate = computed(() => permissions.hasPermission('curricula.edit'))
const canDelete = computed(() => permissions.hasPermission('curricula.edit'))
const canImport = computed(() => permissions.hasPermission('curricula.edit'))
const canExport = computed(() => permissions.hasAnyPermission(['curricula.view', 'curricula.edit']))
const canManageSubjects = computed(() => permissions.hasAnyPermission(['curricula.subjects.create', 'curricula.subjects.update', 'curricula.subjects.delete', 'curricula.edit']))

const $q = useQuasar()
const route = useRoute()
const router = useRouter()
const rowsPerPageKey = 'collegePortal.curricula.rowsPerPage'
const syncingQuery = ref(false)
const formVisible = ref(false)
const subjectFormVisible = ref(false)
const deleteDialogVisible = ref(false)
const subjectDeleteDialogVisible = ref(false)
const activeTab = ref('general')
const editingCurriculum = ref(null)
const deletingCurriculum = ref(null)
const deletingSubject = ref(null)
const importFile = ref(null)
const tablePagination = ref(createTablePagination(rowsPerPageKey, { sortBy: 'year_start', descending: true, rowsPerPage: 20 }))
const codeEditable = ref(false)
const curriculumForm = reactive({ code: '', education_program_id: '', name: '', qualification: '', year_start: new Date().getFullYear(), status: 'draft', description: '' })
const subjectForm = reactive({ subject_id: '', semester: 1, lecture_hours: 0, practice_hours: 0, laboratory_hours: 0, independent_hours: 0, control_type_id: null, sequence: 0, is_optional: false })

const columns = [
  { name: 'code', label: 'Код', field: 'code', align: 'left', sortable: true },
  { name: 'name', label: 'Учебный план', field: 'name', align: 'left', sortable: true },
  { name: 'program', label: 'Программа', field: 'program', align: 'left', sortable: true },
  { name: 'year_start', label: 'Год', field: 'year_start', align: 'left', sortable: true },
  { name: 'status', label: 'Статус', field: 'status', align: 'left', sortable: true },
  { name: 'subjects_count', label: 'Дисциплин', field: 'subjects_count', align: 'left', sortable: true },
  { name: 'actions', label: '', field: 'actions', align: 'right' },
]
const subjectColumns = [
  { name: 'subject', label: 'Дисциплина', field: 'subject', align: 'left' },
  { name: 'semester', label: 'Семестр', field: 'semester', align: 'left', sortable: true },
  { name: 'hours', label: 'Часы', field: 'total_hours', align: 'left', sortable: true },
  { name: 'control', label: 'Контроль', field: 'control_type', align: 'left' },
  { name: 'actions', label: '', field: 'actions', align: 'right' },
]

// Число называется только когда его посчитали.
const tableSubtitle = computed(() => (store.loaded ? `Найдено учебных планов: ${store.filteredCurricula.length}` : 'Список не получен'))
const curriculumMetrics = computed(() => [
  { label: 'Год', value: store.selectedCurriculum?.year_start || '—' },
  { label: 'Дисциплин', value: store.selectedSummary?.subjects_count ?? store.selectedSubjects.length },
  { label: 'Часов', value: store.selectedSummary?.total_hours ?? store.selectedHours },
  { label: 'Экзаменов', value: store.selectedSummary?.exams_count ?? 0 },
])
const curriculumActions = computed(() => [
  { label: 'Дисциплины', to: { path: '/subjects', query: { curriculum: store.selectedCurriculum?.id } } },
  { label: 'Группы', to: { path: '/groups', query: { program: store.selectedCurriculum?.education_program_id } } },
  { label: 'Расписание', to: { path: '/schedule', query: { program: store.selectedCurriculum?.education_program_id } } },
  { label: 'Сформировать нагрузку', to: { path: '/teaching-load', query: { curriculum: store.selectedCurriculum?.id, program: store.selectedCurriculum?.education_program_id } } },
])
const summaryCards = computed(() => [
  { label: 'Всего дисциплин', value: store.selectedSummary?.subjects_count ?? 0 },
  { label: 'Всего часов', value: store.selectedSummary?.total_hours ?? 0 },
  { label: 'Лекции', value: store.selectedSummary?.lecture_hours ?? 0 },
  { label: 'Практика', value: store.selectedSummary?.practice_hours ?? 0 },
  { label: 'Лабораторные', value: store.selectedSummary?.laboratory_hours ?? 0 },
  { label: 'Самостоятельно', value: store.selectedSummary?.independent_hours ?? 0 },
  { label: 'Экзамены', value: store.selectedSummary?.exams_count ?? 0 },
  { label: 'Зачеты', value: store.selectedSummary?.credits_count ?? 0 },
  { label: 'Практики', value: store.selectedSummary?.practices_count ?? 0 },
  { label: 'Курсовые', value: store.selectedSummary?.courseworks_count ?? 0 },
])

function programName(curriculum) { return curriculum?.education_program?.name || '—' }
function specialtyName(curriculum) { return curriculum?.education_program?.specialty?.name || '—' }
function subjectName(item) { return [item?.subject?.code, item?.subject?.name].filter(Boolean).join(' · ') || '—' }
function controlLabel(item) { return item?.control_type_item?.name || CONTROL_FORM_OPTIONS.find((label) => label === item?.control_type)?.label || item?.control_type || '—' }
function notify(message) { $q.notify({ type: 'positive', message, position: 'top-right', timeout: 1800 }) }
function rowClass(row) { return Number(row.id) === Number(store.selectedId) ? 'curricula-row--selected' : '' }
function updatePagination(pagination) { tablePagination.value = pagination; persistTablePagination(rowsPerPageKey, pagination) }
function routeSelectedId() { return route.params.id ? String(route.params.id) : '' }
async function syncQuery(selectedId = routeSelectedId()) { const query = { ...route.query }; syncingQuery.value = true; await router.replace({ path: selectedId ? `/curricula/${selectedId}` : '/curricula', query }); syncingQuery.value = false }
async function selectCurriculum(curriculum) { await store.select(curriculum); await syncQuery(curriculum?.id || '') }
function openCreateForm() { if (!canCreate.value) return; editingCurriculum.value = null; codeEditable.value = false; Object.assign(curriculumForm, { code: '', education_program_id: '', name: '', qualification: '', year_start: new Date().getFullYear(), status: 'draft', description: '' }); formVisible.value = true }
function openEditForm(curriculum) { if (!canUpdate.value) return; editingCurriculum.value = curriculum; codeEditable.value = false; Object.assign(curriculumForm, { code: curriculum.code || '', education_program_id: curriculum.education_program_id, name: curriculum.name, qualification: curriculum.qualification || '', year_start: curriculum.year_start, status: curriculum.status || 'draft', description: curriculum.description || '' }); formVisible.value = true }
async function saveCurriculum() { if (!canUpdate.value) return; const isEdit = Boolean(editingCurriculum.value?.id); await store.save(curriculumForm, editingCurriculum.value?.id || null); formVisible.value = false; notify(isEdit ? 'Учебный план обновлен' : 'Учебный план создан') }
function requestDelete(curriculum) { if (!canDelete.value) return; deletingCurriculum.value = curriculum; deleteDialogVisible.value = true }
async function confirmDelete() { await store.remove(deletingCurriculum.value); deletingCurriculum.value = null; notify('Учебный план удален') }
function openSubjectForm() { if (!canManageSubjects.value) return; Object.assign(subjectForm, { subject_id: '', semester: 1, lecture_hours: 0, practice_hours: 0, laboratory_hours: 0, independent_hours: 0, control_type_id: null, sequence: store.selectedSubjects.length + 1, is_optional: false }); subjectFormVisible.value = true }
async function addSubject() { if (!canManageSubjects.value) return; await store.addSubject(subjectForm); subjectFormVisible.value = false; notify('Дисциплина семестра добавлена') }
function requestSubjectDelete(subject) { if (!canManageSubjects.value) return; deletingSubject.value = subject; subjectDeleteDialogVisible.value = true }
async function confirmSubjectDelete() { await store.removeSubject(deletingSubject.value); deletingSubject.value = null; notify('Дисциплина удалена из учебного плана') }
async function applyFilters() { store.setFilters({ ...store.filters }); await syncQuery('') }
async function resetFilters() { store.resetFilters(); await syncQuery('') }
async function handleImport(file) { if (!canImport.value || !file) return; await store.importCsv(file); importFile.value = null; notify('Импорт учебных планов завершен') }
async function exportCsv() { if (!canExport.value) return; await store.exportCsv(); notify('Экспорт учебных планов подготовлен') }
watch(() => route.params.id, async () => { if (!syncingQuery.value) await store.selectById(routeSelectedId()) })
onMounted(async () => { await store.selectById(routeSelectedId()); await store.load(); if (!store.selectedCurriculum && store.filteredCurricula[0]) { await selectCurriculum(store.filteredCurricula[0]) } })
</script>

<template>
  <AppPage>
    <PageHeader title="Учебные планы" subtitle="Нормализованный план: семестры, дисциплины, часы, контроль и итоги.">
      <template #actions>
        <q-btn v-if="canCreate" color="primary" @click="openCreateForm"><Plus :size="16" class="q-mr-xs" /> Новый план</q-btn>
      </template>
    </PageHeader>

    <AppToolbar>
      <span>{{ tableSubtitle }}</span>
      <template #actions>
        <AppLoading v-if="store.loading" label="Загрузка учебных планов..." />
        <q-btn flat @click="resetSplitter">Сбросить размер</q-btn>
        <q-btn flat :disable="store.loading" @click="store.load"><RefreshCw :size="16" class="q-mr-xs" /> Обновить</q-btn>
        <q-file v-if="canImport" v-model="importFile" dense outlined accept=".csv,text/csv" label="Импорт" style="max-width: 180px" @update:model-value="handleImport"><template #prepend><Upload :size="16" /></template></q-file>
        <q-btn color="primary" @click="exportCsv"><Download :size="16" class="q-mr-xs" /> Экспорт</q-btn>
      </template>
    </AppToolbar>

    <AppErrorBanner :message="store.error" />
    <AppFilterBar>
      <q-input v-model="store.filters.search" dense outlined clearable label="Поиск" @keyup.enter="applyFilters" />
      <q-select v-model="store.filters.specialty_id" dense outlined clearable emit-value map-options label="Специальность" :options="store.specialtyOptions" />
      <q-select v-model="store.filters.education_program_id" dense outlined clearable emit-value map-options label="Программа" :options="store.programOptions" />
      <q-select v-model="store.filters.year_start" dense outlined clearable emit-value map-options label="Год" :options="store.yearOptions" />
      <template #actions><q-btn color="primary" @click="applyFilters">Применить</q-btn><q-btn flat @click="resetFilters">Сбросить</q-btn></template>
    </AppFilterBar>

    <div class="curricula-workspace workspace-page" :class="{ 'workspace-page--card': Boolean(route.params.id) }">
      <div class="curricula-main workspace-page__list">
        <AppTable v-if="store.filteredCurricula.length || store.loading" :rows="store.filteredCurricula" :columns="columns" :loading="store.loading" :pagination="tablePagination" :rows-per-page-options="TABLE_ROWS_PER_PAGE_OPTIONS" :table-row-class-fn="rowClass" @update:pagination="updatePagination":row-link="(row) => `/curricula/${row.id}`"  @row-click="(_, row) => selectCurriculum(row)">
          <template #body-cell-name="props"><q-td :props="props"><button class="curricula-row-link" type="button" @click.stop="selectCurriculum(props.row)">{{ props.row.name }}</button><div class="curricula-secondary-cell">{{ specialtyName(props.row) }}</div></q-td></template>
          <template #body-cell-program="props"><q-td :props="props">{{ programName(props.row) }}</q-td></template>
          <template #body-cell-status="props"><q-td :props="props"><AppStatusBadge :label="statusLabel(props.row.status)" :tone="statusTone(props.row.status)" /></q-td></template>
          <template #body-cell-subjects_count="props"><q-td :props="props">{{ props.row.subjects_count ?? props.row.items_count ?? 0 }}</q-td></template>
          <template #body-cell-actions="props"><q-td :props="props"><q-btn v-if="canUpdate" flat round dense title="Редактировать" @click.stop="openEditForm(props.row)"><Edit3 :size="16" /></q-btn><q-btn v-if="canDelete" flat round dense color="negative" title="Удалить" @click.stop="requestDelete(props.row)"><Trash2 :size="16" /></q-btn></q-td></template>
        </AppTable>
        <AppEmptyState v-else-if="!store.loaded" title="Учебные планы не получены" description="Портал не ответил на запрос. Сколько планов заведено, экран сейчас не знает." />
        <AppEmptyState v-else title="Учебные планы не найдены" description="Создайте учебный план или импортируйте CSV." />
      </div>

      <aside class="curricula-side workspace-page__card">
        <WorkspaceBackBar />
        <AppEmptyState v-if="!store.selectedCurriculum" title="Учебный план не выбран" description="Выберите строку в таблице, чтобы открыть состав плана." />
        <WorkspacePanel v-else class="curricula-card" :title="store.selectedCurriculum.name" :subtitle="programName(store.selectedCurriculum)" :metrics="curriculumMetrics" :actions="curriculumActions">
          <template #status><AppStatusBadge :label="statusLabel(store.selectedCurriculum.status)" :tone="statusTone(store.selectedCurriculum.status)" /></template>
          <q-tabs v-model="activeTab" dense outside-arrows mobile-arrows class="text-primary curricula-tabs">
            <q-tab name="general" label="Общее" />
            <q-tab name="semesters" label="Семестры" />
            <q-tab name="subjects" label="Дисциплины" />
            <q-tab name="control" label="Контроль" />
            <q-tab name="summary" label="Итоги" />
          </q-tabs>
          <q-tab-panels v-model="activeTab" animated class="curricula-tab-panels">
            <q-tab-panel name="general"><dl class="curricula-details__list"><div><dt>Специальность</dt><dd>{{ specialtyName(store.selectedCurriculum) }}</dd></div><div><dt>Квалификация</dt><dd>{{ store.selectedCurriculum.qualification || '—' }}</dd></div><div><dt>Год набора</dt><dd>{{ store.selectedCurriculum.year_start }}</dd></div><div><dt>Описание</dt><dd>{{ store.selectedCurriculum.description || '—' }}</dd></div></dl></q-tab-panel>
            <q-tab-panel name="semesters"><div v-if="store.selectedSemesters.length" class="semester-list"><section v-for="semester in store.selectedSemesters" :key="semester.semester" class="semester-item"><strong>{{ semester.semester }} семестр</strong><span>{{ semester.subjects_count }} дисциплин · {{ semester.total_hours }} ч.</span></section></div><p v-else class="curricula-muted">Семестры пока не заполнены.</p></q-tab-panel>
            <q-tab-panel name="subjects"><div class="curricula-section-header"><h3>Дисциплины семестров</h3><q-btn v-if="canManageSubjects" dense color="primary" @click="openSubjectForm"><BookPlus :size="15" class="q-mr-xs" /> Добавить</q-btn></div><AppTable v-if="store.selectedSubjects.length" :rows="store.selectedSubjects" :columns="subjectColumns" :pagination="{ rowsPerPage: 0 }" :rows-per-page-options="[0]"><template #body-cell-subject="props"><q-td :props="props">{{ subjectName(props.row) }}</q-td></template><template #body-cell-hours="props"><q-td :props="props"><strong>{{ props.row.total_hours }}</strong><div class="curricula-secondary-cell">Л {{ props.row.lecture_hours }} · П {{ props.row.practice_hours }} · Лаб {{ props.row.laboratory_hours }} · СР {{ props.row.independent_hours }}</div></q-td></template><template #body-cell-control="props"><q-td :props="props">{{ controlLabel(props.row) }}</q-td></template><template #body-cell-actions="props"><q-td :props="props"><q-btn v-if="canManageSubjects" flat round dense color="negative" title="Удалить" @click="requestSubjectDelete(props.row)"><Trash2 :size="15" /></q-btn></q-td></template></AppTable><p v-else class="curricula-muted">Дисциплины семестров пока не добавлены.</p></q-tab-panel>
            <q-tab-panel name="control"><div class="summary-grid"><div class="summary-card"><span>Экзамены</span><strong>{{ store.selectedSummary?.exams_count ?? 0 }}</strong></div><div class="summary-card"><span>Зачеты</span><strong>{{ store.selectedSummary?.credits_count ?? 0 }}</strong></div><div class="summary-card"><span>Дифф. зачеты</span><strong>{{ store.selectedSummary?.differentiated_credits_count ?? 0 }}</strong></div><div class="summary-card"><span>Практики</span><strong>{{ store.selectedSummary?.practices_count ?? 0 }}</strong></div><div class="summary-card"><span>Курсовые</span><strong>{{ store.selectedSummary?.courseworks_count ?? 0 }}</strong></div><div class="summary-card"><span>ГИА</span><strong>{{ store.selectedSummary?.gia_count ?? 0 }}</strong></div></div></q-tab-panel>
            <q-tab-panel name="summary"><div class="summary-grid"><div v-for="item in summaryCards" :key="item.label" class="summary-card"><span>{{ item.label }}</span><strong>{{ item.value }}</strong></div></div></q-tab-panel>
          </q-tab-panels>
        </WorkspacePanel>
      </aside>
    </div>

    <q-dialog v-model="formVisible"><q-card class="curricula-dialog"><q-card-section><div class="text-h6">{{ editingCurriculum ? 'Редактировать учебный план' : 'Новый учебный план' }}</div></q-card-section><q-card-section class="curricula-dialog__body"><q-input v-model="curriculumForm.code" outlined dense label="Код" placeholder="Будет создан автоматически" :readonly="!codeEditable"><template #append><q-btn flat round dense title="Разрешить ручное редактирование" @click="codeEditable = true"><Edit3 :size="15" /></q-btn></template></q-input><q-select v-model="curriculumForm.education_program_id" outlined dense emit-value map-options label="Образовательная программа" :options="store.programOptions" /><q-input v-model="curriculumForm.name" outlined dense label="Название" /><q-input v-model="curriculumForm.qualification" outlined dense label="Квалификация" /><q-input v-model="curriculumForm.year_start" outlined dense type="number" label="Год начала" /><q-select v-model="curriculumForm.status" outlined dense emit-value map-options label="Статус" :options="CURRICULUM_STATUS_OPTIONS" /><q-input v-model="curriculumForm.description" outlined dense type="textarea" label="Описание" /></q-card-section><q-card-actions align="right"><q-btn flat label="Отмена" @click="formVisible = false" /><q-btn color="primary" :loading="store.saving" :disable="!curriculumForm.education_program_id || !curriculumForm.name" @click="saveCurriculum">Сохранить</q-btn></q-card-actions></q-card></q-dialog>
    <q-dialog v-model="subjectFormVisible"><q-card class="curricula-dialog"><q-card-section><div class="text-h6">Добавить дисциплину семестра</div></q-card-section><q-card-section class="curricula-dialog__body"><q-select v-model="subjectForm.subject_id" outlined dense emit-value map-options label="Дисциплина" :options="store.subjectOptions" /><q-input v-model="subjectForm.semester" outlined dense type="number" label="Семестр" /><q-input v-model="subjectForm.lecture_hours" outlined dense type="number" label="Лекции" /><q-input v-model="subjectForm.practice_hours" outlined dense type="number" label="Практика" /><q-input v-model="subjectForm.laboratory_hours" outlined dense type="number" label="Лабораторные" /><q-input v-model="subjectForm.independent_hours" outlined dense type="number" label="Самостоятельная работа" /><q-select v-model="subjectForm.control_type_id" outlined dense clearable emit-value map-options label="Вид контроля" :options="store.controlTypeOptions" /><q-input v-model="subjectForm.sequence" outlined dense type="number" label="Порядок" /><q-checkbox v-model="subjectForm.is_optional" label="Дисциплина по выбору" /></q-card-section><q-card-actions align="right"><q-btn flat label="Отмена" @click="subjectFormVisible = false" /><q-btn color="primary" :loading="store.saving" :disable="!subjectForm.subject_id" @click="addSubject">Добавить</q-btn></q-card-actions></q-card></q-dialog>
    <AppConfirmDialog v-model="deleteDialogVisible" title="Удалить учебный план?" :message="deletingCurriculum ? `Будет удален план: ${deletingCurriculum.name}.` : 'Будет удален выбранный план.'" confirm-label="Удалить" tone="negative" @confirm="confirmDelete" />
    <AppConfirmDialog v-model="subjectDeleteDialogVisible" title="Удалить дисциплину из плана?" message="Строка будет удалена из учебного плана." confirm-label="Удалить" tone="negative" @confirm="confirmSubjectDelete" />
  </AppPage>
</template>

<style scoped>
.curricula-workspace { display: grid; gap: 0; align-items: start; }
.curricula-main, .curricula-side { min-width: 0; }
.curricula-main { padding-right: 10px; }
.curricula-side { position: sticky; top: 76px; padding-left: 10px; }
.curricula-row-link { border: 0; padding: 0; background: transparent; color: #0f172a; font-weight: 700; cursor: pointer; text-align: left; }
.curricula-secondary-cell, .curricula-muted { color: #64748b; font-size: 12px; }
.curricula-details__list { display: grid; gap: 10px; margin: 0; }
.curricula-details__list div { min-width: 0; }
.curricula-details__list dt { color: #64748b; font-size: 12px; }
.curricula-details__list dd { margin: 2px 0 0; color: #0f172a; font-weight: 600; overflow-wrap: anywhere; }
.curricula-section-header { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 10px; }
.curricula-section-header h3 { margin: 0; font-size: 15px; }
.curricula-dialog { width: min(620px, 96vw); }
.curricula-dialog__body { display: grid; gap: 12px; }
.curricula-tab-panels { background: transparent; }
.semester-list { display: grid; gap: 8px; }
.semester-item { display: flex; justify-content: space-between; gap: 8px; padding: 10px; border: 1px solid #e2e8f0; border-radius: 8px; }
.summary-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
.summary-card { padding: 10px; border: 1px solid #e2e8f0; border-radius: 8px; background: #fff; }
.summary-card span { display: block; color: #64748b; font-size: 12px; }
.summary-card strong { display: block; margin-top: 2px; color: #0f172a; font-size: 18px; }
:deep(.curricula-row--selected) { background: #f8fafc; }
@media (max-width: 1100px) { .curricula-workspace { grid-template-columns: 1fr !important; gap: 16px; } .curricula-main, .curricula-side { padding: 0; } .curricula-side { position: static; } }
</style>
