<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Eye, FileSearch, RefreshCw, Search, ShieldCheck } from '@lucide/vue'
import AppPage from '../../components/ui/AppPage.vue'
import PageHeader from '../../components/ui/PageHeader.vue'
import AppToolbar from '../../components/ui/AppToolbar.vue'
import AppFilterBar from '../../components/ui/AppFilterBar.vue'
import AppTable from '../../components/ui/AppTable.vue'
import AppEmptyState from '../../components/ui/AppEmptyState.vue'
import AppLoading from '../../components/ui/AppLoading.vue'
import AppErrorBanner from '../../components/ui/AppErrorBanner.vue'
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'
import WorkspacePanel from '../../components/workspace/WorkspacePanel.vue'
import { createTablePagination, persistTablePagination } from '../../services/tableSettings'
import {
  choiceProgramName,
  formatDate,
  formatDateTime,
  personName,
  programName,
  sourceLabel,
  statusCode,
  statusLabel,
  useAdmissionsFoundationStore,
} from '../../stores/admissionsFoundation'

const store = useAdmissionsFoundationStore()
const route = useRoute()
const router = useRouter()
const rowsKey = 'collegePortal.admissionsFoundation.rowsPerPage'
const syncingQuery = ref(false)
const rowsPerPageOptions = [10, 20, 50]
const tablePagination = ref(createTablePagination(rowsKey, { sortBy: 'submitted_at', descending: true, rowsPerPage: 20 }))

const columns = [
  { name: 'application_number', label: '№ заявления', field: 'application_number', align: 'left', sortable: true },
  { name: 'applicant', label: 'Абитуриент', field: (row) => personName(row), align: 'left', sortable: true },
  { name: 'year', label: 'Год', field: 'admission_year', align: 'left', sortable: true },
  { name: 'source', label: 'Источник', field: (row) => sourceLabel(row), align: 'left' },
  { name: 'status', label: 'Статус', field: (row) => statusLabel(row), align: 'left', sortable: true },
  { name: 'choices_count', label: 'Программы', field: 'choices_count', align: 'right' },
  { name: 'documents', label: 'Документы', field: 'documents', align: 'left' },
  { name: 'created_at', label: 'Создано', field: 'created_at', align: 'left', sortable: true },
  { name: 'registered_at', label: 'Регистрация', field: 'registered_at', align: 'left', sortable: true },
  { name: 'actions', label: '', field: 'actions', align: 'right' },
]

const choiceColumns = [
  { name: 'priority', label: 'Приоритет', field: 'priority', align: 'left' },
  { name: 'program', label: 'Программа', field: (row) => choiceProgramName(row), align: 'left' },
  { name: 'education_form', label: 'Форма', field: (row) => referenceLabel(row.education_form), align: 'left' },
  { name: 'funding_form', label: 'Финансирование', field: (row) => referenceLabel(row.funding_form), align: 'left' },
  { name: 'base', label: 'Основание', field: (row) => referenceLabel(row.base_education_type), align: 'left' },
  { name: 'status', label: 'Статус', field: (row) => referenceLabel(row.status), align: 'left' },
]

const statusOptions = computed(() => referenceOptions('admission_application_statuses', 'Все статусы'))
const sourceOptions = computed(() => referenceOptions('admission_sources', 'Все источники', 'id'))
const hasChoicesOptions = [
  { label: 'Все заявления', value: '' },
  { label: 'Есть выбранные программы', value: '1' },
  { label: 'Без выбранных программ', value: '0' },
]

