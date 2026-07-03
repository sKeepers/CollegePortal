import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'

const initialFilters = { search: '', education_program_id: '', specialty_id: '', year_start: '' }
export const CURRICULUM_STATUS_OPTIONS = [
  { label: 'Черновик', value: 'draft', tone: 'neutral' },
  { label: 'Действует', value: 'active', tone: 'success' },
  { label: 'Архив', value: 'archived', tone: 'warning' },
]
export const CONTROL_FORM_OPTIONS = ['Экзамен', 'Зачет', 'Дифференцированный зачет', 'Контрольная работа', 'Курсовая работа']
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
export const useCurriculaStore = defineStore('curricula', () => {
  const curricula = ref([])
  const educationPrograms = ref([])
  const specialties = ref([])
  const subjects = ref([])
  const filters = ref({ ...initialFilters })
  const selectedId = ref(null)
  const loading = ref(false)
  const saving = ref(false)
  const error = ref('')
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
  const yearOptions = computed(() => [...new Set(curricula.value.map((item) => item.year_start).filter(Boolean))].sort((a, b) => b - a).map((year) => ({ label: String(year), value: year })))
  const selectedItems = computed(() => selectedCurriculum.value?.items || [])
  const selectedHours = computed(() => selectedItems.value.reduce((sum, item) => sum + Number(item.hours_total || 0), 0))

  async function load() {
    loading.value = true; error.value = ''
    try {
      const [curriculaPayload, programsPayload, specialtiesPayload, subjectsPayload] = await Promise.all([
        api.list('curricula'), api.list('education-programs'), api.list('specialties'), api.list('subjects'),
      ])
      curricula.value = extractRows(curriculaPayload)
      educationPrograms.value = extractRows(programsPayload)
      specialties.value = extractRows(specialtiesPayload)
      subjects.value = extractRows(subjectsPayload)
      if (selectedId.value && !selectedCurriculum.value) selectedId.value = null
    } catch (err) { error.value = err.message || 'Не удалось загрузить учебные планы' }
    finally { loading.value = false }
  }
  async function save(payload, id = null) {
    saving.value = true; error.value = ''
    try {
      const response = id ? await api.update('curricula', id, cleanCurriculum(payload)) : await api.create('curricula', cleanCurriculum(payload))
      await load(); selectedId.value = response?.data?.id || id || selectedId.value
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
  function select(curriculum) { selectedId.value = curriculum?.id || null }
  function selectById(id) { selectedId.value = id || null }
  return { curricula, filteredCurricula, educationPrograms, specialties, subjects, filters, selectedId, selectedCurriculum, selectedItems, selectedHours, loading, saving, error, importSummary, programOptions, specialtyOptions, subjectOptions, yearOptions, load, save, remove, addItem, removeItem, importCsv, exportCsv, setFilters, resetFilters, select, selectById }
})
