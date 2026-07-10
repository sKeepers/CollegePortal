<script setup>
import { computed, onMounted } from 'vue'
import { RefreshCw } from '@lucide/vue'
import AppPage from '../../components/ui/AppPage.vue'
import PageHeader from '../../components/ui/PageHeader.vue'
import AppToolbar from '../../components/ui/AppToolbar.vue'
import AppTable from '../../components/ui/AppTable.vue'
import AppCard from '../../components/ui/AppCard.vue'
import AppEmptyState from '../../components/ui/AppEmptyState.vue'
import AppLoading from '../../components/ui/AppLoading.vue'
import AppErrorBanner from '../../components/ui/AppErrorBanner.vue'
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'
import { useAttendanceAnalysisStore } from '../../stores/attendanceAnalysis'

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
const pagination = { rowsPerPage: 20, sortBy: 'full_name', descending: false }
const modeOptions = [
  { label: 'Преподаватели', value: 'teachers' },
  { label: 'Студенты', value: 'students' },
]
const pageSubtitle = computed(() => (
  store.date ? `Сопоставление проходной с расписанием за ${new Date(store.date).toLocaleDateString('ru-RU')}` : 'Сопоставление событий проходной с расписанием за сегодня'
))
const metrics = computed(() => [
  { label: 'Всего', value: store.summary.total },
  { label: 'Есть события', value: store.summary.with_events },
  { label: 'Есть расписание', value: store.summary.with_schedule },
  { label: 'Сейчас в здании', value: store.summary.inside_now },
])

function lateLabel(row) {
  if (row.late_minutes === null || row.late_minutes === undefined) return '—'
  return Number(row.late_minutes) > 0 ? `${row.late_minutes} мин.` : 'Нет'
}

onMounted(() => store.load())
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
        @update:model-value="store.setMode"
      />
      <template #actions>
        <AppLoading v-if="store.loading" label="Анализ посещаемости..." />
        <q-btn flat :disable="store.loading" @click="store.load">
          <RefreshCw :size="16" class="q-mr-xs" /> Обновить
        </q-btn>
      </template>
    </AppToolbar>

    <AppErrorBanner :message="store.error" />

    <section class="attendance-analysis-summary">
      <AppCard v-for="metric in metrics" :key="metric.label" class="attendance-analysis-metric">
        <span>{{ metric.label }}</span>
        <strong>{{ metric.value }}</strong>
      </AppCard>
    </section>

    <AppTable
      v-if="store.rows.length || store.loading"
      :rows="store.rows"
      :columns="columns"
      :loading="store.loading"
      :pagination="pagination"
      row-key="id"
    >
      <template #body-cell-full_name="props">
        <q-td :props="props">
          <strong>{{ props.row.full_name }}</strong>
          <div class="attendance-analysis-secondary">
            {{ props.row.entity_type === 'teacher' ? (props.row.department || 'Отделение не указано') : (props.row.group || 'Группа не указана') }}
          </div>
        </q-td>
      </template>
      <template #body-cell-status="props">
        <q-td :props="props">
          <AppStatusBadge :label="props.row.status_label" :tone="props.row.status_tone" />
          <q-chip v-if="props.row.inside_now" dense color="green-1" text-color="positive" class="q-ml-xs">в здании</q-chip>
        </q-td>
      </template>
      <template #body-cell-first_entry="props">
        <q-td :props="props">{{ store.formatAttendanceDateTime(props.row.first_entry) }}</q-td>
      </template>
      <template #body-cell-last_exit="props">
        <q-td :props="props">{{ store.formatAttendanceDateTime(props.row.last_exit) }}</q-td>
      </template>
      <template #body-cell-first_lesson="props">
        <q-td :props="props">{{ store.firstLessonLabel(props.row.first_lesson) }}</q-td>
      </template>
      <template #body-cell-late_minutes="props">
        <q-td :props="props">{{ lateLabel(props.row) }}</q-td>
      </template>
      <template #body-cell-comment="props">
        <q-td :props="props">{{ props.row.comment }}</q-td>
      </template>
    </AppTable>

    <AppEmptyState
      v-else
      title="Нет данных для анализа"
      description="Добавьте расписание и события проходной, чтобы увидеть сопоставление посещаемости."
    />
  </AppPage>
</template>
