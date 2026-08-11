<script setup>
import { computed, onMounted, watch } from 'vue'
import { Download, RefreshCw, RotateCcw } from '@lucide/vue'
import { useRoute, useRouter } from 'vue-router'
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
import WorkspacePanel from '../../components/workspace/WorkspacePanel.vue'
import WorkspaceSplitter from '../../components/workspace/WorkspaceSplitter.vue'
import { useResizableWorkspace } from '../../composables/useResizableWorkspace'
import { ATTENDANCE_REPORT_MODE_OPTIONS, ATTENDANCE_STATUS_OPTIONS, useAttendanceAnalysisStore } from '../../stores/attendanceAnalysis'

const route = useRoute()
const router = useRouter()
const store = useAttendanceAnalysisStore()
const { resetSplitter, startResize, workspaceRef, workspaceStyle } = useResizableWorkspace({ storageKey: 'collegePortal.attendance.splitter.v1', resizeBodyClass: 'attendance-splitter-resizing' })

const todayColumns = [
  { name: 'full_name', label: 'ФИО', field: 'full_name', align: 'left', sortable: true },
  { name: 'status', label: 'Статус', field: 'status_label', align: 'left', sortable: true },
  { name: 'first_entry', label: 'Первый вход', field: 'first_entry', align: 'left', sortable: true },
  { name: 'last_exit', label: 'Последний выход', field: 'last_exit', align: 'left', sortable: true },
  { name: 'first_lesson', label: 'Первая пара', field: 'first_lesson', align: 'left' },
  { name: 'late_minutes', label: 'Опоздание', field: 'late_minutes', align: 'left', sortable: true },
  { name: 'comment', label: 'Комментарий', field: 'comment', align: 'left' },
]
const historyColumns = [
  { name: 'full_name', label: 'ФИО', field: 'full_name', align: 'left', sortable: true },
  { name: 'status', label: 'Статус', field: 'status_label', align: 'left', sortable: true },
  { name: 'scheduled_days', label: 'Дней по расписанию', field: 'scheduled_days', align: 'right', sortable: true },
  { name: 'present_days', label: 'Присутствовал', field: 'present_days', align: 'right', sortable: true },
  { name: 'absent_days', label: 'Отсутствовал', field: 'absent_days', align: 'right', sortable: true },
  { name: 'late_count', label: 'Опозданий', field: 'late_count', align: 'right', sortable: true },
  { name: 'early_leave_count', label: 'Ранних уходов', field: 'early_leave_count', align: 'right', sortable: true },
  { name: 'minutes_inside', label: 'Всего внутри', field: 'minutes_inside', align: 'right', sortable: true },
  { name: 'average_minutes_per_present_day', label: 'Среднее в день', field: 'average_minutes_per_present_day', align: 'right', sortable: true },
]
const columns = computed(() => store.reportMode === 'today' ? todayColumns : historyColumns)
const pagination = { rowsPerPage: 20, sortBy: 'late_minutes', descending: true }
const modeOptions = [
  { label: 'Преподаватели', value: 'teachers' },
  { label: 'Студенты', value: 'students' },
]
const pageSubtitle = computed(() => (
  store.date === store.dateTo
    ? `Сопоставление проходной с расписанием за ${new Date(store.date).toLocaleDateString('ru-RU')}`
    : `Сопоставление проходной с расписанием за период ${new Date(store.date).toLocaleDateString('ru-RU')} — ${new Date(store.dateTo).toLocaleDateString('ru-RU')}`
))
const metrics = computed(() => {
  if (store.reportMode === 'today') {
    return [
      { label: 'Всего', value: store.summary.total },
      { label: 'Вовремя', value: store.summary.on_time, to: `/attendance?type=${store.mode}&mode=today&status=on_time` },
      { label: 'Опоздали', value: store.summary.late, to: `/attendance?type=${store.mode}&mode=today&status=late` },
      { label: 'Не пришли', value: store.summary.absent, to: `/attendance?type=${store.mode}&mode=today&status=absent` },
      { label: 'Сейчас в здании', value: store.summary.inside_now },
      // У студентов — расхождение журнала с проходной, у преподавателей —
      // сколько из них приходящие: их день мерится не с девяти до шести.
      ...(store.mode === 'students'
        ? [{ label: 'Отмечены без входа', value: store.summary.marked_present_without_entry, to: `/attendance?type=students&mode=today&marked_without_entry=1` }]
        : [{ label: 'Приходящие', value: store.summary.visiting }]),
    ]
  }

  return [
    { label: 'Всего людей', value: store.summary.total },
    { label: 'Дней присутствия', value: store.summary.present_days },
    { label: 'Отсутствий', value: store.summary.absent_days, to: `/attendance?type=${store.mode}&mode=${store.reportMode}&status=absent` },
    { label: 'Опозданий', value: store.summary.late_count, to: `/attendance?type=${store.mode}&mode=${store.reportMode}&status=late` },
    { label: 'Ранних уходов', value: store.summary.early_leave_count, to: `/attendance?type=${store.mode}&mode=${store.reportMode}&status=early_leave` },
    { label: 'Время внутри', value: store.minutesLabel(store.summary.minutes_inside) },
  ]
})
const selected = computed(() => store.selectedRow)
const selectedSubtitle = computed(() => selected.value ? [selected.value.entity_type === 'teacher' ? 'Преподаватель' : 'Студент', selected.value.group || selected.value.department].filter(Boolean) : [])
const selectedActions = computed(() => {
  if (!selected.value) return []
  const personRoute = selected.value.entity_type === 'teacher' ? `/teachers?selected=${selected.value.entity_id}` : `/students?selected=${selected.value.entity_id}`
  const scheduleRoute = selected.value.entity_type === 'teacher' ? `/schedule?teacher=${selected.value.entity_id}` : `/schedule?group=${selected.value.group_id || ''}`
  const historyRoute = `/access/reports?entity_type=${selected.value.entity_type}&search=${encodeURIComponent(selected.value.full_name)}`
  return [
    { label: 'Открыть человека', to: personRoute },
    { label: 'Расписание', to: scheduleRoute },
    { label: 'История проходов', to: historyRoute },
  ]
})
const selectedMetrics = computed(() => {
  if (!selected.value) return []
  if (store.reportMode === 'today') {
    return [
      { label: 'Опоздание', value: lateLabel(selected.value) },
      { label: 'Внутри', value: store.minutesLabel(selected.value.minutes_inside) },
      { label: 'Первая пара', value: selected.value.first_lesson?.starts_at || '—' },
    ]
  }
  return [
    { label: 'Присутствовал', value: selected.value.present_days ?? 0 },
    { label: 'Отсутствовал', value: selected.value.absent_days ?? 0 },
    { label: 'Всего внутри', value: store.minutesLabel(selected.value.minutes_inside) },
    { label: 'Среднее', value: store.minutesLabel(selected.value.average_minutes_per_present_day) },
  ]
})
const personDayRows = computed(() => store.personDays || [])
const passEvents = computed(() => personDayRows.value.flatMap((day) => (day.events || []).map((event) => ({ ...event, date: day.date }))))
const scheduleRows = computed(() => personDayRows.value.flatMap((day) => (day.lessons || []).map((lesson) => ({ ...lesson, date: day.date }))))

