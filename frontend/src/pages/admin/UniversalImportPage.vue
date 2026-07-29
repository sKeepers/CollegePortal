<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useQuasar } from 'quasar'
import { CheckCircle2, Download, FileSpreadsheet, History, RefreshCw, Upload, Wand2 } from '@lucide/vue'
import AppPage from '../../components/ui/AppPage.vue'
import PageHeader from '../../components/ui/PageHeader.vue'
import AppToolbar from '../../components/ui/AppToolbar.vue'
import AppCard from '../../components/ui/AppCard.vue'
import AppErrorBanner from '../../components/ui/AppErrorBanner.vue'
import AppLoading from '../../components/ui/AppLoading.vue'
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'
import AppTable from '../../components/ui/AppTable.vue'
import { useUniversalImportStore } from '../../stores/universalImport'
import { usePermissions } from '../../composables/usePermissions'

const store = useUniversalImportStore()
const permissions = usePermissions()
const canManage = computed(() => permissions.hasPermission('import.manage'))
const $q = useQuasar()
const dataType = ref('students')
const mode = ref('skip_duplicates')
const file = ref(null)
const fisFile = ref(null)
const mapping = reactive({})
const previewColumns = computed(() => (store.currentJob?.headers || []).map((header) => ({ name: header, label: header, field: header, align: 'left' })))
const historyColumns = [
  { name: 'created_at', label: 'Дата', field: 'created_at', align: 'left' },
  { name: 'data_type', label: 'Тип', field: 'data_type', align: 'left' },
  { name: 'file', label: 'Файл', field: 'original_filename', align: 'left' },
  { name: 'status', label: 'Статус', field: 'status', align: 'left' },
  { name: 'result', label: 'Результат', field: 'result', align: 'left' },
]
const selectedTypeConfig = computed(() => store.typeOptions.find((type) => type.value === dataType.value) || null)
const fieldOptions = computed(() => (selectedTypeConfig.value?.fields || []).map((field) => ({ label: field.label + (field.required ? ' *' : ''), value: field.value, required: field.required, example: field.example })))
const headerOptions = computed(() => (store.currentJob?.headers || []).map((header) => ({ label: header, value: header })))
const result = computed(() => store.currentJob?.result || null)
const fisResult = computed(() => store.currentJob?.source === 'fis_admissions' ? (store.currentJob?.result || store.currentJob?.metadata || null) : null)
const isFisJob = computed(() => store.currentJob?.source === 'fis_admissions')
const canFisApply = computed(() => Boolean(store.currentJob?.source === 'fis_admissions' && store.currentJob?.id && fisResult.value && (fisResult.value.critical_errors || 0) === 0 && (fisResult.value.ambiguous_duplicates || 0) === 0 && (fisResult.value.unresolved_competitions || 0) === 0 && (fisResult.value.total_rows || 0) === 149))
const errors = computed(() => store.currentJob?.validation_errors || [])
const canPreview = computed(() => Boolean(dataType.value && file.value))
const canConfirm = computed(() => Boolean(store.currentJob?.id && Object.keys(mapping).length))
const requiredLabels = computed(() => selectedTypeConfig.value?.required_fields?.map((field) => field.label) || [])
const keyLabels = computed(() => selectedTypeConfig.value?.key_field_labels || selectedTypeConfig.value?.key_fields || [])
const exampleEntries = computed(() => Object.entries(selectedTypeConfig.value?.template?.example || {}))

function formatDate(value) {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return String(value)
  return date.toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}
function typeLabel(type) { return store.typeOptions.find((item) => item.value === type)?.label || type }
function statusTone(status) { return status === 'completed' ? 'success' : status === 'completed_with_errors' || status === 'validation_failed' ? 'warning' : 'info' }
function statusLabel(status) {
  const labels = { preview: 'Предпросмотр', validated: 'Проверено', validation_failed: 'Есть ошибки', completed: 'Завершено', completed_with_errors: 'Завершено с ошибками' }
  return labels[status] || status || '—'
}
function reportText(job) {
  return `строк ${job.total_rows || 0}, создано ${job.created_count || 0}, обновлено ${job.updated_count || 0}, пропущено ${job.skipped_count || 0}, ошибок ${job.error_count || 0}`
}
function syncMappingFromJob() {
  Object.keys(mapping).forEach((key) => delete mapping[key])
  Object.entries(store.currentJob?.mapping || {}).forEach(([field, header]) => { mapping[field] = header || '' })
}
async function handleDownloadTemplate() {
  if (!canManage.value) return
  const blob = await store.downloadTemplate(dataType.value)
  if (!blob) return
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = selectedTypeConfig.value?.template?.filename || `collegeportal_${dataType.value}_template.csv`
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
  $q.notify({ type: 'positive', message: 'Шаблон CSV скачан', position: 'top-right' })
}


