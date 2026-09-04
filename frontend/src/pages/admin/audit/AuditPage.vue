<script setup>
import { computed, onMounted } from 'vue'
import { Eye, RefreshCw, ShieldCheck } from '@lucide/vue'
import AppPage from '../../../components/ui/AppPage.vue'
import PageHeader from '../../../components/ui/PageHeader.vue'
import AppToolbar from '../../../components/ui/AppToolbar.vue'
import AppFilterBar from '../../../components/ui/AppFilterBar.vue'
import AppTable from '../../../components/ui/AppTable.vue'
import AppCard from '../../../components/ui/AppCard.vue'
import AppErrorBanner from '../../../components/ui/AppErrorBanner.vue'
import AppEmptyState from '../../../components/ui/AppEmptyState.vue'
import AppLoading from '../../../components/ui/AppLoading.vue'
import { useAuditStore, moduleLabel, actionLabel } from '../../../stores/audit'
import { formatDateTime as formatCollegeDateTime } from '../../../utils/datetime'

const store = useAuditStore()
/**
 * Постраничность серверная: в журнале 16 977 записей, и держать их в памяти
 * незачем, а показывать двести и подписывать «из 200» — обман. `rowsNumber`
 * говорит таблице, сколько строк есть на самом деле, и она сама перестаёт
 * листать на месте: каждый переход уходит запросом.
 */
const tablePagination = computed(() => ({
  page: store.pagination.page,
  rowsPerPage: store.pagination.per_page,
  rowsNumber: store.pagination.total,
  sortBy: 'created_at',
  descending: store.direction !== 'asc',
}))

function onTableRequest(event) {
  const next = event?.pagination ?? {}

  store.load({
    page: next.page,
    per_page: next.rowsPerPage,
    direction: next.descending === false ? 'asc' : 'desc',
  })
}
const columns = [
  { name: 'created_at', label: 'Дата', field: 'created_at', align: 'left', sortable: true },
  { name: 'module', label: 'Модуль', field: 'module', align: 'left' },
  { name: 'user', label: 'Пользователь', field: 'user', align: 'left' },
  { name: 'action', label: 'Действие', field: 'action', align: 'left' },
  { name: 'entity', label: 'Объект', field: 'entity_type', align: 'left' },
  { name: 'ip_address', label: 'IP', field: 'ip_address', align: 'left' },
  { name: 'actions', label: '', field: 'actions', align: 'right' },
]

const selectedLog = computed(() => store.selectedLog)

function formatDate(value) {
  return formatCollegeDateTime(value)
}

function prettyJson(value) {
  if (!value) return '—'
  return JSON.stringify(value, null, 2)
}

function objectLabel(log) {
  if (!log?.entity_type) return '—'
  return `${log.entity_type}${log.entity_id ? ` #${log.entity_id}` : ''}`
}

function objectRoute(log) {
  if (!log?.entity_type || !log?.entity_id) return null
  const type = String(log.entity_type).toLowerCase()
  if (type === 'user') return '/admin/users'
  if (type === 'role') return '/admin/roles'
  if (type === 'importjob') return '/admin/import'
  if (type === 'digitalidentity') return '/identity/digital-passes'
  return null
}

function selectLog(log) {
  store.selectedId = log.id
}

function applyFilters() {
  // Новый отбор — снова первая страница: сороковая с новым отбором окажется
  // пустой, и это прочтут как «ничего не найдено».
  store.load({ page: 1 })
}

function resetFilters() {
  store.resetFilters()
  store.load({ page: 1 })
}

function rowClass(row) {
  return Number(row.id) === Number(store.selectedId) ? 'cp-selected-row' : ''
}

onMounted(async () => {
  await store.load()
  if (!store.selectedId && store.logs[0]) {
    store.selectedId = store.logs[0].id
  }
})
</script>