function lateLabel(row) {
  if (row.late_minutes === null || row.late_minutes === undefined) return '—'
  return Number(row.late_minutes) > 0 ? `${row.late_minutes} мин.` : 'Нет'
}

function rowClass(row) {
  return row?.id === store.selectedId ? 'attendance-analysis-row--selected' : ''
}

function detailMinutes(value) {
  return Number(value || 0) > 0 ? store.minutesLabel(value) : '—'
}

function dayTimelineStyle(day) {
  const planned = day.planned_start && day.planned_end ? [new Date(day.planned_start), new Date(day.planned_end)] : null
  const actual = day.first_entry && day.last_exit ? [new Date(day.first_entry), new Date(day.last_exit)] : null
  if (!planned || !actual) return { '--planned-left': '20%', '--planned-width': '60%', '--actual-left': '20%', '--actual-width': '0%' }
  const start = Math.min(planned[0].getTime(), actual[0].getTime())
  const end = Math.max(planned[1].getTime(), actual[1].getTime())
  const span = Math.max(1, end - start)
  return {
    '--planned-left': `${((planned[0].getTime() - start) / span) * 100}%`,
    '--planned-width': `${((planned[1].getTime() - planned[0].getTime()) / span) * 100}%`,
    '--actual-left': `${((actual[0].getTime() - start) / span) * 100}%`,
    '--actual-width': `${((actual[1].getTime() - actual[0].getTime()) / span) * 100}%`,
  }
}

