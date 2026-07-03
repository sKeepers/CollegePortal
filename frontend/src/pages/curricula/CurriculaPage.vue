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
import AppCard from '../../components/ui/AppCard.vue'
import AppEmptyState from '../../components/ui/AppEmptyState.vue'
import AppLoading from '../../components/ui/AppLoading.vue'
import AppErrorBanner from '../../components/ui/AppErrorBanner.vue'
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'
import AppConfirmDialog from '../../components/ui/AppConfirmDialog.vue'
import { TABLE_ROWS_PER_PAGE_OPTIONS, createTablePagination, persistTablePagination } from '../../services/tableSettings'
import { CONTROL_FORM_OPTIONS, CURRICULUM_STATUS_OPTIONS, statusLabel, statusTone, useCurriculaStore } from '../../stores/curricula'

const store = useCurriculaStore()
const $q = useQuasar()
const route = useRoute()
const router = useRouter()
const rowsPerPageKey = 'collegePortal.curricula.rowsPerPage'
const syncingQuery = ref(false)
const formVisible = ref(false)
const itemFormVisible = ref(false)
const deleteDialogVisible = ref(false)
const itemDeleteDialogVisible = ref(false)
const editingCurriculum = ref(null)
const deletingCurriculum = ref(null)
const deletingItem = ref(null)
const importFile = ref(null)
const tablePagination = ref(createTablePagination(rowsPerPageKey, { sortBy: 'year_start', descending: true, rowsPerPage: 20 }))
const codeEditable = ref(false)
const curriculumForm = reactive({ code: '', education_program_id: '', name: '', year_start: new Date().getFullYear(), status: 'draft', description: '' })
const itemForm = reactive({ subject_id: '', course: 1, semester: 1, hours_total: 0, control_form: 'Зачет', sort_order: 0 })
const columns = [
  { name: 'code', label: 'Код', field: 'code', align: 'left', sortable: true },
  { name: 'name', label: 'Учебный план', field: 'name', align: 'left', sortable: true },
  { name: 'program', label: 'Программа', field: 'program', align: 'left', sortable: true },
  { name: 'year_start', label: 'Год', field: 'year_start', align: 'left', sortable: true },
  { name: 'status', label: 'Статус', field: 'status', align: 'left', sortable: true },
  { name: 'items_count', label: 'Дисциплин', field: 'items_count', align: 'left', sortable: true },
  { name: 'actions', label: '', field: 'actions', align: 'right' },
]
const itemColumns = [
  { name: 'subject', label: 'Дисциплина', field: 'subject', align: 'left' },
  { name: 'course', label: 'Курс', field: 'course', align: 'left', sortable: true },
  { name: 'semester', label: 'Семестр', field: 'semester', align: 'left', sortable: true },
  { name: 'hours_total', label: 'Часы', field: 'hours_total', align: 'left', sortable: true },
  { name: 'control_form', label: 'Контроль', field: 'control_form', align: 'left' },
  { name: 'actions', label: '', field: 'actions', align: 'right' },
]
const tableSubtitle = computed(() => `Найдено учебных планов: ${store.filteredCurricula.length}`)
function programName(curriculum) { return curriculum?.education_program?.name || '—' }
function specialtyName(curriculum) { return curriculum?.education_program?.specialty?.name || '—' }
function subjectName(item) { return [item?.subject?.code, item?.subject?.name].filter(Boolean).join(' · ') || '—' }
function notify(message) { $q.notify({ type: 'positive', message, position: 'top-right', timeout: 1800 }) }
function rowClass(row) { return Number(row.id) === Number(store.selectedId) ? 'curricula-row--selected' : '' }
function updatePagination(pagination) { tablePagination.value = pagination; persistTablePagination(rowsPerPageKey, pagination) }
function routeSelectedId() { return route.query.selected ? String(route.query.selected) : '' }
async function syncQuery(selectedId = routeSelectedId()) { const query = { ...route.query }; selectedId ? query.selected = selectedId : delete query.selected; syncingQuery.value = true; await router.replace({ path: '/curricula', query }); syncingQuery.value = false }
async function selectCurriculum(curriculum) { store.select(curriculum); await syncQuery(curriculum?.id || '') }
function openCreateForm() { editingCurriculum.value = null; codeEditable.value = false; Object.assign(curriculumForm, { code: '', education_program_id: '', name: '', year_start: new Date().getFullYear(), status: 'draft', description: '' }); formVisible.value = true }
function openEditForm(curriculum) { editingCurriculum.value = curriculum; codeEditable.value = false; Object.assign(curriculumForm, { code: curriculum.code || '', education_program_id: curriculum.education_program_id, name: curriculum.name, year_start: curriculum.year_start, status: curriculum.status || 'draft', description: curriculum.description || '' }); formVisible.value = true }
async function saveCurriculum() { const isEdit = Boolean(editingCurriculum.value?.id); await store.save(curriculumForm, editingCurriculum.value?.id || null); formVisible.value = false; notify(isEdit ? 'Учебный план обновлен' : 'Учебный план создан') }
function requestDelete(curriculum) { deletingCurriculum.value = curriculum; deleteDialogVisible.value = true }
async function confirmDelete() { await store.remove(deletingCurriculum.value); deletingCurriculum.value = null; notify('Учебный план удален') }
function openItemForm() { Object.assign(itemForm, { subject_id: '', course: 1, semester: 1, hours_total: 0, control_form: 'Зачет', sort_order: store.selectedItems.length + 1 }); itemFormVisible.value = true }
async function addItem() { await store.addItem(itemForm); itemFormVisible.value = false; notify('Дисциплина добавлена в учебный план') }
function requestItemDelete(item) { deletingItem.value = item; itemDeleteDialogVisible.value = true }
async function confirmItemDelete() { await store.removeItem(deletingItem.value); deletingItem.value = null; notify('Строка учебного плана удалена') }
async function applyFilters() { store.setFilters({ ...store.filters }); await syncQuery('') }
async function resetFilters() { store.resetFilters(); await syncQuery('') }
async function handleImport(file) { if (!file) return; await store.importCsv(file); importFile.value = null; notify('Импорт учебных планов завершен') }
async function exportCsv() { await store.exportCsv(); notify('Экспорт учебных планов подготовлен') }
watch(() => route.query.selected, () => { if (!syncingQuery.value) store.selectById(routeSelectedId()) })
onMounted(async () => { store.selectById(routeSelectedId()); await store.load(); if (!store.selectedCurriculum && store.filteredCurricula[0]) { await selectCurriculum(store.filteredCurricula[0]) } })
</script>