const selected = computed(() => store.selectedApplication)
const selectedPerson = computed(() => store.selectedPerson)
const selectedStatusTone = computed(() => statusTone(statusCode(selected.value)))
const tableSubtitle = computed(() => `Найдено foundation-заявлений: ${store.pagination.total || 0}`)
const selectedTitle = computed(() => selected.value?.application_number || `Заявление #${selected.value?.id || ''}`)
const selectedSubtitle = computed(() => [
  personName(selected.value),
  selected.value?.admission_year ? `Прием ${selected.value.admission_year}` : '',
  sourceLabel(selected.value),
])
const selectedMetrics = computed(() => [
  { label: 'Программы', value: store.sortedChoices.length },
  { label: 'Foundation', value: selected.value?.foundation_version || '—' },
  { label: 'Документы', value: 'BACK-005' },
])
const readinessItems = computed(() => [
  { label: 'Основные данные абитуриента', ready: Boolean(selectedPerson.value?.last_name && selectedPerson.value?.first_name) },
  { label: 'Документ, удостоверяющий личность', ready: false, pending: true },
  { label: 'СНИЛС', ready: Boolean(selectedPerson.value?.has_snils), pending: !selectedPerson.value?.has_snils },
  { label: 'Документ об образовании', ready: false, pending: true },
  { label: 'Выбранные программы', ready: store.selectedHasChoices },
  { label: 'Регистрация заявления', ready: Boolean(selected.value?.registered_at || statusCode(selected.value) === 'registered') },
])
const filterChips = computed(() => {
  const chips = []
  if (store.filters.q) chips.push({ key: 'q', label: `Поиск: ${store.filters.q}` })
  if (store.filters.status) chips.push({ key: 'status', label: `Статус: ${optionLabel(statusOptions.value, store.filters.status)}` })
  if (store.filters.admission_year) chips.push({ key: 'admission_year', label: `Год: ${store.filters.admission_year}` })
  if (store.filters.source_id) chips.push({ key: 'source_id', label: `Источник: ${optionLabel(sourceOptions.value, store.filters.source_id)}` })
  if (store.filters.has_choices !== '') chips.push({ key: 'has_choices', label: optionLabel(hasChoicesOptions, store.filters.has_choices) })
  return chips
})

function referenceOptions(code, allLabel, valueField = 'code') {
  const items = store.referenceCatalogs[code]?.items || []
  return [
    { label: allLabel, value: '' },
    ...items.map((item) => ({
      label: item.name,
      value: valueField === 'id' ? item.id : item.code,
      code: item.code,
    })),
  ]
}

function optionLabel(options, value) {
  return options.find((option) => String(option.value) === String(value))?.label || value || '—'
}

function referenceLabel(value) {
  return value?.name || value?.code || '—'
}

function statusTone(value) {
  if (['registered', 'accepted', 'active'].includes(value)) return 'success'
  if (['draft', 'new'].includes(value)) return 'info'
  if (['withdrawn', 'rejected', 'excluded'].includes(value)) return 'danger'
  return 'neutral'
}

function documentSummary() {
  return 'Модуль документов ожидает BACK-005'
}

function tableRowClass(row) {
  return Number(row.id) === Number(store.selectedId) ? 'admissions-foundation-row--selected' : ''
}

function updateTablePagination(pagination) {
  tablePagination.value = pagination
  persistTablePagination(rowsKey, pagination)
}

function applyServerPagination() {
  tablePagination.value = {
    ...tablePagination.value,
    page: store.pagination.current_page || 1,
    rowsPerPage: store.pagination.per_page || tablePagination.value.rowsPerPage,
    rowsNumber: store.pagination.total || 0,
  }
}

function routeSelectedId() {
  return route.query.selected ? String(route.query.selected) : ''
}

function filtersFromRoute() {
  return {
    status: route.query.status ? String(route.query.status) : '',
    admission_year: route.query.admission_year ? String(route.query.admission_year) : '',
    source_id: route.query.source_id ? String(route.query.source_id) : '',
    has_choices: route.query.has_choices !== undefined ? String(route.query.has_choices) : '',
  }
}

async function syncQuery(selectedId = routeSelectedId()) {
  const query = { ...route.query }
  selectedId ? query.selected = selectedId : delete query.selected
  store.filters.status ? query.status = store.filters.status : delete query.status
  store.filters.admission_year ? query.admission_year = store.filters.admission_year : delete query.admission_year
  store.filters.source_id ? query.source_id = store.filters.source_id : delete query.source_id
  store.filters.has_choices !== '' ? query.has_choices = store.filters.has_choices : delete query.has_choices
  delete query.q

  syncingQuery.value = true
  await router.replace({ path: '/admissions/foundation', query })
  syncingQuery.value = false
}

async function load(tableOptions = tablePagination.value) {
  await store.loadApplications(tableOptions)
  applyServerPagination()
}

async function applyFilters() {
  tablePagination.value = { ...tablePagination.value, page: 1 }
  await load(tablePagination.value)
  await syncQuery('')
  await store.selectApplication(null)
}

async function resetFilters() {
  store.resetFilters()
  tablePagination.value = { ...tablePagination.value, page: 1 }
  await load(tablePagination.value)
  await syncQuery('')
  await store.selectApplication(null)
}

async function clearFilter(key) {
  store.setFilters({ [key]: '' })
  await applyFilters()
}

async function selectApplication(application) {
  await store.selectApplication(application)
  await syncQuery(application?.id || '')
}