async function applyFilters() {
  await router.replace({ path: '/attendance', query: store.toQuery() })
  await store.load()
}

async function resetFilters() {
  store.resetFilters()
  await router.replace({ path: '/attendance', query: { type: store.mode, mode: store.reportMode } })
  await store.load()
}

watch(() => route.query, async (query) => {
  store.applyQuery(query)
  await store.load()
}, { deep: true })

onMounted(async () => {
  store.applyQuery(route.query)
  // Справочники фильтров не должны решать судьбу отчёта: раньше отказ по ним
  // прерывал onMounted до store.load(), и экран оставался пустым без сообщения.
  await store.loadOptions().catch(() => {})
  await store.load()
})
</script>

<template>
  <AppPage>
    <PageHeader title="Посещаемость" :subtitle="pageSubtitle" />

    <AppToolbar>
      <q-btn-toggle
        :model-value="store.reportMode"
        unelevated
        toggle-color="primary"
        color="grey-2"
        text-color="dark"
        :options="ATTENDANCE_REPORT_MODE_OPTIONS"
        @update:model-value="value => { store.setReportMode(value); applyFilters() }"
      />
      <q-separator vertical inset />
      <q-btn-toggle
        :model-value="store.mode"
        unelevated
        toggle-color="primary"
        color="grey-2"
        text-color="dark"
        :options="modeOptions"
        @update:model-value="value => { store.setMode(value); applyFilters() }"
      />
      <template #actions>
        <AppLoading v-if="store.loading" label="Анализ посещаемости..." />
        <q-btn flat @click="resetSplitter">Сбросить размер</q-btn>
        <q-btn v-if="store.reportMode !== 'today'" flat :loading="store.exporting" :disable="store.loading" @click="store.exportHistoryCsv">
          <Download :size="16" class="q-mr-xs" /> Экспорт CSV
        </q-btn>
        <q-btn flat :disable="store.loading" @click="store.load">
          <RefreshCw :size="16" class="q-mr-xs" /> Обновить
        </q-btn>
      </template>
    </AppToolbar>

    <AppErrorBanner :message="store.error" />

    <AppFilterBar>
      <q-select v-model="store.filters.status" dense outlined emit-value map-options label="Статус" :options="ATTENDANCE_STATUS_OPTIONS" />
      <q-select v-if="store.mode === 'students'" v-model="store.filters.group_id" dense outlined emit-value map-options label="Группа" :options="store.groupOptions" :loading="store.loadingOptions" />
      <q-select v-if="store.mode === 'teachers'" v-model="store.filters.teacher_id" dense outlined emit-value map-options label="Преподаватель" :options="store.teacherOptions" :loading="store.loadingOptions" />
      <q-input v-model="store.filters.date_from" dense outlined type="date" label="Дата с" />
      <q-input v-model="store.filters.date_to" dense outlined type="date" label="Дата по" />
      <template #actions>
        <q-btn color="primary" :disable="store.loading" @click="applyFilters">Применить</q-btn>
        <q-btn flat :disable="store.loading" @click="resetFilters"><RotateCcw :size="16" class="q-mr-xs" /> Сбросить</q-btn>
      </template>
    </AppFilterBar>

    <section class="attendance-analysis-summary">
      <AppCard v-for="metric in metrics" :key="metric.label" class="attendance-analysis-metric">
        <span>{{ metric.label }}</span>
        <q-btn v-if="metric.to" flat dense no-caps :to="metric.to" class="attendance-analysis-metric__link">{{ metric.value }}</q-btn>
        <strong v-else>{{ metric.value }}</strong>
      </AppCard>
    </section>

    <div ref="workspaceRef" class="attendance-analysis-workspace" :style="workspaceStyle">
      <div class="attendance-analysis-main">
        <AppTable
          v-if="store.rows.length || store.loading"
          :rows="store.rows"
          :columns="columns"
          :loading="store.loading"
          :pagination="pagination"
          :table-row-class-fn="rowClass"
          row-key="id"
          @row-click="(_, row) => store.select(row)"
        >
          <template #body-cell-full_name="props">
            <q-td :props="props" @click="store.select(props.row)">
              <strong class="attendance-analysis-link">{{ props.row.full_name }}</strong>
              <div class="attendance-analysis-secondary">
                {{ props.row.entity_type === 'teacher' ? (props.row.department || 'Отделение не указано') : (props.row.group || 'Группа не указана') }}
              </div>
            </q-td>
          </template>
          <template #body-cell-status="props">
            <q-td :props="props">
              <AppStatusBadge :label="props.row.status_label" :tone="props.row.status_tone" />
              <q-chip v-if="props.row.inside_now" dense color="green-1" text-color="positive" class="q-ml-xs">в здании</q-chip>
              <!-- Преподаватель отметил на занятии, а входа нет: об этом куратор
                   и директор должны узнать здесь, а не в конце семестра. -->
              <q-chip v-if="props.row.marked_present_without_entry" dense color="orange-1" text-color="warning" class="q-ml-xs">отмечен без входа</q-chip>
              <q-chip v-if="props.row.is_visiting" dense color="blue-1" text-color="info" class="q-ml-xs">приходящий</q-chip>
            </q-td>
          </template>
          <template #body-cell-first_entry="props"><q-td :props="props">{{ store.formatAttendanceDateTime(props.row.first_entry) }}</q-td></template>
          <template #body-cell-last_exit="props"><q-td :props="props">{{ store.formatAttendanceDateTime(props.row.last_exit) }}</q-td></template>
          <template #body-cell-first_lesson="props"><q-td :props="props">{{ store.firstLessonLabel(props.row.first_lesson) }}</q-td></template>
          <template #body-cell-late_minutes="props"><q-td :props="props">{{ lateLabel(props.row) }}</q-td></template>
          <template #body-cell-comment="props"><q-td :props="props">{{ props.row.comment }}</q-td></template>
          <template #body-cell-minutes_inside="props"><q-td :props="props">{{ store.minutesLabel(props.row.minutes_inside) }}</q-td></template>
          <template #body-cell-average_minutes_per_present_day="props"><q-td :props="props">{{ store.minutesLabel(props.row.average_minutes_per_present_day) }}</q-td></template>
        </AppTable>
        <AppEmptyState v-else title="Нет данных для анализа" description="Добавьте расписание и события проходной, чтобы увидеть сопоставление посещаемости." />
      </div>

      <WorkspaceSplitter label="Изменить ширину карточки посещаемости" @resize-start="startResize" @reset="resetSplitter" />

      <aside class="attendance-analysis-side">
        <WorkspacePanel
          v-if="selected"
          :title="selected.full_name"
          :subtitle="selectedSubtitle"
          :metrics="selectedMetrics"
          :actions="selectedActions"
        >
          <template #photo>
            <q-avatar size="72px" color="grey-2" text-color="grey-8">
              <img v-if="selected.photo_url" :src="selected.photo_url" alt="" />
              <span v-else>{{ selected.full_name.slice(0, 1) }}</span>
            </q-avatar>
          </template>
          <template #status>
            <AppStatusBadge :label="selected.status_label" :tone="selected.status_tone" />
          </template>

          <q-tabs v-if="store.reportMode !== 'today'" v-model="store.selectedTab" dense align="justify" class="attendance-analysis-tabs">
            <q-tab name="summary" label="Сводка" />
            <q-tab name="days" label="По дням" />
            <q-tab name="events" label="Проходы" />
            <q-tab name="schedule" label="Расписание" />
          </q-tabs>

          <section v-if="store.reportMode === 'today' || store.selectedTab === 'summary'" class="attendance-analysis-details">
            <h3>Детализация</h3>
            <dl>
              <div><dt>Роль</dt><dd>{{ selected.entity_type === 'teacher' ? 'Преподаватель' : 'Студент' }}</dd></div>
              <div v-if="store.reportMode === 'today'"><dt>Первая пара</dt><dd>{{ store.firstLessonLabel(selected.first_lesson) }}</dd></div>
              <div v-if="store.reportMode === 'today'"><dt>Фактический первый вход</dt><dd>{{ store.formatAttendanceDateTime(selected.first_entry) }}</dd></div>
              <div v-if="store.reportMode === 'today'"><dt>Величина опоздания</dt><dd>{{ lateLabel(selected) }}</dd></div>
              <div><dt>Последний выход</dt><dd>{{ store.formatAttendanceDateTime(selected.last_exit) }}</dd></div>
              <div><dt>Время внутри</dt><dd>{{ store.minutesLabel(selected.minutes_inside) }}</dd></div>
              <div v-if="store.reportMode !== 'today'"><dt>Дней по расписанию</dt><dd>{{ selected.scheduled_days }}</dd></div>
              <div v-if="store.reportMode !== 'today'"><dt>Опозданий</dt><dd>{{ selected.late_count }} · {{ detailMinutes(selected.late_minutes_total) }}</dd></div>
              <div v-if="store.reportMode !== 'today'"><dt>Ранних уходов</dt><dd>{{ selected.early_leave_count }} · {{ detailMinutes(selected.early_leave_minutes_total) }}</dd></div>
              <div v-if="store.reportMode === 'today'"><dt>Комментарий</dt><dd>{{ selected.comment }}</dd></div>
            </dl>
          </section>

          <section v-if="store.reportMode !== 'today' && store.selectedTab === 'days'" class="attendance-analysis-details">
            <h3>По дням</h3>
            <div v-for="day in personDayRows" :key="day.date" class="attendance-day-card">
              <div class="attendance-day-card__head">
                <strong>{{ store.formatAttendanceDate(day.date) }}</strong>
                <AppStatusBadge :label="day.status_label" :tone="day.status_tone" />
              </div>
              <div class="attendance-timeline" :style="dayTimelineStyle(day)">
                <span class="attendance-timeline__planned" />
                <span class="attendance-timeline__actual" />
              </div>
              <dl>
                <div><dt>План</dt><dd>{{ store.formatAttendanceDateTime(day.planned_start) }} — {{ store.formatAttendanceDateTime(day.planned_end) }}</dd></div>
                <div><dt>Факт</dt><dd>{{ store.formatAttendanceDateTime(day.first_entry) }} — {{ store.formatAttendanceDateTime(day.last_exit) }}</dd></div>
                <div><dt>Внутри</dt><dd>{{ store.minutesLabel(day.minutes_inside) }}</dd></div>
                <div><dt>Отклонение</dt><dd>Опоздание: {{ detailMinutes(day.late_minutes) }}, ранний уход: {{ detailMinutes(day.early_leave_minutes) }}</dd></div>
              </dl>
            </div>
          </section>

          <section v-if="store.reportMode !== 'today' && store.selectedTab === 'events'" class="attendance-analysis-details">
            <h3>Проходы</h3>
            <div v-if="passEvents.length" class="attendance-list">
              <div v-for="event in passEvents" :key="event.id" class="attendance-list__row">
                <strong>{{ event.direction === 'in' ? 'Вход' : 'Выход' }}</strong>
                <span>{{ store.formatAttendanceDateTime(event.event_time) }}</span>
              </div>
            </div>
            <AppEmptyState v-else title="Проходов нет" description="За выбранный период события проходной не найдены." />
          </section>

          <section v-if="store.reportMode !== 'today' && store.selectedTab === 'schedule'" class="attendance-analysis-details">
            <h3>Расписание</h3>
            <div v-if="scheduleRows.length" class="attendance-list">
              <div v-for="lesson in scheduleRows" :key="`${lesson.id}-${lesson.date}`" class="attendance-list__row">
                <strong>{{ lesson.starts_at }} — {{ lesson.ends_at }}</strong>
                <span>{{ lesson.subject || 'Дисциплина не указана' }} · {{ lesson.group || lesson.classroom || '—' }}</span>
              </div>
            </div>
            <AppEmptyState v-else title="Расписания нет" description="За выбранный период занятий не найдено." />
          </section>
        </WorkspacePanel>
        <AppEmptyState v-else title="Запись не выбрана" description="Выберите строку в таблице для просмотра деталей." />
      </aside>
    </div>
  </AppPage>
