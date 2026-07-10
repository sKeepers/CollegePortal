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
import { TABLE_ROWS_PER_PAGE_OPTIONS, createTablePagination, persistTablePagination } from '../../services/tableSettings'
import { useReferenceOptionsStore } from '../../stores/referenceOptions'
import { EXAM_STATUS_OPTIONS, RESULT_OPTIONS, RESULT_STATUS_OPTIONS, classroomName, examStatusLabel, examStatusTone, formatRuDate, resultStatusLabel, resultStatusTone, studentName, subjectName, teacherName, useExamsStore } from '../../stores/exams'

const store = useExamsStore()
const referenceOptions = useReferenceOptionsStore()
const $q = useQuasar(), route = useRoute(), router = useRouter()
const rowsKey = 'collegePortal.exams.rowsPerPage'
const syncingQuery = ref(false), formVisible = ref(false), resultFormVisible = ref(false), deleteDialogVisible = ref(false), resultDeleteDialogVisible = ref(false)
const editingExam = ref(null), editingResult = ref(null), deletingExam = ref(null), deletingResult = ref(null), importFile = ref(null)
const tablePagination = ref(createTablePagination(rowsKey, { sortBy: 'exam_date', descending: true, rowsPerPage: 20 }))
const examForm = reactive({ academic_year: '2026/2027', semester: 1, group_id: '', subject_id: '', teacher_id: '', classroom_id: '', exam_date: '', starts_at: '', ends_at: '', exam_type: 'exam', status: 'scheduled', topic: '' })
const resultForm = reactive({ student_id: '', result: '', score: '', status: 'planned', comment: '' })
const columns = [
  { name: 'date', label: 'Дата и время', field: 'exam_date', align: 'left', sortable: true },
  { name: 'group', label: 'Группа', field: 'group', align: 'left', sortable: true },
  { name: 'subject', label: 'Дисциплина', field: 'subject', align: 'left', sortable: true },
  { name: 'teacher', label: 'Преподаватель', field: 'teacher', align: 'left', sortable: true },
  { name: 'type', label: 'Тип', field: 'exam_type', align: 'left', sortable: true },
  { name: 'status', label: 'Статус', field: 'status', align: 'left', sortable: true },
  { name: 'results', label: 'Результаты', field: 'results_count', align: 'left' },
  { name: 'actions', label: '', field: 'actions', align: 'right' },
]
const resultColumns = [
  { name: 'student', label: 'Студент', field: 'student', align: 'left' },
  { name: 'result', label: 'Итог', field: 'result', align: 'left' },
  { name: 'score', label: 'Балл', field: 'score', align: 'left' },
  { name: 'status', label: 'Стат.', field: 'status', align: 'left' },
  { name: 'actions', label: '', field: 'actions', align: 'right' },
]
const tableSubtitle = computed(() => `Найдено экзаменов: ${store.filteredExams.length}`)
const examTypeOptions = computed(() => referenceOptions.options('exam_types'))
function examTypeLabel(value) { return referenceOptions.label('exam_types', value, value || '—') }
function examTypeTone(value) { return referenceOptions.tone('exam_types', value, 'neutral') }
const selectedStudentOptions = computed(() => store.selectedExam?.group_id ? store.studentOptions.filter((student) => Number(student.group_id) === Number(store.selectedExam.group_id)) : store.studentOptions)
const groupRoute = computed(() => ({ path: '/groups', query: { selected: store.selectedExam?.group_id } }))
const subjectRoute = computed(() => ({ path: '/subjects', query: { selected: store.selectedExam?.subject_id } }))
const teacherRoute = computed(() => ({ path: '/teachers', query: { selected: store.selectedExam?.teacher_id } }))
const classroomRoute = computed(() => ({ path: '/classrooms', query: { selected: store.selectedExam?.classroom_id } }))
const journalRoute = computed(() => ({ path: '/journal', query: { group: store.selectedExam?.group_id, subject: store.selectedExam?.subject_id } }))

