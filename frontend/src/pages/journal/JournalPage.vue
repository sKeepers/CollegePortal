<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useQuasar } from 'quasar'
import { useRoute } from 'vue-router'
import { RefreshCw, Save, UserCheck, UserX, Wand2 } from '@lucide/vue'
import AppPage from '../../components/ui/AppPage.vue'
import PageHeader from '../../components/ui/PageHeader.vue'
import AppToolbar from '../../components/ui/AppToolbar.vue'
import AppEmptyState from '../../components/ui/AppEmptyState.vue'
import AppErrorBanner from '../../components/ui/AppErrorBanner.vue'
import AppLoading from '../../components/ui/AppLoading.vue'
import AppConfirmDialog from '../../components/ui/AppConfirmDialog.vue'
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'
import JournalFilters from './JournalFilters.vue'
import JournalLessonPanel from './JournalLessonPanel.vue'
import { useJournalStore } from '../../stores/journal'
import { usePermissions } from '../../composables/usePermissions'

const store = useJournalStore()
const route = useRoute()
const { hasPermission } = usePermissions()
const $q = useQuasar()
const selectedStudent = ref(null)
const selectedStudentIds = ref([])
const attendanceDraft = reactive({})
const gradeDraft = reactive({})
const signDialogVisible = ref(false)
const historyFilters = reactive({ status: '' })

const modeOptions = [
  { label: 'Мои занятия', value: 'mine' },
  { label: 'Завтра', value: 'tomorrow' },
  { label: 'Текущая неделя', value: 'week' },
  { label: 'Завершенные', value: 'completed' },
  { label: 'Не заполненные', value: 'not_filled' },
  { label: 'Подписанные', value: 'signed' },
  { label: 'Контроль журналов', value: 'control', permission: 'journal.view_all' },
]

const visibleModes = computed(() => modeOptions.filter((mode) => !mode.permission || hasPermission(mode.permission)))
const isReadOnly = computed(() => store.selectedLesson?.status === 'signed' && !hasPermission('journal.reopen'))
const canEdit = computed(() => hasPermission('journal.edit') && !isReadOnly.value)
const canAttendance = computed(() => hasPermission('journal.attendance') && !isReadOnly.value)
const canGrades = computed(() => hasPermission('journal.grades') && !isReadOnly.value)
const canRequestEdit = computed(() => hasPermission('journal.edit') && store.selectedLesson?.status === 'signed')

const tableSubtitle = computed(() => {
  const stats = store.dashboardStats
  return `Занятий: ${store.journalLessons.length}; не заполнено: ${stats.needsFill}; ожидают подписи: ${stats.awaitingSign}`
})

function markTone(cell) {
  if (cell.type === 'grade') return Number(cell.value) >= 4 ? 'success' : 'warning'
  return { present: 'success', absent: 'danger', late: 'warning', excused: 'info', sick: 'info', remote: 'info', empty: 'neutral' }[cell.type] || 'neutral'
}

function shortLessonTitle(lesson) {
  return [lesson.lesson_date, lesson.starts_at].filter(Boolean).join(' ')
}

function statusLabel(status) {
  return {
    draft: 'Черновик',
    in_progress: 'В работе',
    planned: 'Запланировано',
    opened: 'Открыто',
    completed: 'Завершено',
    signed: 'Подписано',
    reopened: 'Переоткрыто',
    cancelled: 'Отменено',
  }[status] || 'Статус не указан'
}

function statusTone(status) {
  return {
    draft: 'neutral', in_progress: 'info', planned: 'neutral', opened: 'info', completed: 'warning', signed: 'success', reopened: 'warning', cancelled: 'danger',
  }[status] || 'neutral'
}

function requestStatusLabel(status) {
  return { pending: 'Ожидает решения', approved: 'Одобрен', rejected: 'Отклонен' }[status] || 'Статус не указан'
}

function auditActionLabel(action) {
  return {
    edit_requested: 'Запрос создан', edit_request_approved: 'Запрос одобрен', edit_request_rejected: 'Запрос отклонен',
    reopen: 'Журнал переоткрыт', update_lesson: 'Изменены сведения занятия', attendance_update: 'Изменена посещаемость', grade_update: 'Изменена оценка',
  }[action] || action
}