</template>

<style scoped>
.attendance-analysis-summary {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 12px;
  margin-bottom: 16px;
}

.attendance-analysis-metric {
  min-height: 78px;
}

.attendance-analysis-metric :deep(.app-card__body) {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.attendance-analysis-metric span {
  color: #64748b;
  font-size: 13px;
}

.attendance-analysis-metric strong,
.attendance-analysis-metric__link {
  align-self: flex-start;
  color: #0f172a;
  font-size: 24px;
  font-weight: 700;
}

.attendance-analysis-workspace {
  display: grid;
  gap: 0;
  align-items: start;
}

.attendance-analysis-main {
  min-width: 0;
  padding-right: 10px;
}

.attendance-analysis-side {
  min-width: 0;
  padding-left: 10px;
}

.attendance-analysis-link {
  color: #0f172a;
  cursor: pointer;
}

.attendance-analysis-secondary {
  color: #64748b;
  font-size: 12px;
  margin-top: 2px;
}

.attendance-analysis-row--selected td {
  background: #eef6ff !important;
}

.attendance-analysis-tabs {
  margin: 8px 0 12px;
}

.attendance-analysis-details h3 {
  color: #0f172a;
  font-size: 15px;
  font-weight: 700;
  margin: 0 0 12px;
}

.attendance-analysis-details dl,
.attendance-day-card dl {
  display: grid;
  gap: 10px;
  margin: 0;
}

.attendance-analysis-details dl > div,
.attendance-day-card dl > div {
  display: grid;
  gap: 2px;
}

.attendance-analysis-details dt,
.attendance-day-card dt {
  color: #64748b;
  font-size: 12px;
}

.attendance-analysis-details dd,
.attendance-day-card dd {
  color: #0f172a;
  font-size: 13px;
  margin: 0;
  overflow-wrap: anywhere;
}

.attendance-day-card {
  border-bottom: 1px solid #e2e8f0;
  padding: 12px 0;
}

.attendance-day-card:first-of-type {
  padding-top: 0;
}

.attendance-day-card__head,
.attendance-list__row {
  align-items: center;
  display: flex;
  justify-content: space-between;
  gap: 10px;
}

.attendance-timeline {
  background: #e2e8f0;
  border-radius: 999px;
  height: 10px;
  margin: 10px 0;
  position: relative;
  overflow: hidden;
}

.attendance-timeline__planned,
.attendance-timeline__actual {
  border-radius: 999px;
  height: 100%;
  position: absolute;
  top: 0;
}

.attendance-timeline__planned {
  background: #bfdbfe;
  left: var(--planned-left);
  width: var(--planned-width);
}

.attendance-timeline__actual {
  background: #22c55e;
  left: var(--actual-left);
  width: var(--actual-width);
}

.attendance-list {
  display: grid;
  gap: 10px;
}

.attendance-list__row {
  border-bottom: 1px solid #e2e8f0;
  padding-bottom: 8px;
}

.attendance-list__row span {
  color: #64748b;
  font-size: 12px;
  text-align: right;
}

@media (max-width: 1100px) {
  .attendance-analysis-workspace {
    grid-template-columns: 1fr !important;
    gap: 16px;
  }
  .attendance-analysis-main, .attendance-analysis-side { padding: 0; }
}
</style>