const examMetrics = computed(() => [
  { label: 'Год', value: store.selectedExam?.academic_year || '—' },
  { label: 'Семестр', value: store.selectedExam?.semester || '—' },
  { label: 'Результатов', value: store.selectedResults.length },
  { label: 'Время', value: timeRange(store.selectedExam) },
])
const examActions = computed(() => [
  { label: 'Группа', to: groupRoute.value },
  { label: 'Дисциплина', to: subjectRoute.value },
  { label: 'Преподаватель', to: teacherRoute.value },
  { label: 'Аудитория', to: classroomRoute.value, disabled: !store.selectedExam?.classroom_id },
  { label: 'Журнал', to: journalRoute.value },
])
function timeRange(exam) { return [exam?.starts_at, exam?.ends_at].filter(Boolean).join('–') || 'Время не указано' }
function rowClass(row) { return Number(row.id) === Number(store.selectedId) ? 'exams-row--selected' : '' }
function notify(message) { $q.notify({ type: 'positive', message, position: 'top-right', timeout: 1800 }) }
function updatePagination(p) { tablePagination.value = p; persistTablePagination(rowsKey, p) }
function routeSelectedId() { return route.query.selected ? String(route.query.selected) : '' }
async function syncQuery(selectedId = routeSelectedId()) { const query = { ...route.query }; selectedId ? query.selected = selectedId : delete query.selected; syncingQuery.value = true; await router.replace({ path: '/exams', query }); syncingQuery.value = false }
async function selectExam(exam) { store.select(exam); await syncQuery(exam?.id || '') }
function openCreateForm() { editingExam.value = null; Object.assign(examForm, { academic_year: '2026/2027', semester: 1, group_id: '', subject_id: '', teacher_id: '', classroom_id: '', exam_date: new Date().toISOString().slice(0, 10), starts_at: '09:00', ends_at: '10:30', exam_type: 'exam', status: 'scheduled', topic: '' }); formVisible.value = true }
function openEditForm(exam) { editingExam.value = exam; Object.assign(examForm, { academic_year: exam.academic_year, semester: exam.semester, group_id: exam.group_id, subject_id: exam.subject_id, teacher_id: exam.teacher_id, classroom_id: exam.classroom_id || '', exam_date: exam.exam_date, starts_at: exam.starts_at || '', ends_at: exam.ends_at || '', exam_type: exam.exam_type || 'exam', status: exam.status || 'scheduled', topic: exam.topic || '' }); formVisible.value = true }
async function saveExam() { const isEdit = Boolean(editingExam.value?.id); await store.save(examForm, editingExam.value?.id || null); formVisible.value = false; notify(isEdit ? 'Экзамен обновлен' : 'Экзамен создан') }
function requestDelete(exam) { deletingExam.value = exam; deleteDialogVisible.value = true }
async function confirmDelete() { await store.remove(deletingExam.value); deletingExam.value = null; notify('Экзамен удален') }
function openResultForm(result = null) { editingResult.value = result; Object.assign(resultForm, { student_id: result?.student_id || '', result: result?.result || '', score: result?.score ?? '', status: result?.status || 'planned', comment: result?.comment || '' }); resultFormVisible.value = true }
async function saveResult() { const isEdit = Boolean(editingResult.value?.id); await store.saveResult(resultForm); resultFormVisible.value = false; notify(isEdit ? 'Результат обновлен' : 'Результат добавлен') }
function requestResultDelete(result) { deletingResult.value = result; resultDeleteDialogVisible.value = true }
async function confirmResultDelete() { await store.removeResult(deletingResult.value); deletingResult.value = null; notify('Результат удален') }
async function applyFilters() { store.setFilters({ ...store.filters }); await syncQuery('') }
async function resetFilters() { store.resetFilters(); await syncQuery('') }
async function handleImport(file) { if (!file) return; await store.importCsv(file); importFile.value = null; notify('Импорт экзаменов завершен') }
async function exportCsv() { await store.exportCsv(); notify('Экспорт экзаменов подготовлен') }
watch(() => route.query.selected, () => { if (!syncingQuery.value) store.selectById(routeSelectedId()) })
onMounted(async () => { await referenceOptions.loadCatalog('exam_types'); store.selectById(routeSelectedId()); await store.load(); if (!store.selectedExam && store.filteredExams[0]) await selectExam(store.filteredExams[0]) })
</script>

