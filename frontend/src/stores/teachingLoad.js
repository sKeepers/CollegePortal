import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'
import { buildGroupOptions } from '../utils/groupOptions'

const initialFilters = { academic_year: '', teacher_id: '', group_id: '', subject_id: '', semester: '', assignment_teacher_id: '', assignment_status: '' }
export const LOAD_STATUS_OPTIONS = [
  { label: 'Черновик', value: 'draft', tone: 'neutral' },
  { label: 'Действует', value: 'active', tone: 'success' },
  { label: 'Архив', value: 'archived', tone: 'warning' },
]
export const ASSIGNMENT_STATUS_OPTIONS = [
  { label: 'Не назначено', value: 'unassigned', tone: 'warning' },
  { label: 'Частично', value: 'partially_assigned', tone: 'info' },
  { label: 'Назначено', value: 'assigned', tone: 'success' },
  { label: 'Превышение', value: 'overassigned', tone: 'danger' },
]
export const LOAD_TYPE_OPTIONS = ['Аудиторная', 'Консультации', 'Практика', 'Экзамены', 'Методическая работа']
function extractRows(payload) { return Array.isArray(payload?.data) ? payload.data : [] }
function fullName(person) { return [person?.last_name, person?.first_name, person?.middle_name].filter(Boolean).join(' ') }
export function teacherName(teacher) { return fullName(teacher) || 'Не назначен' }
export function statusLabel(status) { return LOAD_STATUS_OPTIONS.find((item) => item.value === status)?.label || status || '—' }
export function statusTone(status) { return LOAD_STATUS_OPTIONS.find((item) => item.value === status)?.tone || 'neutral' }
export function assignmentLabel(status) { return ASSIGNMENT_STATUS_OPTIONS.find((item) => item.value === status)?.label || status || '—' }
export function assignmentTone(status) { return ASSIGNMENT_STATUS_OPTIONS.find((item) => item.value === status)?.tone || 'neutral' }
function cleanLoad(payload) { return { academic_year: payload.academic_year?.trim() || '', teacher_id: Number(payload.teacher_id), status: payload.status || 'draft', description: payload.description?.trim() || '' } }
function cleanItem(payload) { return { subject_id: Number(payload.subject_id), group_id: Number(payload.group_id), teacher_id: payload.teacher_id ? Number(payload.teacher_id) : null, semester: Number(payload.semester), hours_total: Number(payload.hours_total || 0), planned_hours: Number(payload.planned_hours || payload.hours_total || 0), assigned_hours: Number(payload.assigned_hours || 0), load_type: payload.load_type?.trim() || 'Аудиторная', sort_order: Number(payload.sort_order || 0), source: payload.source || 'manual' } }