async function handleFisAnalyze() {
  if (!canManage.value || !fisFile.value) return
  await store.fisAnalyze(fisFile.value)
  $q.notify({ type: 'positive', message: 'Файл ФИС распознан', position: 'top-right' })
}
async function handleFisDryRun() {
  if (!canManage.value || !fisFile.value) return
  await store.fisDryRun(fisFile.value)
  const hasBlockers = (fisResult.value?.critical_errors || 0) > 0 || (fisResult.value?.ambiguous_duplicates || 0) > 0 || (fisResult.value?.unresolved_competitions || 0) > 0
  $q.notify({ type: hasBlockers ? 'warning' : 'positive', message: hasBlockers ? 'Dry-run ФИС требует проверки' : 'Dry-run ФИС прошел без критических ошибок', position: 'top-right' })
}
async function handleFisApply() {
  if (!canManage.value || !canFisApply.value) return
  await store.fisApply(store.currentJob.id)
  $q.notify({ type: 'positive', message: 'Импорт ФИС применен', position: 'top-right' })
}
function fisStat(label, value) { return { label, value: value ?? 0 } }

async function handlePreview() {
  if (!canManage.value) return
  await store.preview(dataType.value, file.value)
  syncMappingFromJob()
  $q.notify({ type: 'positive', message: 'Предварительный просмотр подготовлен', position: 'top-right' })
}
async function handleValidate() {
  if (!canManage.value) return
  await store.validate({ ...mapping }, mode.value)
  syncMappingFromJob()
  $q.notify({ type: errors.value.length ? 'warning' : 'positive', message: errors.value.length ? 'Найдены ошибки проверки' : 'Проверка прошла без ошибок', position: 'top-right' })
}
async function handleConfirm() {
  if (!canManage.value) return
  await store.confirm({ ...mapping }, mode.value)
  syncMappingFromJob()
  $q.notify({ type: store.currentJob.error_count ? 'warning' : 'positive', message: 'Импорт выполнен', position: 'top-right' })
}
watch(dataType, async (value) => { store.resetJob(); file.value = null; await store.loadHistory(value) })
onMounted(async () => { await store.loadConfig(); if (store.typeOptions[0]) dataType.value = store.typeOptions[0].value; await store.loadHistory(dataType.value) })
</script>

