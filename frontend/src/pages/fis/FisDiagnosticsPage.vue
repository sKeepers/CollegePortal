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
  gateway: 'Gateway',
  gateway_version: 'Версия Gateway',
  gateway_capabilities: 'Capabilities',
  gateway_adapter: 'Адаптер ФИС',
  zkspd: 'ЗКСПД',
  test_endpoint: 'TEST endpoint',
  soap: 'SOAP',
  tls: 'TLS / транспорт',
  auth: 'Аутентификация',
  dictionary: 'Справочники',
  read_only: 'Read-only запрос',
}

const checks = computed(() => Object.entries(diagnostics.value?.checks || {}).map(([code, item]) => ({ code, label: checkLabels[code] || code, ...item })))
const operations = computed(() => diagnostics.value?.contract?.operations || [])
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
  if (['ok', 'confirmed', 'configured', 'gateway_hmac_configured', 'loaded'].includes(status)) return 'success'
  if (['failed', 'invalid'].includes(status)) return 'danger'
  if (['blocked', 'missing'].includes(status)) return 'warning'
  return 'info'
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
    <PageHeader title="Диагностика ФИС" subtitle="Проверка цепочки Portal → Gateway → ViPNet → TEST ФИС без выполнения Import.">
      <template #actions>
        <q-btn color="primary" :loading="loading" @click="load(true)"><Activity :size="16" class="q-mr-xs" /> Запустить диагностику</q-btn>
        <q-btn flat to="/fis" class="q-ml-sm">Вернуться в ФИС</q-btn>
      </template>
    </PageHeader>

    <AppErrorBanner :message="error" />
    <AppLoading v-if="loading && !diagnostics" label="Проверка интеграционного контура..." />

    <q-banner v-if="diagnostics" :class="diagnostics.stop_gate ? 'diagnostics-banner diagnostics-banner--blocked' : 'diagnostics-banner diagnostics-banner--ready'">
      <template #avatar><ShieldAlert v-if="diagnostics.stop_gate" :size="24" /><CheckCircle2 v-else :size="24" /></template>
      <strong>{{ diagnostics.stop_gate ? 'Действует stop-gate' : 'Контур готов к read-only проверке' }}</strong>
      <div>{{ diagnostics.stop_gate ? 'Import и production остаются заблокированы. Состояния ниже получены из реальных настроек и проверок.' : 'Контракт и Gateway доступны; Import по-прежнему отключен.' }}</div>
      <div class="text-caption">Проверено: {{ formatRuDateTime(diagnostics.checked_at) }} · Окружение: TEST</div>
    </q-banner>

    <section v-if="diagnostics" class="diagnostics-grid" aria-label="Состояние интеграции ФИС">
      <article v-for="check in checks" :key="check.code" class="diagnostics-check">
        <div class="diagnostics-check__header"><span>{{ check.label }}</span><AppStatusBadge :label="check.status" :tone="tone(check.status)" /></div>
        <p>{{ check.message }}</p>
      </article>
    </section>

    <section v-if="diagnostics" class="diagnostics-section">
      <div class="diagnostics-section__header"><div><h2>Официальный контракт</h2><p>Методы появляются только после разбора загруженного WSDL. Локальные предположения не используются.</p></div><q-btn flat round :loading="loading" title="Обновить" @click="load(false)"><RefreshCw :size="18" /></q-btn></div>
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
      <div v-else class="contract-empty"><AlertTriangle :size="20" /><span>WSDL/DISCO отсутствуют. SOAP Action, binding и список методов не подтверждены.</span></div>
    </section>

    <section class="diagnostics-section">
      <div class="diagnostics-section__header"><div><h2>FIS Communication Log</h2><p>Хранятся только технические метаданные. SOAP payload и персональные данные не записываются.</p></div></div>
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
.diagnostics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 10px; margin: 12px 0; }
.diagnostics-check { min-height: 112px; padding: 12px; border: 1px solid #dbe3ec; border-radius: 6px; background: #fff; }
.diagnostics-check__header { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; font-weight: 700; }
.diagnostics-check p { margin: 10px 0 0; color: #526173; line-height: 1.45; }
.diagnostics-section { margin-top: 12px; padding: 16px 0; border-top: 1px solid #dbe3ec; }
.diagnostics-section__header { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 12px; }
.diagnostics-section h2 { margin: 0; font-size: 18px; }
.diagnostics-section p { margin: 4px 0 0; color: #64748b; }
.contract-summary { display: flex; flex-wrap: wrap; gap: 8px 20px; margin-bottom: 12px; }
.contract-empty { display: flex; align-items: center; gap: 8px; min-height: 72px; padding: 12px; color: #92400e; background: #fffbeb; border: 1px solid #fde68a; border-radius: 6px; }
code { font-size: 12px; word-break: break-all; }
@media (max-width: 700px) { .diagnostics-grid { grid-template-columns: 1fr; } }
</style>