export const useTeachingLoadStore = defineStore('teachingLoad', () => {
  const loads = ref([])
  const teachers = ref([])
  const groups = ref([])
  const subjects = ref([])
  const filters = ref({ ...initialFilters })
  const selectedId = ref(null)
  const loading = ref(false)
  const saving = ref(false)
  const error = ref('')
  const importSummary = ref(null)
  const generationPreview = ref(null)
  const coverage = ref(null)

  const selectedLoad = computed(() => loads.value.find((item) => Number(item.id) === Number(selectedId.value)) || null)
  const selectedItems = computed(() => selectedLoad.value?.items || [])
  const selectedHours = computed(() => selectedItems.value.reduce((sum, item) => sum + Number(item.planned_hours || item.hours_total || 0), 0))
  const filteredLoads = computed(() => loads.value.filter((load) => (!filters.value.academic_year || load.academic_year === filters.value.academic_year)
    && (!filters.value.teacher_id || Number(load.teacher_id) === Number(filters.value.teacher_id))
    && (!filters.value.group_id || Number(load.group_id) === Number(filters.value.group_id) || (load.items || []).some((item) => Number(item.group_id) === Number(filters.value.group_id)))
    && (!filters.value.subject_id || (load.items || []).some((item) => Number(item.subject_id) === Number(filters.value.subject_id)))
    && (!filters.value.semester || (load.items || []).some((item) => Number(item.semester) === Number(filters.value.semester)))
    && (!filters.value.assignment_teacher_id || (load.items || []).some((item) => Number(item.teacher_id) === Number(filters.value.assignment_teacher_id)))
    && (!filters.value.assignment_status || (load.items || []).some((item) => item.assignment_status === filters.value.assignment_status))))
  const academicYearOptions = computed(() => [...new Set(loads.value.map((load) => load.academic_year).filter(Boolean))].sort().reverse().map((year) => ({ label: year, value: year })))
  const semesterOptions = computed(() => [...new Set(loads.value.flatMap((load) => (load.items || []).map((item) => item.semester)).filter(Boolean))].sort((a, b) => a - b).map((semester) => ({ label: `${semester} семестр`, value: semester })))
  const teacherOptions = computed(() => teachers.value.map((teacher) => ({ label: teacherName(teacher), value: teacher.id })))
  const groupOptions = computed(() => buildGroupOptions(groups.value, {
    suffix: (group) => (group.curriculum_id ? 'учебный план' : null),
    extra: (group) => ({ curriculum_id: group.curriculum_id }),
  }))
  const subjectOptions = computed(() => subjects.value.map((subject) => ({ label: [subject.code, subject.name].filter(Boolean).join(' · '), value: subject.id })))

  async function load({ includeReferenceData = true } = {}) {
    loading.value = true; error.value = ''
    try {
      const requests = [api.list('teaching-loads')]
      if (includeReferenceData) requests.push(api.list('teachers', { per_page: 500 }), api.list('groups', { per_page: 200 }), api.list('subjects'))
      const [loadsPayload, teachersPayload = { data: [] }, groupsPayload = { data: [] }, subjectsPayload = { data: [] }] = await Promise.all(requests)
      loads.value = extractRows(loadsPayload)
      teachers.value = extractRows(teachersPayload)
      groups.value = extractRows(groupsPayload)
      subjects.value = extractRows(subjectsPayload)
      if (selectedId.value && !selectedLoad.value) selectedId.value = null
      if (selectedId.value) await loadCoverage(selectedId.value)
    } catch (err) { error.value = err.message || 'Не удалось загрузить нагрузку преподавателей' }
    finally { loading.value = false }
  }
  async function loadCoverage(id = selectedId.value) {
    if (!id) { coverage.value = null; return null }
    const payload = await api.list(`teaching-load/${id}/coverage`)
    coverage.value = payload?.data || null
    return coverage.value
  }
  async function generatePreview(payload) {
    saving.value = true; error.value = ''
    try { const response = await api.create('teaching-load/generate/preview', payload); generationPreview.value = response?.data || null; return generationPreview.value }
    catch (err) { error.value = err.message || 'Не удалось сформировать preview нагрузки'; throw err }
    finally { saving.value = false }
  }
  async function generateApply(payload) {
    saving.value = true; error.value = ''
    try { const response = await api.create('teaching-load/generate/apply', payload); generationPreview.value = response?.data || null; await load(); selectedId.value = response?.data?.teaching_load_id || selectedId.value; return generationPreview.value }
    catch (err) { error.value = err.message || 'Не удалось сформировать нагрузку'; throw err }
    finally { saving.value = false }
  }
  async function save(payload, id = null) { saving.value = true; error.value = ''; try { const response = id ? await api.update('teaching-loads', id, cleanLoad(payload)) : await api.create('teaching-loads', cleanLoad(payload)); await load(); selectedId.value = response?.data?.id || id || selectedId.value; return response?.data || null } catch (err) { error.value = err.message || 'Не удалось сохранить нагрузку'; throw err } finally { saving.value = false } }
  async function remove(load) { if (!load?.id) return; loading.value = true; error.value = ''; try { await api.delete('teaching-loads', load.id); selectedId.value = null; await load() } catch (err) { error.value = err.message || 'Не удалось удалить нагрузку'; throw err } finally { loading.value = false } }
  async function addItem(payload) { if (!selectedId.value) return null; saving.value = true; error.value = ''; try { const response = await api.create(`teaching-loads/${selectedId.value}/items`, cleanItem(payload)); await load(); return response?.data || null } catch (err) { error.value = err.message || 'Не удалось добавить строку нагрузки'; throw err } finally { saving.value = false } }
  async function assignTeacher(item, teacherId, assignedHours = null) { if (!item?.id) return null; saving.value = true; error.value = ''; try { const response = await api.create(`teaching-load/items/${item.id}/assign-teacher`, { teacher_id: teacherId, assigned_hours: assignedHours }); await load(); return response?.data || null } catch (err) { error.value = err.message || 'Не удалось назначить преподавателя'; throw err } finally { saving.value = false } }
  async function bulkAssignTeacher(ids, teacherId) { saving.value = true; error.value = ''; try { const response = await api.create('teaching-load/items/bulk-assign-teacher', { ids, teacher_id: teacherId }); await load(); return response?.data || null } catch (err) { error.value = err.message || 'Не удалось выполнить массовое назначение'; throw err } finally { saving.value = false } }
  async function removeItem(item) { if (!item?.id) return; loading.value = true; error.value = ''; try { await api.delete('teaching-load-items', item.id); await load() } catch (err) { error.value = err.message || 'Не удалось удалить строку нагрузки'; throw err } finally { loading.value = false } }
  async function importCsv(file) { if (!file) return null; loading.value = true; error.value = ''; importSummary.value = null; try { const formData = new FormData(); formData.append('file', file); const payload = await api.upload('/teaching-loads/import', formData); importSummary.value = payload?.data || null; await load(); return importSummary.value } catch (err) { error.value = err.message || 'Не удалось импортировать CSV'; throw err } finally { loading.value = false } }
  async function exportCsv() { const blob = await api.download('/teaching-loads/export'); const url = window.URL.createObjectURL(blob); const link = document.createElement('a'); link.href = url; link.download = 'teaching-loads.csv'; link.click(); window.URL.revokeObjectURL(url) }
  function setFilters(next) { filters.value = { ...filters.value, ...next } }
  function resetFilters() { filters.value = { ...initialFilters } }
  function select(load, { includeCoverage = true } = {}) { selectedId.value = load?.id || null; if (selectedId.value && includeCoverage) loadCoverage(selectedId.value) }
  function selectById(id, { includeCoverage = true } = {}) { selectedId.value = id || null; if (selectedId.value && includeCoverage) loadCoverage(selectedId.value) }

  return { loads, filteredLoads, teachers, groups, subjects, filters, selectedId, selectedLoad, selectedItems, selectedHours, loading, saving, error, importSummary, generationPreview, coverage, academicYearOptions, semesterOptions, teacherOptions, groupOptions, subjectOptions, load, loadCoverage, generatePreview, generateApply, save, remove, addItem, assignTeacher, bulkAssignTeacher, removeItem, importCsv, exportCsv, setFilters, resetFilters, select, selectById }
})
