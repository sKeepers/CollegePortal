<script setup>
import { computed, onMounted, ref } from 'vue'
import { Activity, AlertTriangle, CheckCircle2, RefreshCw, ShieldAlert } from '@lucide/vue'
import AppPage from '../../components/ui/AppPage.vue'
import PageHeader from '../../components/ui/PageHeader.vue'
import AppErrorBanner from '../../components/ui/AppErrorBanner.vue'
import AppLoading from '../../components/ui/AppLoading.vue'
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'
import { api } from '../../services/api'
import { formatRuDateTime } from '../../stores/fis'

const diagnostics = ref(null)
const logs = ref([])
const loading = ref(false)
const error = ref('')

const checkLabels = {
  portal: 'CollegePortal',
  production_guard: 'Production guard',
  gateway_target: 'Адрес Gateway',
  gateway_host: 'Хост Gateway',
  gateway_port: 'TCP 8099',
  gateway_service: 'Windows-служба Gateway',
  gateway_health: 'Gateway /health',
  gateway_version: 'Gateway /version',
  gateway_adapters: 'Gateway /adapters',
  gateway_adapter: 'Адаптер ФИС',
  zkspd: 'ViPNet / ЗКСПД',
  fis_test_direct: 'DEV → TEST 10.0.3.1:8383',
  soap: 'SOAP-контракт',
  auth: 'Аутентификация',
  dictionary: 'Read-only методы',
  read_only: 'Первый read-only вызов',
}

const checks = computed(() => Object.entries(diagnostics.value?.checks || {}).map(([code, item]) => ({ code, label: checkLabels[code] || code, ...item })))
const operations = computed(() => diagnostics.value?.contract?.operations || [])
const blockers = computed(() => diagnostics.value?.blockers || [])
const contractFiles = computed(() => diagnostics.value?.registry?.files || [])
const contractCounts = computed(() => diagnostics.value?.registry?.counts || {})
const contractColumns = [
  { name: 'path', label: 'Файл', field: 'path', align: 'left' },
  { name: 'type', label: 'Тип', field: 'type', align: 'left' },
  { name: 'size_bytes', label: 'Размер', field: 'size_bytes', align: 'right' },
  { name: 'sha256', label: 'SHA-256', field: 'sha256', align: 'left' },
  { name: 'active', label: 'Активен', field: 'active', align: 'center' },
  { name: 'manifest_match', label: 'Manifest', field: 'manifest_match', align: 'center' },
]
const logColumns = [
  { name: 'occurred_at', label: 'Время', field: 'occurred_at', align: 'left' },
  { name: 'method', label: 'Метод', field: 'method', align: 'left' },
  { name: 'request_id', label: 'Request ID', field: 'request_id', align: 'left' },
  { name: 'duration_ms', label: 'мс', field: 'duration_ms', align: 'right' },
  { name: 'http_code', label: 'HTTP', field: 'http_code', align: 'right' },
  { name: 'status', label: 'Статус', field: 'status', align: 'left' },
  { name: 'error_code', label: 'Ошибка', field: 'error_code', align: 'left' },
]

function tone(status) {
  if (['ok', 'confirmed', 'configured', 'running', 'contract_verified', 'loaded'].includes(status)) return 'success'
  if (['failed', 'invalid'].includes(status)) return 'danger'
  if (['blocked', 'missing', 'unknown'].includes(status)) return 'warning'
  return 'info'
}

function formatBytes(value) {
  if (!Number.isFinite(Number(value))) return '—'
  return new Intl.NumberFormat('ru-RU').format(Number(value)) + ' Б'
}

async function load(run = false) {
  loading.value = true
  error.value = ''
  try {
    const [diagnosticsPayload, logsPayload] = await Promise.all([
      run ? api.create('fis/diagnostics/run', {}) : api.list('fis/diagnostics'),
      api.list('fis/communication-logs', { per_page: 50 }),
    ])
    diagnostics.value = diagnosticsPayload?.data || diagnosticsPayload
    logs.value = Array.isArray(logsPayload?.data) ? logsPayload.data : []
  } catch (exception) {
    error.value = exception.message || 'Диагностику ФИС выполнить не удалось'
  } finally {
    loading.value = false
  }
}

onMounted(() => load(false))
</script>

