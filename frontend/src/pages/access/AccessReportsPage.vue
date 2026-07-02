<script setup>
import { onMounted } from 'vue'
import { Download, RefreshCw, RotateCcw } from '@lucide/vue'
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
import { ACCESS_ENTITY_OPTIONS, ACCESS_RESULT_OPTIONS, useAccessReportsStore } from '../../stores/accessReports'

const store = useAccessReportsStore()
const columns = [
  { name: 'event_time', label: 'Время', field: 'event_time', align: 'left', sortable: true },
  { name: 'owner', label: 'ФИО', field: 'owner', align: 'left', sortable: true },
  { name: 'entity_type', label: 'Тип', field: 'entity_type', align: 'left', sortable: true },
  { name: 'direction', label: 'Направление', field: 'direction', align: 'left', sortable: true },
  { name: 'result', label: 'Результат', field: 'result', align: 'left', sortable: true },
  { name: 'access_point', label: 'Точка доступа', field: 'access_point', align: 'left', sortable: true },
  { name: 'reason', label: 'Причина', field: 'reason', align: 'left' },
]
const pagination = { rowsPerPage: 20, sortBy: 'event_time', descending: true }

function applyFilters() { store.load() }
function resetFilters() { store.resetFilters(); store.load() }

onMounted(() => store.load())
</script>

<template>
  <AppPage>
    <PageHeader title="Отчеты по проходам" subtitle="События проходной, входы и выходы за период, отказы и текущие присутствующие в здании." />

    <AppToolbar>
      <span>Событий в отчете: {{ store.events.length }}</span>
      <template #actions>
        <AppLoading v-if="store.loading" label="Загрузка отчета..." />
        <q-btn flat :disable="store.loading" @click="store.load"><RefreshCw :size="16" class="q-mr-xs" /> Обновить</q-btn>
        <q-btn color="primary" :loading="store.exporting" @click="store.exportCsv"><Download :size="16" class="q-mr-xs" /> Экспорт CSV</q-btn>
      </template>
    </AppToolbar>

    <AppErrorBanner :message="store.error" />

    <AppFilterBar>
      <q-input v-model="store.filters.search" dense outlined clearable label="ФИО" @keyup.enter="applyFilters" />
      <q-select v-model="store.filters.entity_type" dense outlined emit-value map-options label="Тип владельца" :options="ACCESS_ENTITY_OPTIONS" />
      <q-select v-model="store.filters.result" dense outlined emit-value map-options label="Результат" :options="ACCESS_RESULT_OPTIONS" />
      <q-input v-model="store.filters.date_from" dense outlined type="date" label="Дата с" />
      <q-input v-model="store.filters.date_to" dense outlined type="date" label="Дата по" />
      <template #actions>
        <q-btn color="primary" :disable="store.loading" @click="applyFilters">Применить</q-btn>
        <q-btn flat :disable="store.loading" @click="resetFilters"><RotateCcw :size="16" class="q-mr-xs" /> Сбросить</q-btn>
      </template>
    </AppFilterBar>

    <section class="access-reports-summary">
      <AppCard class="access-report-metric"><span>События сегодня</span><strong>{{ store.summary.today_events }}</strong></AppCard>
      <AppCard class="access-report-metric access-report-metric--in"><span>Входов</span><strong>{{ store.summary.entries }}</strong></AppCard>
      <AppCard class="access-report-metric access-report-metric--out"><span>Выходов</span><strong>{{ store.summary.exits }}</strong></AppCard>
      <AppCard class="access-report-metric access-report-metric--denied"><span>Отказов</span><strong>{{ store.summary.denied }}</strong></AppCard>
      <AppCard class="access-report-metric access-report-metric--inside"><span>Сейчас в здании</span><strong>{{ store.summary.inside_now }}</strong></AppCard>
    </section>

    <AppTable v-if="store.events.length || store.loading" :rows="store.events" :columns="columns" :loading="store.loading" :pagination="pagination">
      <template #body-cell-event_time="props"><q-td :props="props">{{ store.formatEventTime(props.row.event_time) }}</q-td></template>
      <template #body-cell-owner="props"><q-td :props="props"><strong>{{ store.ownerName(props.row) }}</strong></q-td></template>
      <template #body-cell-entity_type="props"><q-td :props="props">{{ store.entityTypeLabel(props.row.entity_type) }}</q-td></template>
      <template #body-cell-direction="props"><q-td :props="props">{{ store.directionLabel(props.row.direction) }}</q-td></template>
      <template #body-cell-result="props"><q-td :props="props"><AppStatusBadge :label="store.resultLabel(props.row.result)" :tone="store.resultTone(props.row.result)" /></q-td></template>
      <template #body-cell-access_point="props"><q-td :props="props">{{ props.row.access_point || '—' }}</q-td></template>
      <template #body-cell-reason="props"><q-td :props="props">{{ props.row.reason || '—' }}</q-td></template>
    </AppTable>
    <AppEmptyState v-else title="События не найдены" description="Измените фильтры или выполните сканирование на проходной." />
  </AppPage>
</template>
