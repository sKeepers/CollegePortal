<script setup>
import { computed, onMounted, watch } from 'vue'
import { RefreshCw, RotateCcw } from '@lucide/vue'
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
import { ATTENDANCE_STATUS_OPTIONS, useAttendanceAnalysisStore } from '../../stores/attendanceAnalysis'

const route = useRoute()
const router = useRouter()
const store = useAttendanceAnalysisStore()
const columns = [
  { name: 'full_name', label: 'ФИО', field: 'full_name', align: 'left', sortable: true },
  { name: 'status', label: 'Статус', field: 'status_label', align: 'left', sortable: true },
  { name: 'first_entry', label: 'Первый вход', field: 'first_entry', align: 'left', sortable: true },
  { name: 'last_exit', label: 'Последний выход', field: 'last_exit', align: 'left', sortable: true },
  { name: 'first_lesson', label: 'Первая пара', field: 'first_lesson', align: 'left' },
  { name: 'late_minutes', label: 'Опоздание', field: 'late_minutes', align: 'left', sortable: true },
  { name: 'comment', label: 'Комментарий', field: 'comment', align: 'left' },
]
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
const metrics = computed(() => [
  { label: 'Всего', value: store.summary.total },
  { label: 'Вовремя', value: store.summary.on_time, to: `/attendance?type=${store.mode}&status=on_time` },
  { label: 'Опоздали', value: store.summary.late, to: `/attendance?type=${store.mode}&status=late` },
  { label: 'Не пришли', value: store.summary.absent, to: `/attendance?type=${store.mode}&status=absent` },
  { label: 'Сейчас в здании', value: store.summary.inside_now },
])
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

function lateLabel(row) {
  if (row.late_minutes === null || row.late_minutes === undefined) return '—'
  return Number(row.late_minutes) > 0 ? `${row.late_minutes} мин.` : 'Нет'
}

function rowClass(row) {
  return row?.id === store.selectedId ? 'attendance-analysis-row--selected' : ''
}

async function applyFilters() {
  await router.replace({ path: '/attendance', query: store.toQuery() })
  await store.load()
}

async function resetFilters() {
  store.resetFilters()
  await router.replace({ path: '/attendance', query: { type: store.mode } })
  await store.load()
}

watch(() => route.query, async (query) => {
  store.applyQuery(query)
  await store.load()
}, { deep: true })

onMounted(async () => {
  store.applyQuery(route.query)
  await store.loadOptions()
  await store.load()
})
</script>

<template>
  <AppPage>
    <PageHeader title="Посещаемость" :subtitle="pageSubtitle" />

    <AppToolbar>
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

    <div class="attendance-analysis-workspace">
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
            <q-td :props="props"><AppStatusBadge :label="props.row.status_label" :tone="props.row.status_tone" /><q-chip v-if="props.row.inside_now" dense color="green-1" text-color="positive" class="q-ml-xs">в здании</q-chip></q-td>
          </template>
          <template #body-cell-first_entry="props"><q-td :props="props">{{ store.formatAttendanceDateTime(props.row.first_entry) }}</q-td></template>
          <template #body-cell-last_exit="props"><q-td :props="props">{{ store.formatAttendanceDateTime(props.row.last_exit) }}</q-td></template>
          <template #body-cell-first_lesson="props"><q-td :props="props">{{ store.firstLessonLabel(props.row.first_lesson) }}</q-td></template>
          <template #body-cell-late_minutes="props"><q-td :props="props">{{ lateLabel(props.row) }}</q-td></template>
          <template #body-cell-comment="props"><q-td :props="props">{{ props.row.comment }}</q-td></template>
        </AppTable>
        <AppEmptyState v-else title="Нет данных для анализа" description="Добавьте расписание и события проходной, чтобы увидеть сопоставление посещаемости." />
      </div>

      <aside class="attendance-analysis-side">
        <WorkspacePanel
          v-if="selected"
          :title="selected.full_name"
          :subtitle="selectedSubtitle"
          :metrics="[
            { label: 'Опоздание', value: lateLabel(selected) },
            { label: 'Внутри', value: store.minutesLabel(selected.minutes_inside) },
            { label: 'Первая пара', value: selected.first_lesson?.starts_at || '—' },
          ]"
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
          <section class="attendance-analysis-details">
            <h3>Детализация</h3>
            <dl>
              <div><dt>Роль</dt><dd>{{ selected.entity_type === 'teacher' ? 'Преподаватель' : 'Студент' }}</dd></div>
              <div><dt>Первая пара</dt><dd>{{ store.firstLessonLabel(selected.first_lesson) }}</dd></div>
              <div><dt>Фактический первый вход</dt><dd>{{ store.formatAttendanceDateTime(selected.first_entry) }}</dd></div>
              <div><dt>Величина опоздания</dt><dd>{{ lateLabel(selected) }}</dd></div>
              <div><dt>Последний выход</dt><dd>{{ store.formatAttendanceDateTime(selected.last_exit) }}</dd></div>
              <div><dt>Время внутри</dt><dd>{{ store.minutesLabel(selected.minutes_inside) }}</dd></div>
              <div><dt>Комментарий</dt><dd>{{ selected.comment }}</dd></div>
            </dl>
          </section>
        </WorkspacePanel>
        <AppEmptyState v-else title="Запись не выбрана" description="Выберите строку в таблице для просмотра деталей." />
      </aside>
    </div>
  </AppPage>
</template>