function changeSummary(change) {
  const labels = { topic: 'Тема', homework: 'Домашнее задание', teacher_comment: 'Комментарий преподавателя', status: 'Статус', attendance_status: 'Посещаемость', minutes_late: 'Опоздание, мин.', value: 'Оценка', comment: 'Комментарий' }
  const oldValues = change.old_values || {}
  const newValues = change.new_values || {}
  return Object.keys({ ...oldValues, ...newValues })
    .filter((key) => !['id', 'journal_lesson_id', 'created_at', 'updated_at', 'marked_at', 'marked_by'].includes(key))
    .map((key) => `${labels[key] || key}: ${oldValues[key] ?? '—'} -> ${newValues[key] ?? '—'}`)
    .join('; ') || 'Без изменения полей'
}

function formatDateTime(value) {
  return value ? new Intl.DateTimeFormat('ru-RU', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value)) : '—'
}

function suggestionLabel(suggestion) {
  return {
    probably_present: 'Вероятно присутствовал',
    probably_late: 'Вероятно опоздал',
    probably_absent: 'Вероятно отсутствовал',
    no_data: 'Нет событий проходной',
  }[suggestion] || 'Нет данных'
}

function resetDrafts() {
  selectedStudentIds.value = []
  Object.keys(attendanceDraft).forEach((key) => delete attendanceDraft[key])
  Object.keys(gradeDraft).forEach((key) => delete gradeDraft[key])
  store.lessonStudents.forEach((row) => {
    attendanceDraft[row.student_id] = {
      student_id: row.student_id,
      status: row.attendance_status || 'present',
      minutes_late: row.minutes_late || '',
      comment: row.attendance_comment || '',
    }
    gradeDraft[row.student_id] = {
      student_id: row.student_id,
      value: row.grade_value || '',
      comment: row.grade_comment || '',
    }
  })
}

watch(() => store.lessonStudents, resetDrafts, { immediate: true, deep: true })

function selectStudent(student) { selectedStudent.value = student }
async function selectLesson(lesson) { selectedStudent.value = null; await store.selectLesson(lesson); resetDrafts() }
async function applyFilters(filters) { selectedStudent.value = null; store.setFilters(filters); await store.load() }
async function resetFilters() { selectedStudent.value = null; store.resetFilters(); await store.load() }
async function refresh() { await store.load(); if (hasPermission('journal.reopen')) await store.loadEditRequestHistory(historyFilters); resetDrafts() }
async function changeMode(mode) { store.setFilters({ mode }); await store.load(); resetDrafts() }

async function saveAttendance() {
  await store.saveAttendanceRows(Object.values(attendanceDraft).map((row) => ({ ...row, minutes_late: row.minutes_late || null })))
}

async function saveGrades() {
  await store.saveGradeRows(Object.values(gradeDraft).map((row) => ({ ...row, value: row.value || null })))
}

async function markAllPresent() {
  store.lessonStudents.forEach((row) => { attendanceDraft[row.student_id] = { ...attendanceDraft[row.student_id], status: 'present', minutes_late: '', comment: attendanceDraft[row.student_id]?.comment || '' } })
  await saveAttendance()
}

async function markSelectedAbsent() {
  selectedStudentIds.value.forEach((id) => { attendanceDraft[id] = { ...attendanceDraft[id], student_id: id, status: 'absent', minutes_late: '', comment: attendanceDraft[id]?.comment || '' } })
  await saveAttendance()
}

async function completeLesson() {
  await store.completeLesson()
  $q.notify({ type: 'positive', message: 'Занятие завершено.', position: 'top-right' })
}

async function signLesson() {
  await store.signLesson()
  $q.notify({ type: 'positive', message: 'Журнал подписан. Для изменений создайте запрос редактирования.', position: 'top-right' })
}

async function requestEdit(reason) {
  await store.requestEdit(reason)
  $q.notify({ type: 'positive', message: 'Запрос редактирования отправлен администратору.', position: 'top-right' })
}

async function reviewEditRequest(id, approved) {
  await store.reviewEditRequest(id, approved)
  await store.loadEditRequestHistory(historyFilters)
  $q.notify({ type: 'positive', message: approved ? 'Редактирование разрешено.' : 'Запрос отклонен.', position: 'top-right' })
}

async function openPendingEditRequest(request) {
  await store.openJournalLesson(request.journal_lesson_id)
}

async function loadEditRequestHistory() { await store.loadEditRequestHistory(historyFilters) }

