<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useQuasar } from 'quasar'
import { CheckCircle2, FileSpreadsheet, History, RefreshCw, Upload, Wand2 } from '@lucide/vue'
import AppPage from '../../components/ui/AppPage.vue'
import PageHeader from '../../components/ui/PageHeader.vue'
import AppToolbar from '../../components/ui/AppToolbar.vue'
import AppCard from '../../components/ui/AppCard.vue'
import AppErrorBanner from '../../components/ui/AppErrorBanner.vue'
import AppLoading from '../../components/ui/AppLoading.vue'
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'
import AppTable from '../../components/ui/AppTable.vue'
import { useUniversalImportStore } from '../../stores/universalImport'

const store = useUniversalImportStore()
const $q = useQuasar()
const dataType = ref('students')
const mode = ref('skip_duplicates')
const file = ref(null)
const mapping = reactive({})
const previewColumns = computed(() => (store.currentJob?.headers || []).map((header) => ({ name: header, label: header, field: header, align: 'left' })))
const historyColumns = [
  { name: 'created_at', label: 'Дата', field: 'created_at', align: 'left' },
  { name: 'data_type', label: 'Тип', field: 'data_type', align: 'left' },
  { name: 'file', label: 'Файл', field: 'original_filename', align: 'left' },
  { name: 'status', label: 'Статус', field: 'status', align: 'left' },
  { name: 'result', label: 'Результат', field: 'result', align: 'left' },
]
const fieldOptions = computed(() => (store.selectedTypeConfig?.fields || []).map((field) => ({ label: field.label + (field.required ? ' *' : ''), value: field.value, required: field.required })))
const headerOptions = computed(() => (store.currentJob?.headers || []).map((header) => ({ label: header, value: header })))
const result = computed(() => store.currentJob?.result || null)
const errors = computed(() => store.currentJob?.validation_errors || [])
const canPreview = computed(() => Boolean(dataType.value && file.value))
const canConfirm = computed(() => Boolean(store.currentJob?.id && Object.keys(mapping).length))

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
async function handlePreview() {
  await store.preview(dataType.value, file.value)
  syncMappingFromJob()
  $q.notify({ type: 'positive', message: 'Предварительный просмотр подготовлен', position: 'top-right' })
}
async function handleValidate() {
  await store.validate({ ...mapping }, mode.value)
  syncMappingFromJob()
  $q.notify({ type: errors.value.length ? 'warning' : 'positive', message: errors.value.length ? 'Найдены ошибки проверки' : 'Проверка прошла без ошибок', position: 'top-right' })
}
async function handleConfirm() {
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
        <AppCard title="1. Файл и тип данных" subtitle="Выберите раздел, файл CSV/XLSX и режим обработки дублей.">
          <div class="universal-import-controls">
            <q-select v-model="dataType" outlined dense emit-value map-options label="Тип данных" :options="store.typeOptions" />
            <q-select v-model="mode" outlined dense emit-value map-options label="Режим" :options="store.modeOptions" option-value="value" option-label="label" />
            <q-file v-model="file" outlined dense accept=".csv,.txt,.xlsx" label="CSV или XLSX"><template #prepend><Upload :size="16" /></template></q-file>
            <q-btn color="primary" :disable="!canPreview" :loading="store.saving" @click="handlePreview"><FileSpreadsheet :size="16" class="q-mr-xs" /> Предпросмотр</q-btn>
          </div>
          <q-banner rounded class="universal-import-hint">Поддерживаются студенты, группы, преподаватели, дисциплины, аудитории и абитуриенты. Импорт выполняется только после подтверждения.</q-banner>
        </AppCard>

        <AppCard v-if="store.currentJob" title="2. Сопоставление колонок" subtitle="Проверьте, какие колонки файла соответствуют полям CollegePortal.">
          <div class="universal-import-mapping">
            <div v-for="field in fieldOptions" :key="field.value" class="universal-import-mapping__row">
              <span>{{ field.label }}</span>
              <q-select v-model="mapping[field.value]" dense outlined clearable emit-value map-options :options="headerOptions" label="Колонка файла" />
            </div>
          </div>
          <div class="universal-import-actions">
            <q-btn outline color="primary" :disable="!canConfirm" :loading="store.saving" @click="handleValidate"><Wand2 :size="16" class="q-mr-xs" /> Проверить</q-btn>
            <q-btn color="primary" :disable="!canConfirm" :loading="store.saving" @click="handleConfirm"><CheckCircle2 :size="16" class="q-mr-xs" /> Подтвердить импорт</q-btn>
          </div>
        </AppCard>

        <AppCard v-if="store.currentJob" title="3. Предварительный просмотр" :subtitle="`Строк в файле: ${store.currentJob.total_rows || 0}`">
          <AppTable v-if="store.currentJob.preview_rows?.length" :rows="store.currentJob.preview_rows" :columns="previewColumns" :pagination="{ rowsPerPage: 5 }" :rows-per-page-options="[5, 10, 20, 0]" />
          <q-banner v-else rounded class="universal-import-hint">В файле не найдено строк для предварительного просмотра.</q-banner>
        </AppCard>
      </section>

      <aside class="universal-import-side">
        <AppCard title="Отчет импорта" subtitle="Результат появится после проверки или подтверждения.">
          <div class="universal-import-report">
            <div><span>Всего строк</span><strong>{{ store.currentJob?.total_rows || 0 }}</strong></div>
            <div><span>Создано</span><strong>{{ store.currentJob?.created_count || result?.created || 0 }}</strong></div>
            <div><span>Обновлено</span><strong>{{ store.currentJob?.updated_count || result?.updated || 0 }}</strong></div>
            <div><span>Пропущено</span><strong>{{ store.currentJob?.skipped_count || result?.skipped || 0 }}</strong></div>
            <div><span>Ошибок</span><strong>{{ store.currentJob?.error_count || 0 }}</strong></div>
          </div>
          <div v-if="store.currentJob?.status" class="q-mt-md"><AppStatusBadge :label="statusLabel(store.currentJob.status)" :tone="statusTone(store.currentJob.status)" /></div>
        </AppCard>

        <AppCard v-if="errors.length" title="Ошибки проверки" subtitle="Показаны первые ошибки. Исправьте файл или сопоставление колонок.">
          <div class="universal-import-errors">
            <article v-for="error in errors.slice(0, 8)" :key="error.row">
              <strong>Строка {{ error.row }}</strong>
              <span>{{ error.errors.join('; ') }}</span>
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