<template>
  <AppPage>
    <PageHeader title="Учебные планы" subtitle="Планы образовательных программ, дисциплины по курсам и семестрам, часы и формы контроля.">
      <template #actions><q-btn color="primary" @click="openCreateForm"><Plus :size="16" class="q-mr-xs" /> Новый план</q-btn></template>
    </PageHeader>
    <AppToolbar><span>{{ tableSubtitle }}</span><template #actions><AppLoading v-if="store.loading" label="Загрузка учебных планов..." /><q-btn flat :disable="store.loading" @click="store.load"><RefreshCw :size="16" class="q-mr-xs" /> Обновить</q-btn><q-file v-model="importFile" dense outlined accept=".csv,text/csv" label="Импорт" style="max-width: 180px" @update:model-value="handleImport"><template #prepend><Upload :size="16" /></template></q-file><q-btn color="primary" @click="exportCsv"><Download :size="16" class="q-mr-xs" /> Экспорт</q-btn></template></AppToolbar>
    <AppErrorBanner :message="store.error" />
    <AppFilterBar><q-input v-model="store.filters.search" dense outlined clearable label="Поиск" @keyup.enter="applyFilters" /><q-select v-model="store.filters.specialty_id" dense outlined clearable emit-value map-options label="Специальность" :options="store.specialtyOptions" /><q-select v-model="store.filters.education_program_id" dense outlined clearable emit-value map-options label="Программа" :options="store.programOptions" /><q-select v-model="store.filters.year_start" dense outlined clearable emit-value map-options label="Год" :options="store.yearOptions" /><template #actions><q-btn color="primary" @click="applyFilters">Применить</q-btn><q-btn flat @click="resetFilters">Сбросить</q-btn></template></AppFilterBar>
    <div class="curricula-workspace">
      <div class="curricula-main"><AppTable v-if="store.filteredCurricula.length || store.loading" :rows="store.filteredCurricula" :columns="columns" :loading="store.loading" :pagination="tablePagination" :rows-per-page-options="TABLE_ROWS_PER_PAGE_OPTIONS" :table-row-class-fn="rowClass" @update:pagination="updatePagination" @row-click="(_, row) => selectCurriculum(row)"><template #body-cell-name="props"><q-td :props="props"><button class="curricula-row-link" type="button" @click.stop="selectCurriculum(props.row)">{{ props.row.name }}</button><div class="curricula-secondary-cell">{{ specialtyName(props.row) }}</div></q-td></template><template #body-cell-program="props"><q-td :props="props">{{ programName(props.row) }}</q-td></template><template #body-cell-status="props"><q-td :props="props"><AppStatusBadge :label="statusLabel(props.row.status)" :tone="statusTone(props.row.status)" /></q-td></template><template #body-cell-actions="props"><q-td :props="props"><q-btn flat round dense title="Редактировать" @click.stop="openEditForm(props.row)"><Edit3 :size="16" /></q-btn><q-btn flat round dense color="negative" title="Удалить" @click.stop="requestDelete(props.row)"><Trash2 :size="16" /></q-btn></q-td></template></AppTable><AppEmptyState v-else title="Учебные планы не найдены" description="Создайте учебный план или импортируйте CSV." /></div>
      <aside class="curricula-side"><AppCard class="curricula-card"><AppEmptyState v-if="!store.selectedCurriculum" title="Учебный план не выбран" description="Выберите строку в таблице, чтобы открыть состав плана." /><div v-else class="curricula-details"><div class="curricula-details__hero"><h2>{{ store.selectedCurriculum.name }}</h2><AppStatusBadge :label="statusLabel(store.selectedCurriculum.status)" :tone="statusTone(store.selectedCurriculum.status)" /><p>{{ programName(store.selectedCurriculum) }}</p></div><div class="curricula-details__metrics"><div><span>Год</span><strong>{{ store.selectedCurriculum.year_start }}</strong></div><div><span>Дисциплин</span><strong>{{ store.selectedItems.length }}</strong></div><div><span>Часов</span><strong>{{ store.selectedHours }}</strong></div></div><section><h3>Основное</h3><dl class="curricula-details__list"><div><dt>Специальность</dt><dd>{{ specialtyName(store.selectedCurriculum) }}</dd></div><div><dt>Код</dt><dd>{{ store.selectedCurriculum.code || '—' }}</dd></div><div><dt>Программа</dt><dd>{{ programName(store.selectedCurriculum) }}</dd></div><div><dt>Описание</dt><dd>{{ store.selectedCurriculum.description || '—' }}</dd></div></dl></section><section><div class="curricula-section-header"><h3>Дисциплины</h3><q-btn dense color="primary" :disable="store.saving" @click="openItemForm"><BookPlus :size="15" class="q-mr-xs" /> Добавить</q-btn></div><AppTable v-if="store.selectedItems.length" :rows="store.selectedItems" :columns="itemColumns" :pagination="{ rowsPerPage: 0 }" :rows-per-page-options="[0]"><template #body-cell-subject="props"><q-td :props="props">{{ subjectName(props.row) }}</q-td></template><template #body-cell-actions="props"><q-td :props="props"><q-btn flat round dense color="negative" title="Удалить" @click="requestItemDelete(props.row)"><Trash2 :size="15" /></q-btn></q-td></template></AppTable><p v-else class="curricula-muted">Дисциплины пока не добавлены.</p></section></div></AppCard></aside>
    </div>
    <q-dialog v-model="formVisible"><q-card class="curricula-dialog"><q-card-section><div class="text-h6">{{ editingCurriculum ? 'Редактировать учебный план' : 'Новый учебный план' }}</div></q-card-section><q-card-section class="curricula-dialog__body"><q-input v-model="curriculumForm.code" outlined dense label="Код" placeholder="Будет создан автоматически" :readonly="!codeEditable"><template #append><q-btn flat round dense title="Разрешить ручное редактирование" @click="codeEditable = true"><Edit3 :size="15" /></q-btn></template></q-input><q-select v-model="curriculumForm.education_program_id" outlined dense emit-value map-options label="Образовательная программа" :options="store.programOptions" /><q-input v-model="curriculumForm.name" outlined dense label="Название" /><q-input v-model="curriculumForm.year_start" outlined dense type="number" label="Год начала" /><q-select v-model="curriculumForm.status" outlined dense emit-value map-options label="Статус" :options="CURRICULUM_STATUS_OPTIONS" /><q-input v-model="curriculumForm.description" outlined dense type="textarea" label="Описание" /></q-card-section><q-card-actions align="right"><q-btn flat label="Отмена" @click="formVisible = false" /><q-btn color="primary" :loading="store.saving" :disable="!curriculumForm.education_program_id || !curriculumForm.name" @click="saveCurriculum">Сохранить</q-btn></q-card-actions></q-card></q-dialog>
    <q-dialog v-model="itemFormVisible"><q-card class="curricula-dialog"><q-card-section><div class="text-h6">Добавить дисциплину</div></q-card-section><q-card-section class="curricula-dialog__body"><q-select v-model="itemForm.subject_id" outlined dense emit-value map-options label="Дисциплина" :options="store.subjectOptions" /><q-input v-model="itemForm.course" outlined dense type="number" label="Курс" /><q-input v-model="itemForm.semester" outlined dense type="number" label="Семестр" /><q-input v-model="itemForm.hours_total" outlined dense type="number" label="Часы" /><q-select v-model="itemForm.control_form" outlined dense clearable label="Форма контроля" :options="CONTROL_FORM_OPTIONS" /><q-input v-model="itemForm.sort_order" outlined dense type="number" label="Порядок" /></q-card-section><q-card-actions align="right"><q-btn flat label="Отмена" @click="itemFormVisible = false" /><q-btn color="primary" :loading="store.saving" :disable="!itemForm.subject_id" @click="addItem">Добавить</q-btn></q-card-actions></q-card></q-dialog>
    <AppConfirmDialog v-model="deleteDialogVisible" title="Удалить учебный план?" :message="deletingCurriculum ? `Будет удален план: ${deletingCurriculum.name}.` : 'Будет удален выбранный план.'" confirm-label="Удалить" tone="negative" @confirm="confirmDelete" />
    <AppConfirmDialog v-model="itemDeleteDialogVisible" title="Удалить дисциплину из плана?" message="Строка будет удалена из учебного плана." confirm-label="Удалить" tone="negative" @confirm="confirmItemDelete" />
  </AppPage>
</template>