async function loadSuggestion() { await store.loadAttendanceSuggestion() }
async function applySuggestion() { await store.applyAttendanceSuggestion(); resetDrafts() }

onMounted(async () => {
  const queryFilters = {}
  if (route.query.mode) queryFilters.mode = String(route.query.mode)
  if (route.query.teacher) queryFilters.teacher_id = String(route.query.teacher)
  if (route.query.group) queryFilters.group_id = String(route.query.group)
  if (Object.keys(queryFilters).length) store.setFilters(queryFilters)
  await store.load()
  if (route.query.lesson) await store.openFromSchedule(route.query.lesson)
  if (route.query.legacyLesson) await store.openFromLegacySchedule(route.query.legacyLesson)
  if (hasPermission('journal.reopen')) await store.loadPendingEditRequests()
  if (hasPermission('journal.reopen')) await loadEditRequestHistory()
  if (route.query.journalLesson) await store.openJournalLesson(route.query.journalLesson)
  resetDrafts()
})
</script>

<template>
  <AppPage>
    <PageHeader title="Журнал" subtitle="Рабочее место преподавателя: занятия, посещаемость, оценки, материалы и подпись журнала." />

    <div class="journal-mode-tabs">
      <q-btn-toggle
        :model-value="store.filters.mode"
        unelevated
        toggle-color="primary"
        :options="visibleModes"
        @update:model-value="changeMode"
      />
    </div>

    <JournalFilters
      :model-value="store.filters"
      :academic-year-options="store.academicYearOptions"
      :group-options="store.groupOptions"
      :subject-options="store.subjectOptions"
      :teacher-options="store.teacherOptions"
      :loading="store.loading"
      @apply="applyFilters"
      @reset="resetFilters"
      @update:model-value="store.setFilters"
    />

    <AppToolbar>
      <span>{{ tableSubtitle }}</span>
      <template #actions>
        <AppLoading v-if="store.loading || store.detailsLoading" label="Загрузка журнала..." />
        <q-btn flat :disable="store.loading" @click="refresh"><RefreshCw :size="16" /><span>Обновить</span></q-btn>
      </template>
    </AppToolbar>

    <AppErrorBanner :message="store.error" />

    <section v-if="hasPermission('journal.reopen') && store.pendingEditRequests.length" class="journal-pending-requests">
      <div><strong>Запросы на редактирование</strong><span>{{ store.pendingEditRequests.length }} ожидают решения</span></div>
      <button v-for="request in store.pendingEditRequests" :key="request.id" type="button" @click="openPendingEditRequest(request)">
        <strong>{{ request.lesson.subject || 'Занятие' }} · {{ request.lesson.group || 'Группа' }}</strong>
        <span>{{ request.requested_by_name || request.lesson.teacher }}: {{ request.reason }}</span>
      </button>
    </section>

    <section v-if="hasPermission('journal.reopen')" class="journal-edit-history" aria-labelledby="journal-edit-history-title">
      <div class="journal-edit-history__header">
        <div><strong id="journal-edit-history-title">История запросов на редактирование</strong><span>Запросы, решения и фактические изменения в журнале</span></div>
        <div class="journal-edit-history__filters">
          <q-select v-model="historyFilters.status" dense outlined clearable emit-value map-options label="Статус" :options="[{ label: 'Ожидают решения', value: 'pending' }, { label: 'Одобрены', value: 'approved' }, { label: 'Отклонены', value: 'rejected' }]" @update:model-value="loadEditRequestHistory" />
          <q-btn outline color="primary" @click="loadEditRequestHistory">Показать</q-btn>
        </div>
      </div>
      <AppEmptyState v-if="!store.editRequestHistory.length" title="Запросов пока нет" description="История появится после создания запроса на изменение подписанного журнала." />
      <div v-else class="journal-edit-history__list">
        <article v-for="request in store.editRequestHistory" :key="request.id" class="journal-edit-history__item">
          <div class="journal-edit-history__request"><div><strong>{{ request.lesson.subject || 'Занятие' }} · {{ request.lesson.group || 'Группа' }}</strong><span>{{ request.lesson.lesson_date || 'Дата не указана' }} · {{ request.lesson.teacher || 'Преподаватель не указан' }}</span></div><AppStatusBadge :label="requestStatusLabel(request.status)" :tone="request.status === 'approved' ? 'success' : request.status === 'rejected' ? 'danger' : 'warning'" /></div>
          <dl><div><dt>Заявитель</dt><dd>{{ request.requested_by_name || 'Не указан' }}</dd></div><div><dt>Причина</dt><dd>{{ request.reason }}</dd></div><div><dt>Создан</dt><dd>{{ formatDateTime(request.created_at) }}</dd></div><div><dt>Рассмотрел</dt><dd>{{ request.reviewed_by_name || '—' }}</dd></div><div><dt>Комментарий</dt><dd>{{ request.review_comment || '—' }}</dd></div><div><dt>Рассмотрен</dt><dd>{{ formatDateTime(request.reviewed_at) }}</dd></div></dl>
          <div class="journal-edit-history__changes"><strong>Изменения после запроса</strong><p v-if="!request.changes.length">Изменений в аудите пока нет.</p><div v-for="change in request.changes" :key="change.id" class="journal-edit-history__change"><span><strong>{{ auditActionLabel(change.action) }}</strong><template v-if="change.student_name"> · {{ change.student_name }}</template></span><small>{{ formatDateTime(change.created_at) }} · {{ change.user_name || 'Система' }}</small><p>{{ changeSummary(change) }}</p></div></div>
        </article>
      </div>
    </section>

    <div class="journal-layout">
      <div class="journal-main">
        <div v-if="store.journalLessons.length" class="journal-lessons-strip">
          <button
            v-for="lesson in store.journalLessons"
            :key="lesson.id"
            type="button"
            class="journal-lesson-tile"
            :class="{ 'journal-lesson-tile--selected': Number(lesson.id) === Number(store.selectedLesson?.id) }"
            @click="selectLesson(lesson)"
          >
            <span>{{ shortLessonTitle(lesson) }}</span>
            <strong>{{ lesson.subject?.name || 'Дисциплина' }}</strong>
            <small>{{ lesson.group?.name || 'Группа' }}</small>
            <AppStatusBadge :label="statusLabel(lesson.status)" :tone="statusTone(lesson.status)" />
          </button>
        </div>

        <div v-if="store.selectedLesson && store.lessonStudents.length" class="journal-student-card">
          <div class="journal-student-toolbar">
            <div>
              <strong>Студенты занятия</strong>
              <span>{{ store.lessonStudents.length }} записей</span>
            </div>
            <div class="journal-student-actions">
              <q-btn outline color="positive" :disable="!canAttendance" @click="markAllPresent"><UserCheck :size="16" />Все присутствуют</q-btn>
              <q-btn outline color="negative" :disable="!canAttendance || !selectedStudentIds.length" @click="markSelectedAbsent"><UserX :size="16" />Выбранные отсутствуют</q-btn>
              <q-btn outline color="primary" :disable="!canAttendance" @click="loadSuggestion"><Wand2 :size="16" />Предложить по проходной</q-btn>
              <q-btn color="primary" :disable="!canAttendance" @click="saveAttendance"><Save :size="16" />Посещаемость</q-btn>
              <q-btn color="secondary" :disable="!canGrades" @click="saveGrades"><Save :size="16" />Оценки</q-btn>
            </div>
          </div>

          <div v-if="store.attendanceSuggestion.length" class="journal-suggestion">
            <div>
              <strong>Предварительный расчет по проходной</strong>
              <span>Ничего не изменится до подтверждения.</span>
            </div>
            <div class="journal-suggestion__rows">
              <div v-for="suggestion in store.attendanceSuggestion" :key="suggestion.student_id" class="journal-suggestion__row">
                <strong>{{ suggestion.student_name }}</strong>
                <span>{{ suggestionLabel(suggestion.suggestion) }}<template v-if="suggestion.minutes_late">: {{ suggestion.minutes_late }} мин.</template></span>
              </div>
            </div>
            <q-btn color="positive" :disable="!canAttendance" @click="applySuggestion">Применить предложения</q-btn>
          </div>

          <div class="journal-student-scroll">
            <table class="journal-student-table">
              <thead>
                <tr>
                  <th><q-checkbox v-model="selectedStudentIds" :val="'all'" class="hidden-checkbox" /></th>
                  <th>№</th>
                  <th>Фото</th>
                  <th>ФИО</th>
                  <th>Посещаемость</th>
                  <th>Причина/опоздание</th>
                  <th>Оценка</th>
                  <th>Комментарий</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in store.lessonStudents" :key="row.student_id">
                  <td><q-checkbox v-model="selectedStudentIds" :val="row.student_id" :disable="isReadOnly" /></td>
                  <td>{{ row.number }}</td>
                  <td><div class="journal-avatar">{{ (row.student?.last_name || '?').slice(0, 1) }}</div></td>
                  <td><button type="button" class="journal-student-name" @click="selectStudent(row.student)">{{ store.fullName(row.student) }}</button></td>
                  <td>
                    <q-select v-model="attendanceDraft[row.student_id].status" dense outlined emit-value map-options :readonly="!canAttendance" :options="[
                      { label: 'Присутствует', value: 'present' }, { label: 'Отсутствует', value: 'absent' }, { label: 'Опоздал', value: 'late' }, { label: 'Уважительно', value: 'excused' }, { label: 'Болеет', value: 'sick' }, { label: 'Дистанционно', value: 'remote' }
                    ]" />
                  </td>
                  <td>
                    <div class="journal-inline-fields">
                      <q-input v-model="attendanceDraft[row.student_id].minutes_late" dense outlined type="number" min="0" placeholder="мин." :readonly="!canAttendance" />
                      <q-input v-model="attendanceDraft[row.student_id].comment" dense outlined placeholder="причина" :readonly="!canAttendance" />
                    </div>
                  </td>
                  <td>
                    <q-select v-model="gradeDraft[row.student_id].value" dense outlined emit-value map-options :readonly="!canGrades" :options="[
                      { label: '—', value: '' }, { label: '5', value: '5' }, { label: '4', value: '4' }, { label: '3', value: '3' }, { label: '2', value: '2' }, { label: 'Зачет', value: 'зачет' }, { label: 'Незачет', value: 'незачет' }, { label: 'Освобожден', value: 'освобожден' }, { label: 'Не аттестован', value: 'не аттестован' }
                    ]" />
                  </td>
                  <td><q-input v-model="gradeDraft[row.student_id].comment" dense outlined placeholder="комментарий" :readonly="!canGrades" /></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <AppEmptyState v-else title="Данные журнала не найдены" description="Выберите режим, группу, дату или дисциплину. Журнал занятия создается из расписания кнопкой «Открыть журнал»." />
      </div>

      <aside class="journal-side">
        <JournalLessonPanel
          :lesson="store.selectedLesson"
          :student="selectedStudent"
          :files="store.selectedFiles"
          :read-only="isReadOnly"
          :can-edit="canEdit"
          :can-files="hasPermission('journal.files') && !isReadOnly"
          :can-reopen="hasPermission('journal.reopen') && store.selectedLesson?.status === 'signed'"
          :can-request-edit="canRequestEdit"
          :can-review-edit-requests="hasPermission('journal.reopen')"
          @save="store.saveLesson"
          @complete="completeLesson"
          @sign="signDialogVisible = true"
          @reopen="store.reopenLesson"
          @request-edit="requestEdit"
          @review-edit-request="reviewEditRequest"
          @mark-all-present="markAllPresent"
          @upload-file="store.uploadLessonFile"
          @delete-file="store.deleteLessonFile"
        />
      </aside>
    </div>
    <AppConfirmDialog v-model="signDialogVisible" title="Подписать журнал?" message="После подписи редактирование будет заблокировано. Для исправления потребуется запрос редактирования и одобрение администратора." confirm-label="Подписать" tone="positive" @confirm="signLesson" />
  </AppPage>