async function refreshSelected() {
  if (store.selectedId) {
    await store.loadApplication(store.selectedId)
  }
}

async function handleTableRequest({ pagination }) {
  updateTablePagination(pagination)
  await load(pagination)
}

watch(() => route.query.selected, async (value) => {
  if (syncingQuery.value) return
  if (value) {
    await store.loadApplication(String(value)).catch(() => {})
  } else {
    await store.selectApplication(null)
  }
})

watch(() => [route.query.status, route.query.admission_year, route.query.source_id, route.query.has_choices], async () => {
  if (syncingQuery.value) return
  store.setFilters(filtersFromRoute())
  await load(tablePagination.value)
}, { deep: true })

onMounted(async () => {
  store.reset()
  store.setFilters(filtersFromRoute())
  await store.loadReferences()
  await load(tablePagination.value)

  if (routeSelectedId()) {
    await store.loadApplication(routeSelectedId()).catch(() => {})
  }
})
</script>

<template>
  <AppPage>
    <PageHeader
      title="Приёмная комиссия"
      subtitle="Новый read-only контур Admissions Foundation для просмотра заявлений, абитуриентов и выбранных программ."
    >
      <template #actions>
        <q-chip color="blue-1" text-color="primary" dense>
          Foundation
        </q-chip>
      </template>
    </PageHeader>

    <AppToolbar>
      <span>{{ tableSubtitle }}</span>
      <template #actions>
        <AppLoading v-if="store.loading || store.detailsLoading || store.choicesLoading" label="Загрузка Admissions Foundation..." />
        <q-btn flat :disable="store.loading" @click="load()">
          <RefreshCw :size="16" class="q-mr-xs" />
          Обновить
        </q-btn>
      </template>
    </AppToolbar>

    <AppErrorBanner :message="store.error" />

    <AppFilterBar @apply="applyFilters" @reset="resetFilters">
      <q-input
        v-model="store.filters.q"
        dense
        outlined
        clearable
        label="Номер заявления или ФИО"
        @keyup.enter="applyFilters"
      >
        <template #prepend>
          <Search :size="16" />
        </template>
      </q-input>
      <q-select v-model="store.filters.status" dense outlined emit-value map-options label="Статус" :options="statusOptions" />
      <q-input v-model="store.filters.admission_year" dense outlined clearable label="Год приема" />
      <q-select v-model="store.filters.source_id" dense outlined emit-value map-options label="Источник" :options="sourceOptions" />
      <q-select v-model="store.filters.has_choices" dense outlined emit-value map-options label="Выбранные программы" :options="hasChoicesOptions" />

      <template #actions>
        <q-btn color="primary" :disable="store.loading" @click="applyFilters">Применить</q-btn>
        <q-btn flat :disable="store.loading" @click="resetFilters">Очистить</q-btn>
      </template>

      <template v-if="filterChips.length" #footer>
        <div class="admissions-foundation-filter-chips">
          <q-chip
            v-for="chip in filterChips"
            :key="chip.key"
            removable
            dense
            @remove="clearFilter(chip.key)"
          >
            {{ chip.label }}
          </q-chip>
        </div>
      </template>
    </AppFilterBar>

    <q-banner rounded class="admissions-foundation-readonly">
      <ShieldCheck :size="18" />
      <span>Рабочее пространство только для просмотра. Создание, изменение, регистрация, документы, ФИС-экспорт и XSD-валидация в FRONT-001 недоступны.</span>
    </q-banner>

    <div class="admissions-foundation-workspace">
      <section class="admissions-foundation-main">
        <AppTable
          v-if="store.applications.length || store.loading"
          :rows="store.applications"
          :columns="columns"
          :loading="store.loading"
          :pagination="tablePagination"
          :rows-per-page-options="rowsPerPageOptions"
          :table-row-class-fn="tableRowClass"
          @update:pagination="updateTablePagination"
          @request="handleTableRequest"
          @row-click="(_, row) => selectApplication(row)"
        >
          <template #body-cell-application_number="props">
            <q-td :props="props">
              <button class="admissions-foundation-row-link" type="button" @click.stop="selectApplication(props.row)">
                {{ props.row.application_number || `#${props.row.id}` }}
              </button>
            </q-td>
          </template>

          <template #body-cell-applicant="props">
            <q-td :props="props">
              <div class="admissions-foundation-person-cell">
                <strong>{{ personName(props.row) }}</strong>
                <small>{{ props.row.applicant?.uuid || 'Applicant UUID не указан' }}</small>
              </div>
            </q-td>
          </template>

          <template #body-cell-status="props">
            <q-td :props="props">
              <AppStatusBadge :label="statusLabel(props.row)" :tone="statusTone(statusCode(props.row))" />
            </q-td>
          </template>

          <template #body-cell-choices_count="props">
            <q-td :props="props">
              <q-chip dense :color="Number(props.row.choices_count || 0) > 0 ? 'green-1' : 'grey-2'" text-color="dark">
                {{ props.row.choices_count ?? 0 }}
              </q-chip>
            </q-td>
          </template>

          <template #body-cell-documents="props">
            <q-td :props="props">
              <q-chip dense color="orange-1" text-color="orange-10">
                {{ documentSummary(props.row) }}
              </q-chip>
            </q-td>
          </template>

          <template #body-cell-created_at="props">
            <q-td :props="props">{{ formatDate(props.row.created_at) }}</q-td>
          </template>

          <template #body-cell-registered_at="props">
            <q-td :props="props">{{ formatDate(props.row.registered_at) }}</q-td>
          </template>

          <template #body-cell-actions="props">
            <q-td :props="props">
              <q-btn flat round dense title="Открыть" @click.stop="selectApplication(props.row)">
                <Eye :size="16" />
              </q-btn>
            </q-td>
          </template>
        </AppTable>

        <AppEmptyState
          v-else
          title="Foundation-заявления не найдены"
          description="Измените фильтры или создайте данные через backend foundation API в следующих задачах."
        />
      </section>

      <aside class="admissions-foundation-side">
        <AppEmptyState
          v-if="!selected && !store.detailsError"
          title="Заявление не выбрано"
          description="Выберите строку, чтобы открыть read-only карточку Admissions Foundation."
        >
          <FileSearch :size="44" />
        </AppEmptyState>

        <q-banner v-else-if="store.detailsError" rounded class="app-error-banner">
          <div class="row items-center justify-between q-gutter-sm">
            <span>{{ store.detailsError }}</span>
            <q-btn flat label="Повторить" @click="refreshSelected" />
          </div>
        </q-banner>

        <WorkspacePanel
          v-else
          class="admissions-foundation-card"
          :title="selectedTitle"
          :subtitle="selectedSubtitle"
          :metrics="selectedMetrics"
        >
          <template #status>
            <AppStatusBadge :label="statusLabel(selected)" :tone="selectedStatusTone" />
          </template>

          <section class="admissions-foundation-section">
            <h3>Общая информация</h3>
            <dl>
              <div><dt>Номер заявления</dt><dd>{{ selected.application_number || 'Не присвоен' }}</dd></div>
              <div><dt>Год приема</dt><dd>{{ selected.admission_year || '—' }}</dd></div>
              <div><dt>Источник подачи</dt><dd>{{ sourceLabel(selected) }}</dd></div>
              <div><dt>Дата создания</dt><dd>{{ formatDateTime(selected.created_at) }}</dd></div>
              <div><dt>Дата регистрации</dt><dd>{{ formatDateTime(selected.registered_at) }}</dd></div>
              <div><dt>Foundation identifier</dt><dd>{{ selected.uuid || `ID ${selected.id}` }}</dd></div>
            </dl>
          </section>

          <section class="admissions-foundation-section">
            <h3>Абитуриент</h3>
            <dl>
              <div><dt>ФИО</dt><dd>{{ personName(selected) }}</dd></div>
              <div><dt>Дата рождения</dt><dd>{{ formatDate(selectedPerson?.birth_date) }}</dd></div>
              <div><dt>Пол</dt><dd>{{ selectedPerson?.gender || '—' }}</dd></div>
              <div><dt>Гражданство</dt><dd>{{ selectedPerson?.citizenship || '—' }}</dd></div>
              <div><dt>Телефон</dt><dd>{{ selectedPerson?.phone || '—' }}</dd></div>
              <div><dt>Email</dt><dd>{{ selectedPerson?.email || '—' }}</dd></div>
            </dl>
          </section>

          <section class="admissions-foundation-section">
            <h3>Идентификационные сведения</h3>
            <q-banner rounded class="admissions-foundation-note">
              Структурированные документы будут добавлены в BACK-005. Полные паспортные реквизиты, сканы и реквизиты документа об образовании не отображаются.
            </q-banner>
            <dl>
              <div><dt>Документ, удостоверяющий личность</dt><dd>Данные документов ещё не внесены</dd></div>
              <div><dt>СНИЛС</dt><dd>{{ selectedPerson?.snils_masked || 'Не указан' }}</dd></div>
              <div><dt>Документ об образовании</dt><dd>Модуль документов ожидает BACK-005</dd></div>
            </dl>
          </section>

          <section class="admissions-foundation-section">
            <h3>Выбранные программы</h3>
            <AppLoading v-if="store.choicesLoading" label="Загрузка выбранных программ..." />
            <AppTable
              v-else-if="store.sortedChoices.length"
              :rows="store.sortedChoices"
              :columns="choiceColumns"
              :pagination="{ rowsPerPage: 0 }"
              :rows-per-page-options="[0]"
            >
              <template #body-cell-program="props">
                <q-td :props="props">{{ choiceProgramName(props.row) }}</q-td>
              </template>
            </AppTable>
            <q-banner v-else rounded class="admissions-foundation-note">
              Выбранные образовательные программы пока не добавлены.
            </q-banner>
          </section>

          <section class="admissions-foundation-section">
            <h3>Готовность заявления</h3>
            <div class="admissions-foundation-checklist">
              <div
                v-for="item in readinessItems"
                :key="item.label"
                :class="['admissions-foundation-check', item.ready ? 'is-ready' : 'is-pending']"
              >
                <span>{{ item.label }}</span>
                <strong>{{ item.ready ? 'Есть' : item.pending ? 'Ожидает следующего этапа' : 'Нет' }}</strong>
              </div>
            </div>
            <q-banner rounded class="admissions-foundation-note">
              Готовность к ФИС показана информационно. Реальная XSD-валидация и формирование пакета ФИС не выполняются в FRONT-001.
            </q-banner>
          </section>

          <section class="admissions-foundation-section">
            <h3>Системная информация</h3>
            <dl>
              <div><dt>Application ID</dt><dd>{{ selected.id }}</dd></div>
              <div><dt>Applicant ID</dt><dd>{{ selected.applicant_id || '—' }}</dd></div>
              <div><dt>Person ID</dt><dd>{{ selectedPerson?.id || '—' }}</dd></div>
              <div><dt>Record type</dt><dd>{{ selected.record_type || 'foundation' }}</dd></div>
              <div><dt>Foundation version</dt><dd>{{ selected.foundation_version || '—' }}</dd></div>
            </dl>
          </section>
        </WorkspacePanel>
      </aside>
    </div>
  </AppPage>