<template>
  <AppPage>
    <PageHeader title="Аудит" subtitle="Централизованный журнал действий пользователей CollegePortal." />
    <AppToolbar>
      <span>Журнал фиксирует ключевые действия: вход, выход, пользователи, роли, импорт, QR и демо-данные.</span>
      <template #actions>
        <AppLoading v-if="store.loading" label="Загрузка аудита..." />
        <q-btn flat :disable="store.loading" @click="store.load"><RefreshCw :size="16" class="q-mr-xs" /> Обновить</q-btn>
      </template>
    </AppToolbar>
    <AppErrorBanner :message="store.error" />

    <AppFilterBar>
      <q-input v-model="store.filters.search" dense outlined clearable label="Поиск" @keyup.enter="applyFilters" />
      <!-- Список пользователей открыт только под users.manage. Директор журнал
           видит, а список нет: пустой фильтр без объяснения выглядит поломкой,
           поэтому он просто не показывается. -->
      <q-select v-if="store.userOptions.length" v-model="store.filters.user_id" dense outlined clearable emit-value map-options label="Пользователь" :options="store.userOptions" />
      <q-select v-model="store.filters.module" dense outlined clearable emit-value map-options label="Модуль" :options="store.moduleOptions" />
      <q-select v-model="store.filters.action" dense outlined clearable emit-value map-options label="Действие" :options="store.actionOptions" />
      <q-input v-model="store.filters.date_from" dense outlined clearable label="С даты" type="date" />
      <q-input v-model="store.filters.date_to" dense outlined clearable label="По дату" type="date" />
      <q-btn color="primary" @click="applyFilters">Применить</q-btn>
      <q-btn flat @click="resetFilters">Сбросить</q-btn>
    </AppFilterBar>

    <div class="audit-layout">
      <section class="audit-main">
        <AppTable
          v-if="store.logs.length"
          :pagination="tablePagination"
          @request="onTableRequest"
          :rows="store.logs"
          :columns="columns"
          :loading="store.loading"
          :table-row-class-fn="rowClass"
        >
          <template #body-cell-created_at="props"><q-td :props="props">{{ formatDate(props.row.created_at) }}</q-td></template>
          <template #body-cell-module="props"><q-td :props="props">{{ moduleLabel(props.row.module) }}</q-td></template>
          <template #body-cell-user="props"><q-td :props="props">{{ props.row.user?.name || 'Система' }}</q-td></template>
          <template #body-cell-action="props"><q-td :props="props">{{ actionLabel(props.row.action) }}</q-td></template>
          <template #body-cell-entity="props"><q-td :props="props">{{ objectLabel(props.row) }}</q-td></template>
          <template #body-cell-actions="props">
            <q-td :props="props"><q-btn flat dense round title="Открыть событие" @click="selectLog(props.row)"><Eye :size="16" /></q-btn></q-td>
          </template>
        </AppTable>
        <AppEmptyState v-else title="Событий аудита нет" description="События появятся после действий пользователей." />
      </section>

      <aside class="audit-side">
        <AppCard v-if="selectedLog" title="Карточка события" subtitle="Детали аудита">
          <div class="audit-card-head">
            <div class="audit-icon"><ShieldCheck :size="26" /></div>
            <div>
              <h3>{{ actionLabel(selectedLog.action) }}</h3>
              <p>{{ moduleLabel(selectedLog.module) }} · {{ formatDate(selectedLog.created_at) }}</p>
            </div>
          </div>

          <dl class="audit-fields">
            <dt>Пользователь</dt>
            <dd>{{ selectedLog.user?.name || 'Система' }}</dd>
            <dt>Объект</dt>
            <dd>{{ objectLabel(selectedLog) }}</dd>
            <dt>IP</dt>
            <dd>{{ selectedLog.ip_address || '—' }}</dd>
            <dt>Request ID</dt>
            <dd>{{ selectedLog.request_id || '—' }}</dd>
          </dl>

          <q-btn v-if="objectRoute(selectedLog)" outline color="primary" class="q-mt-md" :to="objectRoute(selectedLog)">Открыть объект</q-btn>

          <div class="audit-json-grid">
            <div>
              <strong>Старое значение</strong>
              <pre>{{ prettyJson(selectedLog.old_values) }}</pre>
            </div>
            <div>
              <strong>Новое значение</strong>
              <pre>{{ prettyJson(selectedLog.new_values) }}</pre>
            </div>
          </div>
        </AppCard>
        <AppEmptyState v-else title="Событие не выбрано" description="Выберите строку аудита для просмотра деталей." />
      </aside>
    </div>
  </AppPage>
</template>

<style scoped>
.audit-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 430px;
  gap: 16px;
  align-items: start;
}

.audit-card-head {
  display: flex;
  gap: 12px;
  align-items: center;
}

.audit-card-head h3 {
  margin: 0;
  font-size: 18px;
}

.audit-card-head p {
  margin: 4px 0 0;
  color: #64748b;
}

.audit-icon {
  display: grid;
  place-items: center;
  width: 52px;
  height: 52px;
  border-radius: 8px;
  background: #eef2ff;
  color: #1d4ed8;
}

.audit-fields {
  display: grid;
  grid-template-columns: 120px 1fr;
  gap: 8px 12px;
  margin: 16px 0 0;
}

.audit-fields dt {
  color: #64748b;
}

.audit-fields dd {
  margin: 0;
  overflow-wrap: anywhere;
}

.audit-json-grid {
  display: grid;
  gap: 12px;
  margin-top: 16px;
}

.audit-json-grid pre {
  max-height: 260px;
  overflow: auto;
  margin: 8px 0 0;
  padding: 12px;
  border-radius: 8px;
  background: #0f172a;
  color: #e2e8f0;
  font-size: 12px;
  line-height: 1.5;
}

:deep(.cp-selected-row) {
  background: #eff6ff;
}

@media (max-width: 1439px) {
  .audit-layout {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