<template>
  <AppPage>
    <PageHeader title="Экзамены и ГИА" subtitle="Экзамены, зачеты, ГИА и результаты студентов."><template #actions><q-btn color="primary" @click="openCreateForm"><Plus :size="16" class="q-mr-xs" /> Новый экзамен</q-btn></template></PageHeader>
    <AppToolbar><span>{{ tableSubtitle }}</span><template #actions><AppLoading v-if="store.loading" label="Загрузка экзаменов..." /><q-btn flat :disable="store.loading" @click="store.load"><RefreshCw :size="16" class="q-mr-xs" /> Обновить</q-btn><q-file v-model="importFile" dense outlined accept=".csv,text/csv" label="Импорт" style="max-width: 180px" @update:model-value="handleImport"><template #prepend><Upload :size="16" /></template></q-file><q-btn color="primary" @click="exportCsv"><Download :size="16" class="q-mr-xs" /> Экспорт</q-btn></template></AppToolbar>
    <AppErrorBanner :message="store.error" />
    <AppFilterBar><q-select v-model="store.filters.academic_year" dense outlined clearable emit-value map-options label="Учебный год" :options="store.academicYearOptions" /><q-select v-model="store.filters.group_id" dense outlined clearable emit-value map-options label="Группа" :options="store.groupOptions" /><q-select v-model="store.filters.subject_id" dense outlined clearable emit-value map-options label="Дисциплина" :options="store.subjectOptions" /><q-select v-model="store.filters.teacher_id" dense outlined clearable emit-value map-options label="Преподаватель" :options="store.teacherOptions" /><q-select v-model="store.filters.exam_type" dense outlined clearable emit-value map-options label="Тип" :options="examTypeOptions" /><template #actions><q-btn color="primary" @click="applyFilters">Применить</q-btn><q-btn flat @click="resetFilters">Сбросить</q-btn></template></AppFilterBar>
    <div class="exams-workspace"><div class="exams-main"><AppTable v-if="store.filteredExams.length || store.loading" :rows="store.filteredExams" :columns="columns" :loading="store.loading" :pagination="tablePagination" :rows-per-page-options="TABLE_ROWS_PER_PAGE_OPTIONS" :table-row-class-fn="rowClass" @update:pagination="updatePagination" @row-click="(_, row) => selectExam(row)"><template #body-cell-date="props"><q-td :props="props"><button class="exams-row-link" type="button" @click.stop="selectExam(props.row)">{{ formatRuDate(props.row.exam_date) }}</button><div class="exams-secondary-cell">{{ timeRange(props.row) }}</div></q-td></template><template #body-cell-group="props"><q-td :props="props">{{ props.row.group?.name || '—' }}</q-td></template><template #body-cell-subject="props"><q-td :props="props"><div class="exams-strong-cell">{{ subjectName(props.row.subject) }}</div><div class="exams-secondary-cell">{{ props.row.topic || 'Тема не указана' }}</div></q-td></template><template #body-cell-teacher="props"><q-td :props="props">{{ teacherName(props.row.teacher) }}</q-td></template><template #body-cell-type="props"><q-td :props="props"><AppStatusBadge :label="examTypeLabel(props.row.exam_type)" :tone="examTypeTone(props.row.exam_type)" /></q-td></template><template #body-cell-status="props"><q-td :props="props"><AppStatusBadge :label="examStatusLabel(props.row.status)" :tone="examStatusTone(props.row.status)" /></q-td></template><template #body-cell-results="props"><q-td :props="props">{{ props.row.results_count ?? props.row.results?.length ?? 0 }}</q-td></template><template #body-cell-actions="props"><q-td :props="props"><q-btn flat round dense title="Редактировать" @click.stop="openEditForm(props.row)"><Edit3 :size="16" /></q-btn><q-btn flat round dense color="negative" title="Удалить" @click.stop="requestDelete(props.row)"><Trash2 :size="16" /></q-btn></q-td></template></AppTable><AppEmptyState v-else title="Экзамены не найдены" description="Создайте экзамен или импортируйте CSV." /></div>
      <aside class="exams-side"><AppEmptyState v-if="!store.selectedExam" title="Экзамен не выбран" description="Выберите строку в таблице, чтобы открыть карточку экзамена." /><WorkspacePanel v-else class="exams-card" :title="subjectName(store.selectedExam.subject)" :subtitle="`${store.selectedExam.group?.name || 'Группа не указана'} · ${formatRuDate(store.selectedExam.exam_date)}`" :metrics="examMetrics" :actions="examActions"><template #status><AppStatusBadge :label="examTypeLabel(store.selectedExam.exam_type)" :tone="examTypeTone(store.selectedExam.exam_type)" /><AppStatusBadge :label="examStatusLabel(store.selectedExam.status)" :tone="examStatusTone(store.selectedExam.status)" /></template><div class="exams-details"><section><h3>Основное</h3><dl class="exams-fields"><div><dt>Преподаватель</dt><dd>{{ teacherName(store.selectedExam.teacher) }}</dd></div><div><dt>Аудитория</dt><dd>{{ classroomName(store.selectedExam.classroom) }}</dd></div><div><dt>Тема</dt><dd>{{ store.selectedExam.topic || 'Не указана' }}</dd></div></dl></section><section><div class="exams-section-header"><h3>Результаты студентов</h3><q-btn dense color="primary" :disable="store.saving" @click="openResultForm()"><Plus :size="15" class="q-mr-xs" /> Добавить</q-btn></div><AppTable v-if="store.selectedResults.length" class="exams-results-table" :rows="store.selectedResults" :columns="resultColumns" :pagination="{ rowsPerPage: 0 }" :rows-per-page-options="[0]"><template #body-cell-student="props"><q-td :props="props"><RouterLink :to="{ path: '/students', query: { selected: props.row.student_id } }" class="entity-link-action">{{ studentName(props.row.student) }}</RouterLink><div v-if="props.row.comment" class="exams-secondary-cell">{{ props.row.comment }}</div></q-td></template><template #body-cell-result="props"><q-td :props="props"><strong>{{ props.row.result || '—' }}</strong></q-td></template><template #body-cell-score="props"><q-td :props="props">{{ props.row.score ?? '—' }}</q-td></template><template #body-cell-status="props"><q-td :props="props"><AppStatusBadge :label="resultStatusLabel(props.row.status)" :tone="resultStatusTone(props.row.status)" /></q-td></template><template #body-cell-actions="props"><q-td :props="props"><q-btn flat round dense title="Редактировать" @click="openResultForm(props.row)"><Edit3 :size="15" /></q-btn><q-btn flat round dense color="negative" title="Удалить" @click="requestResultDelete(props.row)"><Trash2 :size="15" /></q-btn></q-td></template></AppTable><p v-else class="exams-muted">Результаты по выбранному экзамену пока не внесены.</p></section></div></WorkspacePanel></aside></div>
    <q-dialog v-model="formVisible"><q-card class="exams-dialog"><q-card-section><div class="text-h6">{{ editingExam ? 'Редактировать экзамен' : 'Новый экзамен' }}</div></q-card-section><q-card-section class="exams-dialog__body"><q-input v-model="examForm.academic_year" outlined dense label="Учебный год" /><q-input v-model="examForm.semester" outlined dense type="number" label="Семестр" /><q-select v-model="examForm.group_id" outlined dense emit-value map-options label="Группа" :options="store.groupOptions" /><q-select v-model="examForm.subject_id" outlined dense emit-value map-options label="Дисциплина" :options="store.subjectOptions" /><q-select v-model="examForm.teacher_id" outlined dense emit-value map-options label="Преподаватель" :options="store.teacherOptions" /><q-select v-model="examForm.classroom_id" outlined dense clearable emit-value map-options label="Аудитория" :options="store.classroomOptions" /><q-input v-model="examForm.exam_date" outlined dense type="date" label="Дата" /><q-input v-model="examForm.starts_at" outlined dense type="time" label="Начало" /><q-input v-model="examForm.ends_at" outlined dense type="time" label="Окончание" /><q-select v-model="examForm.exam_type" outlined dense emit-value map-options label="Тип" :options="examTypeOptions" /><q-select v-model="examForm.status" outlined dense emit-value map-options label="Статус" :options="EXAM_STATUS_OPTIONS" /><q-input v-model="examForm.topic" outlined dense label="Тема / примечание" /></q-card-section><q-card-actions align="right"><q-btn flat label="Отмена" @click="formVisible = false" /><q-btn color="primary" :loading="store.saving" :disable="!examForm.academic_year || !examForm.group_id || !examForm.subject_id || !examForm.teacher_id || !examForm.exam_date" @click="saveExam">Сохранить</q-btn></q-card-actions></q-card></q-dialog>
    <q-dialog v-model="resultFormVisible"><q-card class="exams-dialog"><q-card-section><div class="text-h6">{{ editingResult ? 'Редактировать результат' : 'Добавить результат' }}</div></q-card-section><q-card-section class="exams-dialog__body"><q-select v-model="resultForm.student_id" outlined dense emit-value map-options label="Студент" :options="selectedStudentOptions" :disable="Boolean(editingResult)" /><q-select v-model="resultForm.result" outlined dense clearable label="Оценка / отметка" :options="RESULT_OPTIONS" /><q-input v-model="resultForm.score" outlined dense type="number" label="Баллы" /><q-select v-model="resultForm.status" outlined dense emit-value map-options label="Статус" :options="RESULT_STATUS_OPTIONS" /><q-input v-model="resultForm.comment" outlined dense type="textarea" label="Комментарий" /></q-card-section><q-card-actions align="right"><q-btn flat label="Отмена" @click="resultFormVisible = false" /><q-btn color="primary" :loading="store.saving" :disable="!resultForm.student_id" @click="saveResult">Сохранить</q-btn></q-card-actions></q-card></q-dialog>
    <AppConfirmDialog v-model="deleteDialogVisible" title="Удалить экзамен?" :message="deletingExam ? `Будет удален экзамен: ${subjectName(deletingExam.subject)} (${formatRuDate(deletingExam.exam_date)}).` : 'Будет удален выбранный экзамен.'" confirm-label="Удалить" tone="negative" @confirm="confirmDelete" />
    <AppConfirmDialog v-model="resultDeleteDialogVisible" title="Удалить результат?" :message="deletingResult ? `Будет удален результат студента: ${studentName(deletingResult.student)}.` : 'Будет удален выбранный результат.'" confirm-label="Удалить" tone="negative" @confirm="confirmResultDelete" />
  </AppPage>
</template>