<template>
  <AppPage>
    <PageHeader title="Диагностика ФИС" subtitle="Portal → Gateway → ViPNet → TEST ФИС. Import и production не вызываются.">
      <template #actions>
        <q-btn color="primary" :loading="loading" @click="load(true)"><Activity :size="16" class="q-mr-xs" /> Запустить диагностику</q-btn>
        <q-btn flat to="/fis" class="q-ml-sm">Вернуться в ФИС</q-btn>
      </template>
    </PageHeader>

    <AppErrorBanner :message="error" />
    <AppLoading v-if="loading && !diagnostics" label="Проверка интеграционного контура..." />

    <q-banner v-if="diagnostics" :class="diagnostics.stop_gate ? 'diagnostics-banner diagnostics-banner--blocked' : 'diagnostics-banner diagnostics-banner--ready'">
      <template #avatar><ShieldAlert v-if="diagnostics.stop_gate" :size="24" /><CheckCircle2 v-else :size="24" /></template>
      <strong>{{ diagnostics.stop_gate ? 'Действует strict stop-gate' : 'Контур готов к разрешенному read-only вызову' }}</strong>
      <div>{{ diagnostics.stop_gate ? 'SOAP-вызовы, Import и production заблокированы. Показаны только наблюдаемые факты.' : 'Контракт и транспорт подтверждены; Import по-прежнему отключен.' }}</div>
      <div class="text-caption">Проверено: {{ formatRuDateTime(diagnostics.checked_at) }} · Окружение: TEST</div>
    </q-banner>

    <section v-if="blockers.length" class="diagnostics-blockers" aria-label="Блокеры обмена ФИС">
      <div class="diagnostics-section__header"><div><h2>Точные блокеры</h2><p>Первый read-only SOAP-вызов не выполняется, пока список не закрыт.</p></div></div>
      <ul><li v-for="blocker in blockers" :key="blocker"><code>{{ blocker }}</code></li></ul>
    </section>

    <section v-if="diagnostics" class="diagnostics-grid" aria-label="Состояние интеграции ФИС">
      <article v-for="check in checks" :key="check.code" class="diagnostics-check">
        <div class="diagnostics-check__header"><span>{{ check.label }}</span><AppStatusBadge :label="check.status" :tone="tone(check.status)" /></div>
        <p>{{ check.message }}</p>
        <dl v-if="check.details && Object.keys(check.details).length" class="diagnostics-details">
          <template v-for="(value, key) in check.details" :key="key"><dt>{{ key }}</dt><dd>{{ value ?? '—' }}</dd></template>
        </dl>
      </article>
    </section>

    <section v-if="diagnostics" class="diagnostics-section">
      <div class="diagnostics-section__header"><div><h2>Private registry контрактов</h2><p>Показываются только относительные пути и контрольные суммы. Файлы не публикуются в Git.</p></div><q-btn flat round :loading="loading" title="Обновить" @click="load(false)"><RefreshCw :size="18" /></q-btn></div>
      <div class="contract-summary">
        <span>WSDL: <strong>{{ contractCounts.wsdl || 0 }}</strong></span>
        <span>XSD: <strong>{{ contractCounts.xsd || 0 }}</strong></span>
        <span>DISCO: <strong>{{ contractCounts.disco || 0 }}</strong></span>
        <span>Manifest: <strong>{{ diagnostics.registry?.manifest?.status || 'missing' }}</strong></span>
        <span>Bundle verified: <strong>{{ diagnostics.registry?.bundle?.verified ? 'да' : 'нет' }}</strong></span>
      </div>
      <q-table v-if="contractFiles.length" flat bordered dense row-key="path" :rows="contractFiles" :columns="contractColumns" :pagination="{ rowsPerPage: 0 }" hide-pagination>
        <template #body-cell-size_bytes="props"><q-td :props="props">{{ formatBytes(props.row.size_bytes) }}</q-td></template>
        <template #body-cell-sha256="props"><q-td :props="props"><code>{{ props.row.sha256 }}</code></q-td></template>
        <template #body-cell-active="props"><q-td :props="props">{{ props.row.active ? 'Да' : 'Нет' }}</q-td></template>
        <template #body-cell-manifest_match="props"><q-td :props="props"><AppStatusBadge :label="props.row.manifest_match ? 'совпадает' : 'не подтвержден'" :tone="props.row.manifest_match ? 'success' : 'warning'" /></q-td></template>
      </q-table>
      <div v-else class="contract-empty"><AlertTriangle :size="20" /><span>В private registry не найдено WSDL, XSD или DISCO.</span></div>
    </section>

    <section v-if="diagnostics" class="diagnostics-section">
      <div class="diagnostics-section__header"><div><h2>Разбор SOAP-контракта</h2><p>Методы появляются только после проверки официального WSDL. Предположительные значения не используются.</p></div></div>
      <div class="contract-summary">
        <span>WSDL: <strong>{{ diagnostics.contract?.wsdl?.status || 'missing' }}</strong></span>
        <span>XSD: <strong>{{ diagnostics.contract?.xsd?.status || 'missing' }}</strong></span>
        <span>DISCO: <strong>{{ diagnostics.contract?.disco?.status || 'missing' }}</strong></span>
        <span>SOAP: <strong>{{ diagnostics.contract?.soap_versions?.join(', ') || 'не определен' }}</strong></span>
        <span>Методов: <strong>{{ operations.length }}</strong></span>
      </div>
      <q-table v-if="operations.length" flat bordered dense row-key="name" :rows="operations" :columns="[
        { name: 'name', label: 'Метод', field: 'name', align: 'left' },
        { name: 'input', label: 'Request', field: 'input_message', align: 'left' },
        { name: 'output', label: 'Response', field: 'output_message', align: 'left' },
      ]" :pagination="{ rowsPerPage: 0 }" hide-pagination />
      <div v-else class="contract-empty"><AlertTriangle :size="20" /><span>WSDL/DISCO отсутствуют. SOAP Action, binding, authentication и список methods не подтверждены.</span></div>
    </section>

    <section class="diagnostics-section">
      <div class="diagnostics-section__header"><div><h2>FIS Communication Log</h2><p>Хранятся только технические метаданные. SOAP payload, fault text и персональные данные не записываются.</p></div></div>
      <q-table flat bordered dense row-key="id" :rows="logs" :columns="logColumns" :pagination="{ rowsPerPage: 20 }">
        <template #body-cell-occurred_at="props"><q-td :props="props">{{ formatRuDateTime(props.row.occurred_at) }}</q-td></template>
        <template #body-cell-request_id="props"><q-td :props="props"><code>{{ props.row.request_id || '—' }}</code></q-td></template>
        <template #body-cell-status="props"><q-td :props="props"><AppStatusBadge :label="props.row.status" :tone="tone(props.row.status)" /></q-td></template>
      </q-table>
    </section>
  </AppPage>