<template>
  <AppPage>
    <PageHeader title="Импорт реальных данных" subtitle="Загрузка CSV/XLSX с предварительным просмотром, сопоставлением колонок, проверкой и подтверждением импорта." />
    <AppToolbar>
      <span>Импорт не очищает данные и не меняет production-ограничения.</span>
      <template #actions>
        <AppLoading v-if="store.loading || store.saving" label="Обработка файла..." />
        <q-btn flat :disable="store.loading" @click="store.loadHistory(dataType)"><RefreshCw :size="16" class="q-mr-xs" /> История</q-btn>
      </template>
    </AppToolbar>
    <AppErrorBanner :message="store.error" />

    <div class="universal-import-layout">
      <section class="universal-import-main">

        <AppCard title="ФИС ГИА и Приема" subtitle="Специализированный импорт принятых заявлений из Excel-выгрузки ФИС. Персональные поля в предпросмотре маскируются.">
          <div class="fis-import-controls">
            <q-file v-if="canManage" v-model="fisFile" outlined dense accept=".xls,.xlsx" label="Файл ФИС XLS/XLSX"><template #prepend><Upload :size="16" /></template></q-file>
            <q-btn v-if="canManage" outline color="primary" :disable="!fisFile" :loading="store.saving" @click="handleFisAnalyze">Распознать</q-btn>
            <q-btn v-if="canManage" color="primary" :disable="!fisFile" :loading="store.saving" @click="handleFisDryRun">Dry-run</q-btn>
            <q-btn v-if="canManage" color="negative" outline :disable="!canFisApply" :loading="store.saving" @click="handleFisApply">Подтвердить apply</q-btn>
          </div>
          <q-stepper flat animated alternative-labels class="fis-import-steps" :model-value="fisResult ? 6 : 1">
            <q-step :name="1" title="Файл" :done="Boolean(store.currentJob?.source === 'fis_admissions')" />
            <q-step :name="2" title="Структура" :done="Boolean(fisResult?.headers?.length)" />
            <q-step :name="3" title="Статистика" :done="Boolean(fisResult?.total_rows)" />
            <q-step :name="4" title="Конкурсы" :done="Boolean(fisResult && (fisResult.unresolved_competitions || 0) === 0)" />
            <q-step :name="5" title="Дубли" :done="Boolean(fisResult && (fisResult.ambiguous_duplicates || 0) === 0)" />
            <q-step :name="6" title="Итог" />
          </q-stepper>
          <div v-if="fisResult" class="fis-import-report">
            <div v-for="item in [fisStat('Строк', fisResult.total_rows), fisStat('Валидных', fisResult.valid_rows), fisStat('Персон', fisResult.unique_persons), fisStat('Новых персон', fisResult.new_persons), fisStat('Найдено персон', fisResult.found_persons), fisStat('Новых заявлений', fisResult.applications_to_create), fisStat('Обновлений', fisResult.applications_to_update), fisStat('Неоднозначных дублей', fisResult.ambiguous_duplicates), fisStat('Конкурсов', fisResult.unique_competitions), fisStat('Несопоставлено', fisResult.unresolved_competitions), fisStat('Критических ошибок', fisResult.critical_errors)]" :key="item.label">
              <span>{{ item.label }}</span><strong>{{ item.value }}</strong>
            </div>
          </div>
          <div v-if="fisResult?.warnings?.length" class="universal-import-errors q-mt-md">
            <article v-for="warning in fisResult.warnings" :key="warning"><strong>Предупреждение</strong><span>{{ warning }}</span></article>
          </div>
          <div v-if="fisResult?.unresolved_competitions_list?.length" class="q-mt-md">
            <strong>Несопоставленные конкурсы</strong>
            <div class="universal-import-chips"><q-chip v-for="competition in fisResult.unresolved_competitions_list" :key="competition" dense color="red-1" text-color="red-9">{{ competition }}</q-chip></div>
          </div>
          <div v-if="fisResult?.preview_rows?.length" class="import-preview-scroll import-preview-scroll--fis q-mt-md"><AppTable :rows="fisResult.preview_rows" :columns="[
            { name: 'row', label: 'Строка', field: 'row', align: 'left' },
            { name: 'application_number', label: '№ заявления', field: 'application_number', align: 'left' },
            { name: 'fio', label: 'ФИО', field: 'fio', align: 'left' },
            { name: 'snils', label: 'СНИЛС', field: 'snils', align: 'left' },
            { name: 'competition', label: 'Конкурс', field: 'competition', align: 'left' },
            { name: 'person', label: 'Персона', field: 'person', align: 'left' },
            { name: 'application', label: 'Заявление', field: 'application', align: 'left' },
          ]" :pagination="{ rowsPerPage: 5 }" :rows-per-page-options="[5, 10, 20]" /></div>
        </AppCard>

        <AppCard title="1. Файл и тип данных" subtitle="Выберите раздел, файл CSV/XLSX и режим обработки дублей.">
          <div class="universal-import-controls">
            <q-select v-model="dataType" outlined dense emit-value map-options label="Тип данных" :options="store.typeOptions" />
            <q-select v-model="mode" outlined dense emit-value map-options label="Режим" :options="store.modeOptions" option-value="value" option-label="label" />
            <q-file v-if="canManage" v-model="file" outlined dense accept=".csv,.txt,.xlsx" label="CSV или XLSX"><template #prepend><Upload :size="16" /></template></q-file>
            <q-btn v-if="canManage" outline color="primary" :loading="store.saving" @click="handleDownloadTemplate"><Download :size="16" class="q-mr-xs" /> Шаблон CSV</q-btn>
            <q-btn v-if="canManage" color="primary" :disable="!canPreview" :loading="store.saving" @click="handlePreview"><FileSpreadsheet :size="16" class="q-mr-xs" /> Предпросмотр</q-btn>
          </div>
          <q-banner rounded class="universal-import-hint">Поддерживаются студенты, группы, преподаватели, дисциплины, аудитории и абитуриенты. Импорт выполняется только после подтверждения.</q-banner>
          <div v-if="selectedTypeConfig" class="universal-import-reference">
            <div>
              <strong>Обязательные поля</strong>
              <div class="universal-import-chips">
                <q-chip v-for="label in requiredLabels" :key="label" dense color="red-1" text-color="red-9">{{ label }}</q-chip>
                <q-chip v-if="!requiredLabels.length" dense>Нет обязательных полей</q-chip>
              </div>
            </div>
            <div>
              <strong>Ключ обновления</strong>
              <div class="universal-import-chips">
                <q-chip v-for="label in keyLabels" :key="label" dense color="blue-1" text-color="blue-9">{{ label }}</q-chip>
              </div>
            </div>
            <div>
              <strong>Пример строки</strong>
              <dl class="universal-import-example">
                <template v-for="([column, value]) in exampleEntries" :key="column">
                  <dt>{{ column }}</dt>
                  <dd>{{ value }}</dd>
                </template>
              </dl>
            </div>
          </div>
        </AppCard>

        <AppCard v-if="store.currentJob && !isFisJob" title="2. Сопоставление колонок" subtitle="Проверьте, какие колонки файла соответствуют полям CollegePortal.">
          <div class="universal-import-mapping">
            <div v-for="field in fieldOptions" :key="field.value" class="universal-import-mapping__row">
              <span>{{ field.label }}</span>
              <q-select v-model="mapping[field.value]" dense outlined clearable emit-value map-options :options="headerOptions" label="Колонка файла" />
            </div>
          </div>
          <div class="universal-import-actions">
            <q-btn v-if="canManage" outline color="primary" :disable="!canConfirm" :loading="store.saving" @click="handleValidate"><Wand2 :size="16" class="q-mr-xs" /> Проверить</q-btn>
            <q-btn v-if="canManage" color="primary" :disable="!canConfirm" :loading="store.saving" @click="handleConfirm"><CheckCircle2 :size="16" class="q-mr-xs" /> Подтвердить импорт</q-btn>
          </div>
        </AppCard>

        <AppCard v-if="store.currentJob && !isFisJob" title="3. Предварительный просмотр" :subtitle="`Строк в файле: ${store.currentJob.total_rows || 0}`">
          <div v-if="store.currentJob.preview_rows?.length" class="import-preview-scroll"><AppTable :rows="store.currentJob.preview_rows" :columns="previewColumns" :pagination="{ rowsPerPage: 5 }" :rows-per-page-options="[5, 10, 20, 0]" /></div>
          <q-banner v-else rounded class="universal-import-hint">В файле не найдено строк для предварительного просмотра.</q-banner>
        </AppCard>
      </section>

      <aside class="universal-import-side">
        <AppCard title="Отчет импорта" subtitle="Результат появится после проверки или подтверждения.">
          <div class="universal-import-report">
            <div><span>Всего строк</span><strong>{{ fisResult?.total_rows || store.currentJob?.total_rows || 0 }}</strong></div>
            <div><span>Создано</span><strong>{{ fisResult?.created_count ?? store.currentJob?.created_count ?? result?.created ?? 0 }}</strong></div>
            <div><span>Обновлено</span><strong>{{ fisResult?.updated_count ?? store.currentJob?.updated_count ?? result?.updated ?? 0 }}</strong></div>
            <div><span>Пропущено</span><strong>{{ store.currentJob?.skipped_count || result?.skipped || 0 }}</strong></div>
            <div><span>Ошибок</span><strong>{{ fisResult?.critical_errors ?? store.currentJob?.error_count ?? 0 }}</strong></div>
          </div>
          <div v-if="store.currentJob?.status" class="q-mt-md"><AppStatusBadge :label="statusLabel(store.currentJob.status)" :tone="statusTone(store.currentJob.status)" /></div>
        </AppCard>

        <AppCard v-if="errors.length" title="Ошибки проверки" subtitle="Показаны первые ошибки. Исправьте файл или сопоставление колонок.">
          <div class="universal-import-errors">
            <article v-for="(error, index) in errors.slice(0, 8)" :key="`${error.row}-${error.field || index}`">
              <strong>Строка {{ error.row }} · {{ error.column || 'Колонка не определена' }}</strong>
              <span>{{ error.reason || error.errors?.join('; ') }}</span>
              <small v-if="error.value !== null && error.value !== undefined && error.value !== ''">Исходное значение: {{ error.value }}</small>
            </article>
          </div>
        </AppCard>

        <AppCard title="История импортов" subtitle="Последние загрузки реальных данных.">
          <div class="universal-import-history-title"><History :size="16" /> {{ typeLabel(dataType) }}</div>
          <AppTable v-if="store.history.length" :rows="store.history" :columns="historyColumns" :pagination="{ rowsPerPage: 6 }" :rows-per-page-options="[6, 12, 0]">
            <template #body-cell-created_at="props"><q-td :props="props">{{ formatDate(props.row.created_at) }}</q-td></template>
            <template #body-cell-data_type="props"><q-td :props="props">{{ typeLabel(props.row.data_type) }}</q-td></template>
            <template #body-cell-status="props"><q-td :props="props"><AppStatusBadge :label="statusLabel(props.row.status)" :tone="statusTone(props.row.status)" /></q-td></template>
            <template #body-cell-result="props"><q-td :props="props">{{ reportText(props.row) }}</q-td></template>
          </AppTable>
          <q-banner v-else rounded class="universal-import-hint">Истории импортов пока нет.</q-banner>
        </AppCard>
      </aside>
    </div>
  </AppPage>
