import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'

const initialFilters = { package_type: '', year: '', status: '', education_program_id: '' }
export const FIS_TYPE_OPTIONS = [{ label: 'ФИС Приема', value: 'admission' }, { label: 'ФИС ГИА', value: 'gia' }]
export const FIS_STATUS_OPTIONS = [
  { label: 'Черновик', value: 'draft', tone: 'neutral' }, { label: 'Ошибки проверки', value: 'validation_failed', tone: 'danger' }, { label: 'Готов', value: 'ready', tone: 'success' }, { label: 'Выгружен', value: 'exported', tone: 'info' }, { label: 'Архив', value: 'archived', tone: 'warning' },
]
export const FIS_RECORD_STATUS_OPTIONS = [{ label: 'Черновик', value: 'draft', tone: 'neutral' }, { label: 'Корректно', value: 'valid', tone: 'success' }, { label: 'Ошибка', value: 'invalid', tone: 'danger' }, { label: 'Выгружено', value: 'exported', tone: 'info' }]
function extractRows(payload) { return Array.isArray(payload?.data) ? payload.data : [] }
export function optionLabel(options, value) { return options.find((item) => item.value === value)?.label || value || '—' }
export function statusTone(options, value) { return options.find((item) => item.value === value)?.tone || 'neutral' }
export function formatRuDateTime(value) { if (!value) return '—'; const date = new Date(value); return Number.isNaN(date.getTime()) ? value : date.toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) }
export const useFisStore = defineStore('fis', () => {
  const packages = ref([]), outboundPackages = ref([]), specInfo = ref(null), gatewayStatus = ref({ health: null, version: null, zkspd: null, dictionaries: null, institution: null, lastCheckAt: null }), programs = ref([]), applications = ref([]), exams = ref([]), graduates = ref([])
  const dictionaryIntake = ref(null)
  const filters = ref({ ...initialFilters })
  const selectedId = ref(null), loading = ref(false), saving = ref(false), error = ref('')
  const selectedPackage = computed(() => packages.value.find((item) => Number(item.id) === Number(selectedId.value)) || null)
  const records = computed(() => selectedPackage.value?.records || [])
  const errors = computed(() => selectedPackage.value?.validation_errors || [])
  const outboundSummary = computed(() => ({ total: outboundPackages.value.length, ready: outboundPackages.value.filter((item) => ['validated','accepted','completed'].includes(item.status)).length, blocked: !specInfo.value?.xsd_loaded }))
  const filteredPackages = computed(() => packages.value.filter((item) => (!filters.value.package_type || item.package_type === filters.value.package_type) && (!filters.value.year || Number(item.year) === Number(filters.value.year)) && (!filters.value.status || item.status === filters.value.status) && (!filters.value.education_program_id || Number(item.education_program_id) === Number(filters.value.education_program_id))))
  const yearOptions = computed(() => [...new Set([...(packages.value || []).map((item) => item.year), ...(applications.value || []).map((item) => item.submitted_at ? Number(String(item.submitted_at).slice(0, 4)) : null), ...(graduates.value || []).map((item) => item.graduation_year)].filter(Boolean))].sort((a, b) => b - a).map((year) => ({ label: String(year), value: year })))
  const programOptions = computed(() => programs.value.map((program) => ({ label: program.name, value: program.id })))
  async function load() { loading.value = true; error.value = ''; try { const [packagesPayload, outboundPayload, specPayload, programsPayload, applicationsPayload, examsPayload, graduatesPayload] = await Promise.all([api.list('fis-packages'), api.list('fis/outbound/packages'), api.list('fis/outbound/spec-info'), api.list('education-programs'), api.list('applicant-applications'), api.list('exams'), api.list('graduates')]); packages.value = extractRows(packagesPayload); outboundPackages.value = extractRows(outboundPayload); specInfo.value = specPayload || null; programs.value = extractRows(programsPayload); applications.value = extractRows(applicationsPayload); exams.value = extractRows(examsPayload); graduates.value = extractRows(graduatesPayload); if (selectedId.value && !selectedPackage.value) selectedId.value = null } catch (err) { error.value = err.message || 'Не удалось загрузить пакеты ФИС' } finally { loading.value = false } }
  async function createPackage(payload) { saving.value = true; error.value = ''; try { const response = await api.create('fis-packages', { name: payload.name?.trim() || '', package_type: payload.package_type || 'admission', year: Number(payload.year), education_program_id: payload.education_program_id ? Number(payload.education_program_id) : null, note: payload.note?.trim() || '' }); await load(); selectedId.value = response?.data?.id || selectedId.value; return response?.data || null } catch (err) { error.value = err.message || 'Не удалось создать пакет ФИС'; throw err } finally { saving.value = false } }
  async function validatePackage() { if (!selectedId.value) return null; saving.value = true; error.value = ''; try { const response = await api.create(`fis-packages/${selectedId.value}/validate`, {}); await load(); selectedId.value = response?.data?.id || selectedId.value; return response?.data || null } catch (err) { error.value = err.message || 'Не удалось проверить пакет'; throw err } finally { saving.value = false } }
  async function markExported() { if (!selectedId.value) return null; saving.value = true; error.value = ''; try { const response = await api.create(`fis-packages/${selectedId.value}/mark-exported`, {}); await load(); return response?.data || null } catch (err) { error.value = err.message || 'Не удалось отметить пакет выгруженным'; throw err } finally { saving.value = false } }
  async function archive() { if (!selectedId.value) return null; saving.value = true; error.value = ''; try { const response = await api.create(`fis-packages/${selectedId.value}/archive`, {}); await load(); return response?.data || null } catch (err) { error.value = err.message || 'Не удалось архивировать пакет'; throw err } finally { saving.value = false } }
  async function exportCsv() { if (!selectedId.value) return; const blob = await api.download(`/fis-packages/${selectedId.value}/export.csv`); const url = window.URL.createObjectURL(blob); const link = document.createElement('a'); link.href = url; link.download = `fis-package-${selectedId.value}.csv`; link.click(); window.URL.revokeObjectURL(url) }
  async function exportJson() { if (!selectedId.value) return; const response = await api.authFetch(`${api.baseUrl}/fis-packages/${selectedId.value}/export.json`, { headers: { Accept: 'application/json' } }); const blob = new Blob([JSON.stringify(await response.json(), null, 2)], { type: 'application/json' }); const url = window.URL.createObjectURL(blob); const link = document.createElement('a'); link.href = url; link.download = `fis-package-${selectedId.value}.json`; link.click(); window.URL.revokeObjectURL(url) }

  async function gatewayGet(kind, resource) { saving.value = true; error.value = ''; try { const response = await api.list(resource); gatewayStatus.value = { ...gatewayStatus.value, [kind]: response?.data || response, lastCheckAt: new Date().toISOString() }; return response?.data || response } catch (err) { gatewayStatus.value = { ...gatewayStatus.value, [kind]: { ok: false, message: err.message || 'Проверка не выполнена' }, lastCheckAt: new Date().toISOString() }; error.value = err.message || 'Проверка шлюза не выполнена'; return gatewayStatus.value[kind] } finally { saving.value = false } }
  async function gatewayPost(kind, resource, payload = {}) { saving.value = true; error.value = ''; try { const response = await api.create(resource, payload); gatewayStatus.value = { ...gatewayStatus.value, [kind]: response?.data || response, lastCheckAt: new Date().toISOString() }; return response?.data || response } catch (err) { gatewayStatus.value = { ...gatewayStatus.value, [kind]: { ok: false, message: err.message || 'Проверка не выполнена' }, lastCheckAt: new Date().toISOString() }; error.value = err.message || 'Проверка шлюза не выполнена'; return gatewayStatus.value[kind] } finally { saving.value = false } }
  async function checkGatewayHealth() { return gatewayGet('health', 'fis/outbound/gateway/health') }
  async function checkGatewayVersion() { return gatewayGet('version', 'fis/outbound/gateway/version') }
  async function checkZkspd() { return gatewayPost('zkspd', 'fis/outbound/gateway/zkspd-check') }
  async function loadGatewayDictionaries() { return gatewayPost('dictionaries', 'fis/outbound/gateway/dictionaries/list') }
  async function loadGatewayInstitution() { return gatewayPost('institution', 'fis/outbound/gateway/institution/info') }
  // Загрузка справочников ФИС. Разбор и применение разделены: сначала оператор
  // видит, что приедет в справочники портала, и только потом соглашается.
  async function sendDictionaryFile(resource, file, extra = {}) {
    saving.value = true; error.value = ''
    try {
      const form = new FormData()
      form.append('file', file)
      Object.entries(extra).forEach(([key, value]) => { if (value) form.append(key, value) })
      const response = await api.upload(`/fis/dictionaries/${resource}`, form)
      dictionaryIntake.value = { ...(response?.data || {}), stage: resource, at: new Date().toISOString() }
      return dictionaryIntake.value
    } catch (err) {
      error.value = err.message || 'Справочник ФИС загрузить не удалось'
      throw err
    } finally { saving.value = false }
  }
  async function previewDictionary(file, catalog = '') { return sendDictionaryFile('preview', file, { catalog }) }
  async function applyDictionary(file, catalog = '', dictionary = '') { const result = await sendDictionaryFile('apply', file, { catalog, dictionary }); await load(); return result }
  function clearDictionaryIntake() { dictionaryIntake.value = null }
  function setFilters(next) { filters.value = { ...filters.value, ...next } }
  function resetFilters() { filters.value = { ...initialFilters } }
  function select(item) { selectedId.value = item?.id || null }
  function selectById(id) { selectedId.value = id || null }
  return { packages, outboundPackages, outboundSummary, specInfo, gatewayStatus, dictionaryIntake, filteredPackages, programs, applications, exams, graduates, filters, selectedId, selectedPackage, records, errors, loading, saving, error, yearOptions, programOptions, load, createPackage, validatePackage, markExported, archive, exportCsv, exportJson, checkGatewayHealth, checkGatewayVersion, checkZkspd, loadGatewayDictionaries, loadGatewayInstitution, previewDictionary, applyDictionary, clearDictionaryIntake, setFilters, resetFilters, select, selectById }
})
