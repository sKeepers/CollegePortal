import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'

const initialFilters = { graduation_year: '', status: '', education_program_id: '' }
export const FRDO_STATUS_OPTIONS = [
  { label: 'Черновик', value: 'draft', tone: 'neutral' },
  { label: 'Ошибки проверки', value: 'validation_failed', tone: 'danger' },
  { label: 'Готов', value: 'ready', tone: 'success' },
  { label: 'Выгружен', value: 'exported', tone: 'info' },
  { label: 'Архив', value: 'archived', tone: 'warning' },
]
export const FRDO_RECORD_STATUS_OPTIONS = [
  { label: 'Черновик', value: 'draft', tone: 'neutral' },
  { label: 'Корректно', value: 'valid', tone: 'success' },
  { label: 'Ошибка', value: 'invalid', tone: 'danger' },
  { label: 'Выгружено', value: 'exported', tone: 'info' },
]
function extractRows(payload) { return Array.isArray(payload?.data) ? payload.data : [] }
export function statusLabel(options, value) { return options.find((item) => item.value === value)?.label || value || '—' }
export function statusTone(options, value) { return options.find((item) => item.value === value)?.tone || 'neutral' }
export function formatRuDateTime(value) { if (!value) return '—'; const date = new Date(value); return Number.isNaN(date.getTime()) ? value : date.toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) }
export const useFrdoStore = defineStore('frdo', () => {
  const packages = ref([]), programs = ref([]), graduates = ref([])
  const filters = ref({ ...initialFilters })
  const selectedId = ref(null), loading = ref(false), saving = ref(false), error = ref('')
  const selectedPackage = computed(() => packages.value.find((item) => Number(item.id) === Number(selectedId.value)) || null)
  const records = computed(() => selectedPackage.value?.records || [])
  const errors = computed(() => selectedPackage.value?.validation_errors || [])
  const filteredPackages = computed(() => packages.value.filter((item) => (!filters.value.graduation_year || Number(item.graduation_year) === Number(filters.value.graduation_year)) && (!filters.value.status || item.status === filters.value.status) && (!filters.value.education_program_id || Number(item.education_program_id) === Number(filters.value.education_program_id))))
  const graduationYearOptions = computed(() => [...new Set([...(packages.value || []).map((item) => item.graduation_year), ...(graduates.value || []).map((item) => item.graduation_year)].filter(Boolean))].sort((a, b) => b - a).map((year) => ({ label: String(year), value: year })))
  const programOptions = computed(() => programs.value.map((program) => ({ label: program.name, value: program.id })))
  async function load() { loading.value = true; error.value = ''; try { const [packagesPayload, programsPayload, graduatesPayload] = await Promise.all([api.list('frdo-packages'), api.list('education-programs'), api.list('graduates')]); packages.value = extractRows(packagesPayload); programs.value = extractRows(programsPayload); graduates.value = extractRows(graduatesPayload); if (selectedId.value && !selectedPackage.value) selectedId.value = null } catch (err) { error.value = err.message || 'Не удалось загрузить пакеты ФРДО' } finally { loading.value = false } }
  async function createPackage(payload) { saving.value = true; error.value = ''; try { const response = await api.create('frdo-packages', { name: payload.name?.trim() || '', graduation_year: Number(payload.graduation_year), education_program_id: payload.education_program_id ? Number(payload.education_program_id) : null, note: payload.note?.trim() || '' }); await load(); selectedId.value = response?.data?.id || selectedId.value; return response?.data || null } catch (err) { error.value = err.message || 'Не удалось создать пакет ФРДО'; throw err } finally { saving.value = false } }
  async function validatePackage() { if (!selectedId.value) return null; saving.value = true; error.value = ''; try { const response = await api.create(`frdo-packages/${selectedId.value}/validate`, {}); await load(); selectedId.value = response?.data?.id || selectedId.value; return response?.data || null } catch (err) { error.value = err.message || 'Не удалось проверить пакет'; throw err } finally { saving.value = false } }
  async function markExported() { if (!selectedId.value) return null; saving.value = true; error.value = ''; try { const response = await api.create(`frdo-packages/${selectedId.value}/mark-exported`, {}); await load(); return response?.data || null } catch (err) { error.value = err.message || 'Не удалось отметить пакет выгруженным'; throw err } finally { saving.value = false } }
  async function archive() { if (!selectedId.value) return null; saving.value = true; error.value = ''; try { const response = await api.create(`frdo-packages/${selectedId.value}/archive`, {}); await load(); return response?.data || null } catch (err) { error.value = err.message || 'Не удалось архивировать пакет'; throw err } finally { saving.value = false } }
  async function exportCsv() { if (!selectedId.value) return; const blob = await api.download(`/frdo-packages/${selectedId.value}/export.csv`); const url = window.URL.createObjectURL(blob); const link = document.createElement('a'); link.href = url; link.download = `frdo-package-${selectedId.value}.csv`; link.click(); window.URL.revokeObjectURL(url) }
  async function exportJson() { if (!selectedId.value) return; const token = api.token(); const response = await fetch(`${api.baseUrl}/frdo-packages/${selectedId.value}/export.json`, { headers: { Accept: 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) } }); const blob = new Blob([JSON.stringify(await response.json(), null, 2)], { type: 'application/json' }); const url = window.URL.createObjectURL(blob); const link = document.createElement('a'); link.href = url; link.download = `frdo-package-${selectedId.value}.json`; link.click(); window.URL.revokeObjectURL(url) }
  function setFilters(next) { filters.value = { ...filters.value, ...next } }
  function resetFilters() { filters.value = { ...initialFilters } }
  function select(item) { selectedId.value = item?.id || null }
  function selectById(id) { selectedId.value = id || null }
  return { packages, filteredPackages, programs, graduates, filters, selectedId, selectedPackage, records, errors, loading, saving, error, graduationYearOptions, programOptions, load, createPackage, validatePackage, markExported, archive, exportCsv, exportJson, setFilters, resetFilters, select, selectById }
})