</template>


<style scoped>

.fis-import-controls {
  display: grid;
  grid-template-columns: minmax(260px, 1fr) auto auto auto;
  gap: 10px;
  align-items: center;
}

.fis-import-steps {
  margin-top: 12px;
  background: transparent;
}

.fis-import-report {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
  gap: 10px;
  margin-top: 12px;
}

.fis-import-report div {
  display: grid;
  gap: 4px;
  padding: 10px;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  background: #f8fafc;
}

.fis-import-report span {
  color: #64748b;
  font-size: 12px;
}

.fis-import-report strong {
  color: #0f172a;
  font-size: 18px;
}

.universal-import-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 400px;
  gap: 16px;
  align-items: start;
  max-width: 100%;
}

.universal-import-main,
.universal-import-side {
  display: flex;
  flex-direction: column;
  gap: 16px;
  min-width: 0;
}

.universal-import-main > *,
.universal-import-side > * {
  min-width: 0;
  align-self: stretch;
}

.universal-import-side {
  align-self: start;
}


.import-preview-scroll {
  max-height: clamp(350px, 42vh, 450px);
  overflow: auto;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  background: #ffffff;
}

.import-preview-scroll--fis {
  max-height: clamp(360px, 44vh, 450px);
}

.import-preview-scroll :deep(.q-table__container),
.import-preview-scroll :deep(.q-table__middle) {
  max-height: inherit;
}

