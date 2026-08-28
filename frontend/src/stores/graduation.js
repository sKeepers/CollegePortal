import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'
import { loadReferences } from '../services/referenceLoader'
import { buildGroupOptions } from '../utils/groupOptions'

const initialFilters = { graduation_year: '', group_id: '', education_program_id: '', diploma_status: '' }
export const GRADUATE_STATUS_OPTIONS = [
  { label: 'Черновик', value: 'draft', tone: 'neutral' },
  { label: 'Готов', value: 'ready', tone: 'info' },
  { label: 'Выпущен', value: 'issued', tone: 'success' },
  { label: 'Архив', value: 'archived', tone: 'warning' },
]
export const DIPLOMA_STATUS_OPTIONS = [
  { label: 'Черновик', value: 'draft', tone: 'neutral' },
  { label: 'Готов', value: 'ready', tone: 'info' },
  { label: 'Выдан', value: 'issued', tone: 'success' },
  { label: 'Аннулирован', value: 'revoked', tone: 'danger' },
]
function extractRows(payload) { return Array.isArray(payload?.data) ? payload.data : [] }
function fullName(person) { return [person?.last_name, person?.first_name, person?.middle_name].filter(Boolean).join(' ') }
export function studentName(student) { return fullName(student) || '—' }
export function statusLabel(options, value) { return options.find((item) => item.value === value)?.label || value || '—' }
export function statusTone(options, value) { return options.find((item) => item.value === value)?.tone || 'neutral' }
export function formatRuDate(value) { if (!value) return '—'; const date = new Date(`${value}T00:00:00`); return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString('ru-RU') }
function cleanGraduate(payload) { return { student_id: Number(payload.student_id), group_id: payload.group_id ? Number(payload.group_id) : null, education_program_id: payload.education_program_id ? Number(payload.education_program_id) : null, specialty_id: payload.specialty_id ? Number(payload.specialty_id) : null, graduation_year: Number(payload.graduation_year), qualification: payload.qualification?.trim() || '', status: payload.status || 'draft', note: payload.note?.trim() || '' } }
function cleanDiploma(payload) { return { series: payload.series?.trim() || '', number: payload.number?.trim() || '', registration_number: payload.registration_number?.trim() || '', issue_date: payload.issue_date || null, qualification: payload.qualification?.trim() || '', gia_decision: payload.gia_decision?.trim() || '', status: payload.status || 'draft', note: payload.note?.trim() || '' } }
// `subjects` — перечень дисциплин приложения. До 24.08.2026 он не доходил до сервера
// вовсе: форма его не собирала, а чистка выбрасывала. Приложение из-за этого
// оставалось пустым при любом наполнении портала.
function cleanSupplement(payload) { return { series: payload.series?.trim() || '', number: payload.number?.trim() || '', issue_date: payload.issue_date || null, status: payload.status || 'draft', note: payload.note?.trim() || '', subjects: Array.isArray(payload.subjects) ? payload.subjects : [] } }
export const useGraduationStore = defineStore('graduation', () => {
  const graduates = ref([]), students = ref([]), groups = ref([]), programs = ref([]), specialties = ref([])
  const filters = ref({ ...initialFilters })
  const selectedId = ref(null), loading = ref(false), saving = ref(false), error = ref(''), importSummary = ref(null)
  const assembling = ref(false), assemblyError = ref(''), assemblyProblems = ref([])
  const selectedGraduate = computed(() => graduates.value.find((item) => Number(item.id) === Number(selectedId.value)) || null)
  const filteredGraduates = computed(() => graduates.value.filter((item) => (!filters.value.graduation_year || Number(item.graduation_year) === Number(filters.value.graduation_year)) && (!filters.value.group_id || Number(item.group_id) === Number(filters.value.group_id)) && (!filters.value.education_program_id || Number(item.education_program_id) === Number(filters.value.education_program_id)) && (!filters.value.diploma_status || item.diploma?.status === filters.value.diploma_status)))
  const graduationYearOptions = computed(() => [...new Set(graduates.value.map((item) => item.graduation_year).filter(Boolean))].sort((a, b) => b - a).map((year) => ({ label: String(year), value: year })))
  const studentOptions = computed(() => students.value.filter((student) => !graduates.value.some((graduate) => Number(graduate.student_id) === Number(student.id)) || Number(selectedGraduate.value?.student_id) === Number(student.id)).map((student) => ({ label: studentName(student), value: student.id, group_id: student.group_id })))
  const groupOptions = computed(() => buildGroupOptions(groups.value, {
    extra: (group) => ({ education_program_id: group.education_program_id }),
  }))
  const programOptions = computed(() => programs.value.map((program) => ({ label: program.name, value: program.id, specialty_id: program.specialty_id })))
  const specialtyOptions = computed(() => specialties.value.map((specialty) => ({ label: [specialty.code, specialty.name].filter(Boolean).join(' · '), value: specialty.id, qualification: specialty.qualification })))
  // Выпускники — сам экран, остальное — справочники его форм и фильтров.
  async function load() { loading.value = true; error.value = ''; try { const { payloads } = await loadReferences({ graduates: api.listAll('graduates'), students: api.listAll('students'), groups: api.listAll('groups', { per_page: 200 }), programs: api.listAll('education-programs'), specialties: api.listAll('specialties') }); graduates.value = extractRows(payloads.graduates); students.value = extractRows(payloads.students); groups.value = extractRows(payloads.groups); programs.value = extractRows(payloads.programs); specialties.value = extractRows(payloads.specialties); if (selectedId.value && !selectedGraduate.value) selectedId.value = null } catch (err) { error.value = err.message || 'Не удалось загрузить выпускников' } finally { loading.value = false } }
  async function save(payload, id = null) { saving.value = true; error.value = ''; try { const response = id ? await api.update('graduates', id, cleanGraduate(payload)) : await api.create('graduates', cleanGraduate(payload)); await load(); selectedId.value = response?.data?.id || id || selectedId.value; return response?.data || null } catch (err) { error.value = err.message || 'Не удалось сохранить выпускника'; throw err } finally { saving.value = false } }
  async function remove(graduate) { if (!graduate?.id) return; loading.value = true; error.value = ''; try { await api.delete('graduates', graduate.id); selectedId.value = null; await load() } catch (err) { error.value = err.message || 'Не удалось удалить выпускника'; throw err } finally { loading.value = false } }
  async function saveDiploma(payload) { if (!selectedId.value) return null; saving.value = true; error.value = ''; try { const response = await api.create(`graduates/${selectedId.value}/diploma`, cleanDiploma(payload)); await load(); return response?.data || null } catch (err) { error.value = err.message || 'Не удалось сохранить диплом'; throw err } finally { saving.value = false } }
  /**
   * Собрать приложение из учебного плана и итоговых оценок.
   *
   * Ничего не сохраняет: возвращает строки и перечень того, чего не хватает. Отказ
   * сервера показывается как есть — он называет причину («у группы выпускника не выбран
   * учебный план»), и своими словами её не перескажешь лучше.
   */
  async function assembleSupplement() {
    if (!selectedId.value) return []
    assembling.value = true; assemblyError.value = ''; assemblyProblems.value = []
    try {
      const payload = await api.get(`graduates/${selectedId.value}/supplement/assembled`)
      const data = payload?.data || {}
      assemblyProblems.value = data.problems || []
      return data.rows || []
    } catch (err) {
      assemblyError.value = err.message || 'Не удалось собрать приложение'
      return []
    } finally { assembling.value = false }
  }
  async function saveSupplement(payload) { if (!selectedId.value) return null; saving.value = true; error.value = ''; try { const response = await api.create(`graduates/${selectedId.value}/supplement`, cleanSupplement(payload)); await load(); return response?.data || null } catch (err) { error.value = err.message || 'Не удалось сохранить приложение'; throw err } finally { saving.value = false } }
  async function importCsv(file) { if (!file) return null; loading.value = true; error.value = ''; importSummary.value = null; try { const formData = new FormData(); formData.append('file', file); const payload = await api.upload('/graduates/import', formData); importSummary.value = payload?.data || null; await load(); return importSummary.value } catch (err) { error.value = err.message || 'Не удалось импортировать CSV'; throw err } finally { loading.value = false } }
  async function exportCsv() { const blob = await api.download('/graduates/export'); const url = window.URL.createObjectURL(blob); const link = document.createElement('a'); link.href = url; link.download = 'graduates.csv'; link.click(); window.URL.revokeObjectURL(url) }
  function setFilters(next) { filters.value = { ...filters.value, ...next } }
  function resetFilters() { filters.value = { ...initialFilters } }
  function select(graduate) { selectedId.value = graduate?.id || null }
  function selectById(id) { selectedId.value = id || null }
  return { graduates, filteredGraduates, students, groups, programs, specialties, filters, selectedId, selectedGraduate, loading, saving, error, importSummary, graduationYearOptions, studentOptions, groupOptions, programOptions, specialtyOptions, load, save, remove, saveDiploma, saveSupplement, assembleSupplement, assembling, assemblyError, assemblyProblems, importCsv, exportCsv, setFilters, resetFilters, select, selectById }
})
