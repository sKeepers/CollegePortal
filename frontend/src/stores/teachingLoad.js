import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'

const initialFilters = { academic_year: '', teacher_id: '', group_id: '', subject_id: '' }
export const LOAD_STATUS_OPTIONS = [
  { label: 'Черновик', value: 'draft', tone: 'neutral' },
  { label: 'Действует', value: 'active', tone: 'success' },
  { label: 'Архив', value: 'archived', tone: 'warning' },
]
export const LOAD_TYPE_OPTIONS = ['Аудиторная', 'Консультации', 'Практика', 'Экзамены', 'Методическая работа']
function extractRows(payload) { return Array.isArray(payload?.data) ? payload.data : [] }
function fullName(person) { return [person?.last_name, person?.first_name, person?.middle_name].filter(Boolean).join(' ') }
export function teacherName(teacher) { return fullName(teacher) || '—' }
export function statusLabel(status) { return LOAD_STATUS_OPTIONS.find((item) => item.value === status)?.label || status || '—' }
export function statusTone(status) { return LOAD_STATUS_OPTIONS.find((item) => item.value === status)?.tone || 'neutral' }
function cleanLoad(payload) { return { academic_year: payload.academic_year?.trim() || '', teacher_id: Number(payload.teacher_id), status: payload.status || 'draft', description: payload.description?.trim() || '' } }
function cleanItem(payload) { return { subject_id: Number(payload.subject_id), group_id: Number(payload.group_id), semester: Number(payload.semester), hours_total: Number(payload.hours_total || 0), load_type: payload.load_type?.trim() || 'Аудиторная', sort_order: Number(payload.sort_order || 0) } }
export const useTeachingLoadStore = defineStore('teachingLoad', () => {
  const loads = ref([]), teachers = ref([]), groups = ref([]), subjects = ref([])
  const filters = ref({ ...initialFilters })
  const selectedId = ref(null), loading = ref(false), saving = ref(false), error = ref(''), importSummary = ref(null)
  const selectedLoad = computed(() => loads.value.find((item) => Number(item.id) === Number(selectedId.value)) || null)
  const selectedItems = computed(() => selectedLoad.value?.items || [])
  const selectedHours = computed(() => selectedItems.value.reduce((sum, item) => sum + Number(item.hours_total || 0), 0))
  const filteredLoads = computed(() => loads.value.filter((load) => (!filters.value.academic_year || load.academic_year === filters.value.academic_year)
    && (!filters.value.teacher_id || Number(load.teacher_id) === Number(filters.value.teacher_id))
    && (!filters.value.group_id || (load.items || []).some((item) => Number(item.group_id) === Number(filters.value.group_id)))
    && (!filters.value.subject_id || (load.items || []).some((item) => Number(item.subject_id) === Number(filters.value.subject_id)))))
  const academicYearOptions = computed(() => [...new Set(loads.value.map((load) => load.academic_year).filter(Boolean))].sort().reverse().map((year) => ({ label: year, value: year })))
  const teacherOptions = computed(() => teachers.value.map((teacher) => ({ label: teacherName(teacher), value: teacher.id })))
  const groupOptions = computed(() => groups.value.map((group) => ({ label: group.name, value: group.id })))
  const subjectOptions = computed(() => subjects.value.map((subject) => ({ label: [subject.code, subject.name].filter(Boolean).join(' · '), value: subject.id })))
  async function load() { loading.value = true; error.value = ''; try { const [loadsPayload, teachersPayload, groupsPayload, subjectsPayload] = await Promise.all([api.list('teaching-loads'), api.list('teachers'), api.list('groups'), api.list('subjects')]); loads.value = extractRows(loadsPayload); teachers.value = extractRows(teachersPayload); groups.value = extractRows(groupsPayload); subjects.value = extractRows(subjectsPayload); if (selectedId.value && !selectedLoad.value) selectedId.value = null } catch (err) { error.value = err.message || 'Не удалось загрузить нагрузку преподавателей' } finally { loading.value = false } }
  async function save(payload, id = null) { saving.value = true; error.value = ''; try { const response = id ? await api.update('teaching-loads', id, cleanLoad(payload)) : await api.create('teaching-loads', cleanLoad(payload)); await load(); selectedId.value = response?.data?.id || id || selectedId.value; return response?.data || null } catch (err) { error.value = err.message || 'Не удалось сохранить нагрузку'; throw err } finally { saving.value = false } }
  async function remove(load) { if (!load?.id) return; loading.value = true; error.value = ''; try { await api.delete('teaching-loads', load.id); selectedId.value = null; await load() } catch (err) { error.value = err.message || 'Не удалось удалить нагрузку'; throw err } finally { loading.value = false } }
  async function addItem(payload) { if (!selectedId.value) return null; saving.value = true; error.value = ''; try { const response = await api.create(`teaching-loads/${selectedId.value}/items`, cleanItem(payload)); await load(); return response?.data || null } catch (err) { error.value = err.message || 'Не удалось добавить строку нагрузки'; throw err } finally { saving.value = false } }
  async function removeItem(item) { if (!item?.id) return; loading.value = true; error.value = ''; try { await api.delete('teaching-load-items', item.id); await load() } catch (err) { error.value = err.message || 'Не удалось удалить строку нагрузки'; throw err } finally { loading.value = false } }
  async function importCsv(file) { if (!file) return null; loading.value = true; error.value = ''; importSummary.value = null; try { const formData = new FormData(); formData.append('file', file); const payload = await api.upload('/teaching-loads/import', formData); importSummary.value = payload?.data || null; await load(); return importSummary.value } catch (err) { error.value = err.message || 'Не удалось импортировать CSV'; throw err } finally { loading.value = false } }
  async function exportCsv() { const blob = await api.download('/teaching-loads/export'); const url = window.URL.createObjectURL(blob); const link = document.createElement('a'); link.href = url; link.download = 'teaching-loads.csv'; link.click(); window.URL.revokeObjectURL(url) }
  function setFilters(next) { filters.value = { ...filters.value, ...next } }
  function resetFilters() { filters.value = { ...initialFilters } }
  function select(load) { selectedId.value = load?.id || null }
  function selectById(id) { selectedId.value = id || null }
  return { loads, filteredLoads, teachers, groups, subjects, filters, selectedId, selectedLoad, selectedItems, selectedHours, loading, saving, error, importSummary, academicYearOptions, teacherOptions, groupOptions, subjectOptions, load, save, remove, addItem, removeItem, importCsv, exportCsv, setFilters, resetFilters, select, selectById }
})