</template>

<style scoped>
.admissions-foundation-readonly,
.admissions-foundation-note {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 14px;
  background: #eff6ff;
  color: #1e3a8a;
}

.admissions-foundation-workspace {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(360px, 440px);
  gap: 16px;
  align-items: start;
}

.admissions-foundation-main,
.admissions-foundation-side {
  min-width: 0;
}

.admissions-foundation-filter-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.admissions-foundation-row-link {
  border: 0;
  padding: 0;
  background: transparent;
  color: #2563eb;
  font: inherit;
  font-weight: 700;
  cursor: pointer;
}

.admissions-foundation-person-cell {
  display: grid;
  gap: 2px;
}

.admissions-foundation-person-cell small {
  color: #64748b;
  overflow-wrap: anywhere;
}

.admissions-foundation-section {
  display: grid;
  gap: 10px;
  margin-bottom: 18px;
}

.admissions-foundation-section h3 {
  margin: 0;
  color: #0f172a;
  font-size: 15px;
  font-weight: 800;
}

.admissions-foundation-section dl {
  display: grid;
  gap: 8px;
  margin: 0;
}

.admissions-foundation-section dl div {
  display: grid;
  grid-template-columns: minmax(120px, 0.8fr) minmax(0, 1.2fr);
  gap: 10px;
}

.admissions-foundation-section dt {
  color: #64748b;
  font-size: 12px;
}

.admissions-foundation-section dd {
  min-width: 0;
  margin: 0;
  color: #0f172a;
  overflow-wrap: anywhere;
}

.admissions-foundation-checklist {
  display: grid;
  gap: 8px;
}

.admissions-foundation-check {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 8px 10px;
}

.admissions-foundation-check.is-ready {
  background: #f0fdf4;
  border-color: #bbf7d0;
}

.admissions-foundation-check.is-pending {
  background: #fff7ed;
  border-color: #fed7aa;
}

:deep(.admissions-foundation-row--selected) {
  background: #eef6ff;
}

@media (max-width: 1180px) {
  .admissions-foundation-workspace {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 640px) {
  .admissions-foundation-section dl div {
    grid-template-columns: 1fr;
    gap: 2px;
  }
}
</style>
