import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'
import { loadReferences } from '../services/referenceLoader'

const initialFilters = { search: '', education_program_id: '', specialty_id: '', year_start: '' }
export const CURRICULUM_STATUS_OPTIONS = [
  { label: 'Черновик', value: 'draft', tone: 'neutral' },
  { label: 'Действует', value: 'active', tone: 'success' },
  { label: 'Архив', value: 'archived', tone: 'warning' },
]
export const CONTROL_FORM_OPTIONS = ['Экзамен', 'Зачет', 'Дифференцированный зачет', 'Курсовая работа', 'Проект', 'Практика', 'ГИА']
function extractRows(payload) { return Array.isArray(payload?.data) ? payload.data : [] }
export function statusLabel(status) { return CURRICULUM_STATUS_OPTIONS.find((item) => item.value === status)?.label || status || '—' }
export function statusTone(status) { return CURRICULUM_STATUS_OPTIONS.find((item) => item.value === status)?.tone || 'neutral' }
function cleanCurriculum(payload) {
  return {
    code: payload.code?.trim() || '',
    education_program_id: Number(payload.education_program_id),
    name: payload.name?.trim() || '',
    year_start: Number(payload.year_start),
    status: payload.status || 'draft',
    description: payload.description?.trim() || '',
  }
}
function cleanItem(payload) {
  return {
    subject_id: Number(payload.subject_id),
    course: Number(payload.course),
    semester: Number(payload.semester),
    hours_total: Number(payload.hours_total || 0),
    control_form: payload.control_form?.trim() || '',
    sort_order: Number(payload.sort_order || 0),
  }
}
function cleanSubject(payload) {
  return {
    subject_id: Number(payload.subject_id),
    semester: Number(payload.semester || 1),
    lecture_hours: Number(payload.lecture_hours || 0),
    practice_hours: Number(payload.practice_hours || 0),
    laboratory_hours: Number(payload.laboratory_hours || 0),
    independent_hours: Number(payload.independent_hours || 0),
    control_type_id: payload.control_type_id ? Number(payload.control_type_id) : null,
    sequence: Number(payload.sequence || 0),
    is_optional: Boolean(payload.is_optional),
  }
}
export const useCurriculaStore = defineStore('curricula', () => {
  const curricula = ref([])
  const educationPrograms = ref([])
  const specialties = ref([])
  const subjects = ref([])
  const controlTypes = ref([])
  const selectedSubjects = ref([])
  const selectedSemesters = ref([])
  const selectedSummary = ref(null)
  const filters = ref({ ...initialFilters })
  const selectedId = ref(null)
  const loading = ref(false)
  const saving = ref(false)
  const error = ref('')
  // Список получен — только тогда его длина что-то значит. Замер 03.09.2026
  // в браузере: при оборванном запросе экран говорил «Учебные планы не найдены. Создайте учебный план» и «Найдено учебных планов: 0» — то есть
  // утверждал о колледже то, чего не спрашивал, и называл число, которого не
  // считал. Признак заведён по образцу экранов проходной (053103511).
  const loaded = ref(false)

  const importSummary = ref(null)

  const selectedCurriculum = computed(() => curricula.value.find((item) => Number(item.id) === Number(selectedId.value)) || null)
  const filteredCurricula = computed(() => {
    const search = filters.value.search.trim().toLowerCase()
    return curricula.value.filter((curriculum) => {
      const program = curriculum.education_program
      const specialty = program?.specialty
      const haystack = [curriculum.name, curriculum.description, program?.name, specialty?.name, specialty?.code].filter(Boolean).join(' ').toLowerCase()
      return (!search || haystack.includes(search))
        && (!filters.value.education_program_id || Number(curriculum.education_program_id) === Number(filters.value.education_program_id))
        && (!filters.value.specialty_id || Number(program?.specialty_id) === Number(filters.value.specialty_id))
        && (!filters.value.year_start || Number(curriculum.year_start) === Number(filters.value.year_start))
    })
  })
  const programOptions = computed(() => educationPrograms.value.map((program) => ({ label: [program.name, program.study_form, program.year_start].filter(Boolean).join(' · '), value: program.id, specialty_id: program.specialty_id })))
  const specialtyOptions = computed(() => specialties.value.map((specialty) => ({ label: [specialty.code, specialty.name].filter(Boolean).join(' · '), value: specialty.id })))
  const subjectOptions = computed(() => subjects.value.map((subject) => ({ label: [subject.code, subject.name].filter(Boolean).join(' · '), value: subject.id })))
  const controlTypeOptions = computed(() => controlTypes.value.map((item) => ({ label: item.name, value: item.id, code: item.code })))
  const yearOptions = computed(() => [...new Set(curricula.value.map((item) => item.year_start).filter(Boolean))].sort((a, b) => b - a).map((year) => ({ label: String(year), value: year })))
  const selectedItems = computed(() => selectedCurriculum.value?.items || [])
  const selectedHours = computed(() => (selectedSummary.value?.total_hours ?? selectedSubjects.value.reduce((sum, item) => sum + Number(item.total_hours || 0), 0)) || selectedItems.value.reduce((sum, item) => sum + Number(item.hours_total || 0), 0))

  async function load() {
    loading.value = true; error.value = ''
    try {
      // Учебные планы — сам экран, остальное — справочники его форм.
      const { payloads } = await loadReferences({
        curricula: api.listAll('curricula'),
        programs: api.listAll('education-programs'),
        specialties: api.listAll('specialties'),
        subjects: api.listAll('subjects'),
        controlTypes: api.listAll('admin/reference/items', { catalog_code: 'control_types', is_active: 1 }),
      })
      curricula.value = extractRows(payloads.curricula)
      educationPrograms.value = extractRows(payloads.programs)
      specialties.value = extractRows(payloads.specialties)
      subjects.value = extractRows(payloads.subjects)
      controlTypes.value = extractRows(payloads.controlTypes)
      if (selectedId.value && !selectedCurriculum.value) selectedId.value = null
      if (selectedId.value) await loadEngine(selectedId.value)
      loaded.value = true
    } catch (err) { loaded.value = false; error.value = err.message || 'Не удалось загрузить учебные планы' }
    finally { loading.value = false }
  }
  async function save(payload, id = null) {
    saving.value = true; error.value = ''
    try {
      const response = id ? await api.update('curricula', id, cleanCurriculum(payload)) : await api.create('curricula', cleanCurriculum(payload))
      await load(); selectedId.value = response?.data?.id || id || selectedId.value; if (selectedId.value) await loadEngine(selectedId.value)
      return response?.data || null
    } catch (err) { error.value = err.message || 'Не удалось сохранить учебный план'; throw err }
    finally { saving.value = false }
  }
  async function remove(curriculum) {
    if (!curriculum?.id) return
    loading.value = true; error.value = ''
    try { await api.delete('curricula', curriculum.id); selectedId.value = null; await load() }
    catch (err) { error.value = err.message || 'Не удалось удалить учебный план'; throw err }
    finally { loading.value = false }
  }

  async function loadEngine(id = selectedId.value) {
    if (!id) { selectedSubjects.value = []; selectedSemesters.value = []; selectedSummary.value = null; return }
    const [subjectsPayload, semestersPayload, summaryPayload] = await Promise.all([
      api.list(`curricula/${id}/subjects`),
      api.list(`curricula/${id}/semesters`),
      api.list(`curricula/${id}/summary`),
    ])
    selectedSubjects.value = extractRows(subjectsPayload)
    selectedSemesters.value = extractRows(semestersPayload)
    selectedSummary.value = summaryPayload?.data || null
  }
  async function addSubject(payload) {
    if (!selectedId.value) return null
    saving.value = true; error.value = ''
    try { const response = await api.create(`curricula/${selectedId.value}/subjects`, cleanSubject(payload)); await load(); await loadEngine(); return response?.data || null }
    catch (err) { error.value = err.message || 'Не удалось добавить дисциплину семестра'; throw err }
    finally { saving.value = false }
  }
  async function updateSubject(id, payload) {
    saving.value = true; error.value = ''
    try { const response = await api.put(`curriculum-subjects/${id}`, cleanSubject(payload)); await load(); await loadEngine(); return response?.data || null }
    catch (err) { error.value = err.message || 'Не удалось обновить дисциплину семестра'; throw err }
    finally { saving.value = false }
  }
  async function removeSubject(subject) {
    if (!subject?.id) return
    loading.value = true; error.value = ''
    try { await api.delete('curriculum-subjects', subject.id); await load(); await loadEngine() }
    catch (err) { error.value = err.message || 'Не удалось удалить дисциплину семестра'; throw err }
    finally { loading.value = false }
  }
  async function addItem(payload) {
    if (!selectedId.value) return null
    saving.value = true; error.value = ''
    try { const response = await api.create(`curricula/${selectedId.value}/items`, cleanItem(payload)); await load(); return response?.data || null }
    catch (err) { error.value = err.message || 'Не удалось добавить дисциплину в план'; throw err }
    finally { saving.value = false }
  }
  async function removeItem(item) {
    if (!item?.id) return
    loading.value = true; error.value = ''
    try { await api.delete('curriculum-items', item.id); await load() }
    catch (err) { error.value = err.message || 'Не удалось удалить строку учебного плана'; throw err }
    finally { loading.value = false }
  }
  async function importCsv(file) {
    if (!file) return null
    loading.value = true; error.value = ''; importSummary.value = null
    try { const formData = new FormData(); formData.append('file', file); const payload = await api.upload('/curricula/import', formData); importSummary.value = payload?.data || null; await load(); return importSummary.value }
    catch (err) { error.value = err.message || 'Не удалось импортировать CSV'; throw err }
    finally { loading.value = false }
  }
  async function exportCsv() {
    const blob = await api.download('/curricula/export')
    const url = window.URL.createObjectURL(blob); const link = document.createElement('a')
    link.href = url; link.download = 'curricula.csv'; link.click(); window.URL.revokeObjectURL(url)
  }
  function setFilters(next) { filters.value = { ...filters.value, ...next } }
  function resetFilters() { filters.value = { ...initialFilters } }
  async function select(curriculum) { selectedId.value = curriculum?.id || null; await loadEngine() }
  async function selectById(id) { selectedId.value = id || null; await loadEngine() }
  return { curricula, filteredCurricula, educationPrograms, specialties, subjects, controlTypes, selectedSubjects, selectedSemesters, selectedSummary, filters, selectedId, selectedCurriculum, selectedItems, selectedHours, loading, saving, error, loaded, importSummary, programOptions, specialtyOptions, subjectOptions, controlTypeOptions, yearOptions, load, loadEngine, save, remove, addSubject, updateSubject, removeSubject, addItem, removeItem, importCsv, exportCsv, setFilters, resetFilters, select, selectById }
})