</template>

<style scoped>
.diagnostics-banner { margin: 12px 0; border: 1px solid; border-radius: 6px; }
.diagnostics-banner--blocked { color: #7c2d12; background: #fff7ed; border-color: #fdba74; }
.diagnostics-banner--ready { color: #14532d; background: #f0fdf4; border-color: #86efac; }
.diagnostics-blockers { margin-top: 12px; padding: 14px 16px; color: #7c2d12; background: #fff7ed; border: 1px solid #fdba74; border-radius: 6px; }
.diagnostics-blockers ul { display: flex; flex-wrap: wrap; gap: 6px 18px; margin: 8px 0 0; padding-left: 20px; }
.diagnostics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 10px; margin: 12px 0; }
.diagnostics-check { min-height: 112px; padding: 12px; border: 1px solid #dbe3ec; border-radius: 6px; background: #fff; }
.diagnostics-check__header { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; font-weight: 700; }
.diagnostics-check p { margin: 10px 0 0; color: #526173; line-height: 1.45; }
.diagnostics-details { display: grid; grid-template-columns: minmax(88px, auto) 1fr; gap: 3px 8px; margin: 10px 0 0; font-size: 12px; }
.diagnostics-details dt { color: #64748b; }
.diagnostics-details dd { min-width: 0; margin: 0; overflow-wrap: anywhere; }
.diagnostics-section { margin-top: 12px; padding: 16px 0; border-top: 1px solid #dbe3ec; }
.diagnostics-section__header { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 12px; }
.diagnostics-section h2, .diagnostics-blockers h2 { margin: 0; font-size: 18px; }
.diagnostics-section p, .diagnostics-blockers p { margin: 4px 0 0; color: #64748b; }
.contract-summary { display: flex; flex-wrap: wrap; gap: 8px 20px; margin-bottom: 12px; }
.contract-empty { display: flex; align-items: center; gap: 8px; min-height: 72px; padding: 12px; color: #92400e; background: #fffbeb; border: 1px solid #fde68a; border-radius: 6px; }
code { font-size: 12px; word-break: break-all; }
@media (max-width: 700px) { .diagnostics-grid { grid-template-columns: 1fr; } }
</style>
