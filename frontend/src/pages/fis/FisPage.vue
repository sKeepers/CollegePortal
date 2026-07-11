<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useQuasar } from 'quasar'
import { useRoute, useRouter } from 'vue-router'
import { Archive, CheckCircle2, Download, ExternalLink, FileJson, Plus, RefreshCw, ShieldCheck } from '@lucide/vue'
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
import { usePermissions } from '../../composables/usePermissions'
import { TABLE_ROWS_PER_PAGE_OPTIONS, createTablePagination, persistTablePagination } from '../../services/tableSettings'
import { FIS_RECORD_STATUS_OPTIONS, FIS_STATUS_OPTIONS, FIS_TYPE_OPTIONS, formatRuDateTime, optionLabel, statusTone, useFisStore } from '../../stores/fis'

const store = useFisStore()
const permissions = usePermissions()
const canManage = computed(() => permissions.hasPermission('fis.export'))
const $q = useQuasar(), route = useRoute(), router = useRouter()
const rowsKey = 'collegePortal.fis.rowsPerPage'
const syncingQuery = ref(false), createVisible = ref(false)
const tablePagination = ref(createTablePagination(rowsKey, { sortBy: 'created_at', descending: true, rowsPerPage: 20 }))
const form = reactive({ name: '', package_type: 'admission', year: new Date().getFullYear(), education_program_id: '', note: '' })
const createDialogTitle = computed(() => form.package_type === 'gia' ? 'Новый пакет ФИС ГИА' : 'Новый пакет ФИС Приема')
const createTypeLabel = computed(() => form.package_type === 'gia' ? 'gia' : 'admission')
const createSourceLabel = computed(() => form.package_type === 'gia' ? 'ГИА' : 'Приемная комиссия')
const columns = [
  { name: 'name', label: 'Пакет', field: 'name', align: 'left', sortable: true },
  { name: 'type', label: 'Тип', field: 'package_type', align: 'left' },
  { name: 'year', label: 'Год', field: 'year', align: 'left', sortable: true },
  { name: 'program', label: 'Программа', field: 'education_program', align: 'left' },
  { name: 'records', label: 'Записей', field: 'records_count', align: 'left' },
  { name: 'errors', label: 'Ошибок', field: 'validation_errors_count', align: 'left' },
  { name: 'status', label: 'Статус', field: 'status', align: 'left' },
]
const tableSubtitle = computed(() => `Найдено пакетов: ${store.filteredPackages.length}`)
const selected = computed(() => store.selectedPackage)

const fisMetrics = computed(() => [
  { label: 'Записей', value: selected.value?.records_count ?? store.records.length },
  { label: 'Ошибок', value: selected.value?.validation_errors_count ?? store.errors.length },
  { label: 'Год', value: selected.value?.year || '—' },
  { label: 'Выгрузка', value: selected.value?.exported_at ? 'Да' : 'Нет' },
])
function rowClass(row) { return Number(row.id) === Number(store.selectedId) ? 'fis-row--selected' : '' }
function notify(message) { $q.notify({ type: 'positive', message, position: 'top-right', timeout: 1800 }) }
function packageProgram(pkg) { return pkg?.education_program?.name || 'Все программы' }
function payload(record, key) { return record?.payload?.[key] || '—' }
function recordLink(record) { return selected.value?.package_type === 'admission' ? { path: '/admissions', query: { selected: record.applicant_application_id } } : { path: '/exams', query: { selected: record.exam_id } } }
function updatePagination(p) { tablePagination.value = p; persistTablePagination(rowsKey, p) }
function routeSelectedId() { return route.query.selected ? String(route.query.selected) : '' }
async function syncQuery(selectedId = routeSelectedId()) { const query = { ...route.query }; selectedId ? query.selected = selectedId : delete query.selected; syncingQuery.value = true; await router.replace({ path: '/fis', query }); syncingQuery.value = false }
async function selectPackage(pkg) { store.select(pkg); await syncQuery(pkg?.id || '') }
function openCreate(type = 'admission') { if (!canManage.value) return; Object.assign(form, { name: '', package_type: type, year: store.yearOptions[0]?.value || new Date().getFullYear(), education_program_id: '', note: '' }); createVisible.value = true }
async function createPackage() { if (!canManage.value) return; await store.createPackage(form); createVisible.value = false; notify('Пакет ФИС создан') }
async function validatePackage() { if (!canManage.value) return; await store.validatePackage(); notify('Пакет проверен') }
async function markExported() { if (!canManage.value) return; await store.markExported(); notify('Пакет отмечен как выгруженный') }
async function archivePackage() { if (!canManage.value) return; await store.archive(); notify('Пакет архивирован') }
async function exportCsv() { if (!canManage.value) return; await store.exportCsv(); notify('CSV выгружен') }
async function exportJson() { if (!canManage.value) return; await store.exportJson(); notify('JSON выгружен') }
async function applyFilters() { store.setFilters({ ...store.filters }); await syncQuery('') }
async function resetFilters() { store.resetFilters(); await syncQuery('') }
watch(() => route.query.selected, () => { if (!syncingQuery.value) store.selectById(routeSelectedId()) })
onMounted(async () => { store.selectById(routeSelectedId()); await store.load(); if (!store.selectedPackage && store.filteredPackages[0]) await selectPackage(store.filteredPackages[0]) })
</script>