</template>

<style scoped>
.journal-mode-tabs { margin-bottom: 12px; overflow-x: auto; }
.journal-pending-requests { display: grid; gap: 8px; margin-bottom: 12px; padding: 12px; border: 1px solid #f59e0b; border-radius: 8px; background: #fffbeb; }
.journal-pending-requests > div { display: flex; justify-content: space-between; gap: 8px; }
.journal-pending-requests > div span { color: #92400e; font-size: 13px; }
.journal-pending-requests button { display: grid; gap: 3px; border: 1px solid #fde68a; border-radius: 6px; padding: 8px; background: #fff; text-align: left; cursor: pointer; }
.journal-pending-requests button span { color: #64748b; font-size: 13px; }
.journal-edit-history { display: grid; gap: 12px; margin-bottom: 12px; padding: 12px; border: 1px solid var(--cp-border, #d9dee8); border-radius: 8px; background: #fff; }
.journal-edit-history__header, .journal-edit-history__request { display: flex; justify-content: space-between; gap: 12px; align-items: start; }
.journal-edit-history__header span, .journal-edit-history__request span { display: block; margin-top: 3px; color: #64748b; font-size: 13px; }
.journal-edit-history__filters { display: flex; gap: 8px; align-items: center; }
.journal-edit-history__filters :deep(.q-field) { min-width: 180px; }
.journal-edit-history__list { display: grid; gap: 10px; }
.journal-edit-history__item { display: grid; gap: 10px; padding: 12px; border: 1px solid #e2e8f0; border-radius: 8px; }
.journal-edit-history dl { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; margin: 0; }
.journal-edit-history dt { color: #64748b; font-size: 12px; }.journal-edit-history dd { margin: 2px 0 0; overflow-wrap: anywhere; }
.journal-edit-history__changes { display: grid; gap: 6px; }.journal-edit-history__changes > p { margin: 0; color: #64748b; font-size: 13px; }
.journal-edit-history__change { padding: 8px; border-radius: 6px; background: #f8fafc; }.journal-edit-history__change span { display: block; }.journal-edit-history__change small { color: #64748b; }.journal-edit-history__change p { margin: 4px 0 0; font-size: 13px; overflow-wrap: anywhere; }
.journal-layout { display: grid; grid-template-columns: minmax(0, 1fr) 360px; gap: 16px; align-items: start; }
.journal-main { min-width: 0; display: grid; gap: 12px; }
.journal-side { position: sticky; top: 76px; min-width: 0; }
.journal-lessons-strip { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 10px; }
.journal-lesson-tile { text-align: left; border: 1px solid var(--cp-border, #d9dee8); background: #fff; border-radius: 8px; padding: 10px; display: grid; gap: 4px; cursor: pointer; transition: border-color .15s ease, transform .15s ease; }
.journal-lesson-tile:hover { transform: translateY(-1px); border-color: #2563eb; }
.journal-lesson-tile--selected { border-color: #2563eb; box-shadow: 0 0 0 1px #2563eb; }
.journal-student-card { background: #fff; border: 1px solid var(--cp-border, #d9dee8); border-radius: 8px; padding: 12px; display: grid; gap: 12px; }
.journal-student-toolbar, .journal-suggestion { display: flex; justify-content: space-between; gap: 12px; align-items: center; }
.journal-student-toolbar strong, .journal-suggestion strong { display: block; }
.journal-student-toolbar span, .journal-suggestion span { color: #64748b; font-size: 13px; }
.journal-student-actions { display: flex; flex-wrap: wrap; gap: 8px; justify-content: flex-end; }
.journal-suggestion { background: #f8fafc; border: 1px dashed #94a3b8; border-radius: 8px; padding: 10px; }
.journal-suggestion { align-items: start; flex-wrap: wrap; }
.journal-suggestion__rows { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 6px 12px; width: 100%; }
.journal-suggestion__row { display: flex; justify-content: space-between; gap: 8px; font-size: 13px; }
.journal-suggestion__row span { text-align: right; }
.journal-student-scroll { overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 8px; }
.journal-student-table { width: 100%; border-collapse: collapse; min-width: 980px; }
.journal-student-table th, .journal-student-table td { padding: 8px; border-bottom: 1px solid #e2e8f0; vertical-align: middle; }
.journal-student-table th { background: #f8fafc; text-align: left; font-size: 12px; color: #475569; position: sticky; top: 0; z-index: 1; }
.journal-avatar { width: 34px; height: 34px; border-radius: 50%; background: #e2e8f0; display: grid; place-items: center; font-weight: 700; }
.journal-student-name { border: 0; background: transparent; padding: 0; font-weight: 600; cursor: pointer; text-align: left; }
.journal-inline-fields { display: grid; grid-template-columns: 72px minmax(140px, 1fr); gap: 6px; }
.hidden-checkbox { visibility: hidden; width: 0; }
@media (max-width: 1200px) { .journal-layout { grid-template-columns: 1fr; } .journal-side { position: static; } }
@media (max-width: 700px) { .journal-edit-history__header, .journal-edit-history__request { display: grid; }.journal-edit-history__filters { flex-wrap: wrap; }.journal-edit-history__filters :deep(.q-field) { min-width: 0; flex: 1 1 180px; }.journal-edit-history dl { grid-template-columns: 1fr; } }
</style>