.import-preview-scroll :deep(.q-table__middle) {
  overflow: auto;
}

.import-preview-scroll :deep(table) {
  min-width: max-content;
}

.import-preview-scroll :deep(.q-table__bottom) {
  position: sticky;
  bottom: 0;
  z-index: 1;
  background: #ffffff;
}

.universal-import-controls {
  display: grid;
  grid-template-columns: minmax(170px, 220px) minmax(220px, 280px) minmax(240px, 1fr) auto auto;
  gap: 10px;
  align-items: center;
}

.universal-import-hint {
  margin-top: 12px;
  background: #f8fafc;
  color: #475569;
}

.universal-import-reference {
  display: grid;
  gap: 14px;
  margin-top: 14px;
  padding: 14px;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  background: #f8fafc;
}

.universal-import-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 8px;
}

.universal-import-example {
  display: grid;
  grid-template-columns: minmax(120px, 180px) 1fr;
  gap: 6px 12px;
  margin: 8px 0 0;
  font-size: 13px;
}

.universal-import-example dt {
  color: #64748b;
}

.universal-import-example dd {
  margin: 0;
  color: #0f172a;
  overflow-wrap: anywhere;
}

.universal-import-mapping {
  display: grid;
  gap: 10px;
}

.universal-import-mapping__row {
  display: grid;
  grid-template-columns: minmax(160px, 220px) minmax(220px, 1fr);
  gap: 12px;
  align-items: center;
}

.universal-import-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-top: 14px;
}

.universal-import-report {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
}

.universal-import-report div {
  display: grid;
  gap: 4px;
  padding: 10px;
  border-radius: 8px;
  background: #f8fafc;
}

.universal-import-report span {
  color: #64748b;
  font-size: 12px;
}

.universal-import-report strong {
  color: #0f172a;
  font-size: 20px;
}

.universal-import-errors {
  display: grid;
  gap: 10px;
}

.universal-import-errors article {
  display: grid;
  gap: 4px;
  padding: 10px;
  border-left: 3px solid #f59e0b;
  border-radius: 6px;
  background: #fffbeb;
}

.universal-import-errors span {
  color: #92400e;
}

.universal-import-errors small {
  color: #64748b;
}

.universal-import-history-title {
  display: flex;
  gap: 8px;
  align-items: center;
  margin-bottom: 10px;
  color: #475569;
  font-weight: 600;
}

@media (max-width: 1439px) {
  .universal-import-layout {
    grid-template-columns: minmax(0, 1fr);
  }

  .fis-import-controls {
    grid-template-columns: minmax(0, 1fr) auto auto;
  }

  .fis-import-controls .q-btn:last-child {
    grid-column: 1 / -1;
    justify-self: start;
  }

  .universal-import-controls {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 767px) {
  .fis-import-controls,
  .universal-import-controls,
  .universal-import-mapping__row {
    grid-template-columns: minmax(0, 1fr);
  }

  .fis-import-report,
  .universal-import-report {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