<template>
  <AppPage>
    <PageHeader title="ФИС" subtitle="Подготовка, проверка и выгрузка данных для ФИС ГИА и ФИС Приема без реальной отправки."><template #actions><q-btn v-if="canManage" color="primary" @click="openCreate('admission')"><Plus :size="16" class="q-mr-xs" /> Пакет приема</q-btn><q-btn v-if="canManage" outline color="primary" class="q-ml-sm" @click="openCreate('gia')"><Plus :size="16" class="q-mr-xs" /> Пакет ГИА</q-btn></template></PageHeader>
    <AppToolbar><span>{{ tableSubtitle }}</span><template #actions><AppLoading v-if="store.loading" label="Загрузка ФИС..." /><q-btn flat :disable="store.loading" @click="store.load"><RefreshCw :size="16" class="q-mr-xs" /> Обновить</q-btn></template></AppToolbar>
    <AppErrorBanner :message="store.error" />
    <AppFilterBar><q-select v-model="store.filters.package_type" dense outlined clearable emit-value map-options label="Тип" :options="FIS_TYPE_OPTIONS" /><q-select v-model="store.filters.year" dense outlined clearable emit-value map-options label="Год" :options="store.yearOptions" /><q-select v-model="store.filters.status" dense outlined clearable emit-value map-options label="Статус" :options="FIS_STATUS_OPTIONS" /><q-select v-model="store.filters.education_program_id" dense outlined clearable emit-value map-options label="Программа" :options="store.programOptions" /><template #actions><q-btn color="primary" @click="applyFilters">Применить</q-btn><q-btn flat @click="resetFilters">Сбросить</q-btn></template></AppFilterBar>
    <div class="fis-workspace"><div class="fis-main"><AppTable v-if="store.filteredPackages.length || store.loading" :rows="store.filteredPackages" :columns="columns" :loading="store.loading" :pagination="tablePagination" :rows-per-page-options="TABLE_ROWS_PER_PAGE_OPTIONS" :table-row-class-fn="rowClass" @update:pagination="updatePagination" @row-click="(_, row) => selectPackage(row)"><template #body-cell-name="props"><q-td :props="props"><button class="fis-row-link" type="button" @click.stop="selectPackage(props.row)">{{ props.row.name }}</button><div class="fis-secondary-cell">Проверка: {{ formatRuDateTime(props.row.validation_checked_at) }}</div></q-td></template><template #body-cell-type="props"><q-td :props="props">{{ optionLabel(FIS_TYPE_OPTIONS, props.row.package_type) }}</q-td></template><template #body-cell-program="props"><q-td :props="props">{{ packageProgram(props.row) }}</q-td></template><template #body-cell-records="props"><q-td :props="props">{{ props.row.records_count ?? props.row.records?.length ?? 0 }}</q-td></template><template #body-cell-errors="props"><q-td :props="props"><strong :class="Number(props.row.validation_errors_count || 0) > 0 ? 'text-negative' : 'text-positive'">{{ props.row.validation_errors_count ?? props.row.validation_errors?.length ?? 0 }}</strong></q-td></template><template #body-cell-status="props"><q-td :props="props"><AppStatusBadge :label="optionLabel(FIS_STATUS_OPTIONS, props.row.status)" :tone="statusTone(FIS_STATUS_OPTIONS, props.row.status)" /></q-td></template></AppTable><AppEmptyState v-else title="Пакеты ФИС не найдены" description="Создайте пакет приема или ГИА." /></div>
      <aside class="fis-side"><AppEmptyState v-if="!selected" title="Пакет не выбран" description="Выберите пакет в таблице, чтобы открыть карточку." /><WorkspacePanel v-else class="fis-card" :title="selected.name" :subtitle="`${selected.year} · ${packageProgram(selected)}`" :metrics="fisMetrics"><template #status><AppStatusBadge :label="optionLabel(FIS_TYPE_OPTIONS, selected.package_type)" tone="info" /><AppStatusBadge :label="optionLabel(FIS_STATUS_OPTIONS, selected.status)" :tone="statusTone(FIS_STATUS_OPTIONS, selected.status)" /></template><div class="fis-details"><section><h3>Действия</h3><div class="fis-actions"><q-btn color="primary" dense :loading="store.saving" @click="validatePackage"><ShieldCheck :size="15" class="q-mr-xs" /> Проверить</q-btn><q-btn dense outline @click="exportCsv"><Download :size="15" class="q-mr-xs" /> CSV</q-btn><q-btn dense outline @click="exportJson"><FileJson :size="15" class="q-mr-xs" /> JSON</q-btn><q-btn dense outline color="positive" :loading="store.saving" @click="markExported"><CheckCircle2 :size="15" class="q-mr-xs" /> Exported</q-btn><q-btn dense outline color="warning" :loading="store.saving" @click="archivePackage"><Archive :size="15" class="q-mr-xs" /> Архив</q-btn></div></section><section v-if="store.errors.length"><h3>Ошибки проверки</h3><div class="fis-errors"><div v-for="error in store.errors" :key="error.id"><strong>{{ error.field || 'Поле' }}</strong><span>{{ error.message }}</span></div></div></section><section><h3>Записи пакета</h3><div v-if="store.records.length" class="fis-record-list"><article v-for="record in store.records" :key="record.id" class="fis-record-item"><div><RouterLink :to="recordLink(record)" class="entity-link-action fis-record-title">{{ payload(record, 'person') }}</RouterLink><span>{{ payload(record, 'birth_date') }}</span></div><div><strong>{{ payload(record, 'education_program') }}</strong><span>{{ payload(record, 'specialty') }}</span></div><div><span>{{ payload(record, 'details') }}</span></div><div class="fis-record-footer"><AppStatusBadge :label="optionLabel(FIS_RECORD_STATUS_OPTIONS, record.status)" :tone="statusTone(FIS_RECORD_STATUS_OPTIONS, record.status)" /><q-btn flat round dense title="Открыть запись" :to="recordLink(record)"><ExternalLink :size="15" /></q-btn></div></article></div><p v-else class="fis-muted">В пакете пока нет записей.</p></section></div></WorkspacePanel></aside></div>
    <q-dialog v-model="createVisible"><q-card class="fis-dialog"><q-card-section><div class="text-h6">{{ createDialogTitle }}</div><div class="text-caption text-grey-7">Тип и источник заданы выбранной кнопкой.</div></q-card-section><q-card-section class="fis-dialog__body"><q-input v-model="form.name" outlined dense label="Название" /><q-input :model-value="createTypeLabel" outlined dense readonly label="Тип пакета" /><q-input :model-value="createSourceLabel" outlined dense readonly label="Источник" /><q-input v-model="form.year" outlined dense type="number" label="Год" /><q-select v-model="form.education_program_id" outlined dense clearable emit-value map-options label="Программа" :options="store.programOptions" /><q-input v-model="form.note" outlined dense type="textarea" label="Комментарий" /></q-card-section><q-card-actions align="right"><q-btn flat label="Отмена" @click="createVisible = false" /><q-btn color="primary" :loading="store.saving" :disable="!form.year || !form.package_type" @click="createPackage">Создать</q-btn></q-card-actions></q-card></q-dialog>
  </AppPage>
</template>
