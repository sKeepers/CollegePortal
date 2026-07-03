import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'

const initialFilters = { academic_year: '', group_id: '', subject_id: '', teacher_id: '', exam_type: '' }
export const EXAM_TYPE_OPTIONS = [
  { label: 'Экзамен', value: 'exam', tone: 'danger' },
  { label: 'Зачет', value: 'credit', tone: 'success' },
  { label: 'Дифференцированный зачет', value: 'differentiated_credit', tone: 'warning' },
  { label: 'ГИА', value: 'gia', tone: 'info' },
]
export const EXAM_STATUS_OPTIONS = [
  { label: 'Черновик', value: 'draft', tone: 'neutral' },
  { label: 'Запланирован', value: 'scheduled', tone: 'info' },
  { label: 'Проведен', value: 'completed', tone: 'success' },
  { label: 'Отменен', value: 'canceled', tone: 'danger' },
]
export const RESULT_STATUS_OPTIONS = [
  { label: 'Планируется', value: 'planned', tone: 'neutral' },
  { label: 'Сдано', value: 'passed', tone: 'success' },
  { label: 'Не сдано', value: 'failed', tone: 'danger' },
  { label: 'Неявка', value: 'absent', tone: 'warning' },
]
export const RESULT_OPTIONS = ['5', '4', '3', '2', 'зачет', 'незачет', 'Н']
function extractRows(payload) { return Array.isArray(payload?.data) ? payload.data : [] }
function fullName(person) { return [person?.last_name, person?.first_name, person?.middle_name].filter(Boolean).join(' ') }
export function teacherName(teacher) { return fullName(teacher) || '—' }
export function studentName(student) { return fullName(student) || '—' }
export function subjectName(subject) { return [subject?.code, subject?.name].filter(Boolean).join(' · ') || '—' }
export function classroomName(classroom) { return classroom?.number ? [classroom.number, classroom.building].filter(Boolean).join(' · ') : '—' }
export function examTypeLabel(value) { return EXAM_TYPE_OPTIONS.find((item) => item.value === value)?.label || value || '—' }
export function examTypeTone(value) { return EXAM_TYPE_OPTIONS.find((item) => item.value === value)?.tone || 'neutral' }
export function examStatusLabel(value) { return EXAM_STATUS_OPTIONS.find((item) => item.value === value)?.label || value || '—' }
export function examStatusTone(value) { return EXAM_STATUS_OPTIONS.find((item) => item.value === value)?.tone || 'neutral' }
export function resultStatusLabel(value) { return RESULT_STATUS_OPTIONS.find((item) => item.value === value)?.label || value || '—' }
export function resultStatusTone(value) { return RESULT_STATUS_OPTIONS.find((item) => item.value === value)?.tone || 'neutral' }
export function formatRuDate(value) { if (!value) return '—'; const date = new Date(`${value}T00:00:00`); return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString('ru-RU') }
function cleanExam(payload) {
  return {
    academic_year: payload.academic_year?.trim() || '',
    semester: Number(payload.semester || 1),
    group_id: Number(payload.group_id),
    subject_id: Number(payload.subject_id),
    teacher_id: Number(payload.teacher_id),
    classroom_id: payload.classroom_id ? Number(payload.classroom_id) : null,
    exam_date: payload.exam_date,
    starts_at: payload.starts_at || null,
    ends_at: payload.ends_at || null,
    exam_type: payload.exam_type || 'exam',
    status: payload.status || 'scheduled',
    topic: payload.topic?.trim() || '',
  }
}
function cleanResult(payload) {
  return {
    student_id: Number(payload.student_id),
    result: payload.result?.trim() || null,
    score: payload.score !== '' && payload.score !== null && payload.score !== undefined ? Number(payload.score) : null,
    status: payload.status || 'planned',
    comment: payload.comment?.trim() || '',
  }
}
export const useExamsStore = defineStore('exams', () => {
  const exams = ref([]), groups = ref([]), subjects = ref([]), teachers = ref([]), classrooms = ref([]), students = ref([])
  const filters = ref({ ...initialFilters })
  const selectedId = ref(null), loading = ref(false), saving = ref(false), error = ref(''), importSummary = ref(null)
  const selectedExam = computed(() => exams.value.find((item) => Number(item.id) === Number(selectedId.value)) || null)
  const selectedResults = computed(() => selectedExam.value?.results || [])
  const filteredExams = computed(() => exams.value.filter((exam) => (!filters.value.academic_year || exam.academic_year === filters.value.academic_year)
    && (!filters.value.group_id || Number(exam.group_id) === Number(filters.value.group_id))
    && (!filters.value.subject_id || Number(exam.subject_id) === Number(filters.value.subject_id))
    && (!filters.value.teacher_id || Number(exam.teacher_id) === Number(filters.value.teacher_id))
    && (!filters.value.exam_type || exam.exam_type === filters.value.exam_type)))
  const academicYearOptions = computed(() => [...new Set(exams.value.map((exam) => exam.academic_year).filter(Boolean))].sort().reverse().map((year) => ({ label: year, value: year })))
  const groupOptions = computed(() => groups.value.map((group) => ({ label: group.name, value: group.id })))
  const subjectOptions = computed(() => subjects.value.map((subject) => ({ label: subjectName(subject), value: subject.id })))
  const teacherOptions = computed(() => teachers.value.map((teacher) => ({ label: teacherName(teacher), value: teacher.id })))
  const classroomOptions = computed(() => classrooms.value.map((classroom) => ({ label: classroomName(classroom), value: classroom.id })))
  const studentOptions = computed(() => students.value.map((student) => ({ label: studentName(student), value: student.id, group_id: student.group_id })))
  async function load() {
    loading.value = true; error.value = ''
    try {
      const [examsPayload, groupsPayload, subjectsPayload, teachersPayload, classroomsPayload, studentsPayload] = await Promise.all([
        api.list('exams'), api.list('groups'), api.list('subjects'), api.list('teachers'), api.list('classrooms'), api.list('students'),
      ])
      exams.value = extractRows(examsPayload); groups.value = extractRows(groupsPayload); subjects.value = extractRows(subjectsPayload); teachers.value = extractRows(teachersPayload); classrooms.value = extractRows(classroomsPayload); students.value = extractRows(studentsPayload)
      if (selectedId.value && !selectedExam.value) selectedId.value = null
    } catch (err) { error.value = err.message || 'Не удалось загрузить экзамены и ГИА' }
    finally { loading.value = false }
  }
  async function save(payload, id = null) { saving.value = true; error.value = ''; try { const response = id ? await api.update('exams', id, cleanExam(payload)) : await api.create('exams', cleanExam(payload)); await load(); selectedId.value = response?.data?.id || id || selectedId.value; return response?.data || null } catch (err) { error.value = err.message || 'Не удалось сохранить экзамен'; throw err } finally { saving.value = false } }
  async function remove(exam) { if (!exam?.id) return; loading.value = true; error.value = ''; try { await api.delete('exams', exam.id); selectedId.value = null; await load() } catch (err) { error.value = err.message || 'Не удалось удалить экзамен'; throw err } finally { loading.value = false } }
  async function saveResult(payload) { if (!selectedId.value) return null; saving.value = true; error.value = ''; try { const response = await api.create(`exams/${selectedId.value}/results`, cleanResult(payload)); await load(); return response?.data || null } catch (err) { error.value = err.message || 'Не удалось сохранить результат'; throw err } finally { saving.value = false } }
  async function removeResult(result) { if (!result?.id) return; loading.value = true; error.value = ''; try { await api.delete('exam-results', result.id); await load() } catch (err) { error.value = err.message || 'Не удалось удалить результат'; throw err } finally { loading.value = false } }
  async function importCsv(file) { if (!file) return null; loading.value = true; error.value = ''; importSummary.value = null; try { const formData = new FormData(); formData.append('file', file); const payload = await api.upload('/exams/import', formData); importSummary.value = payload?.data || null; await load(); return importSummary.value } catch (err) { error.value = err.message || 'Не удалось импортировать CSV'; throw err } finally { loading.value = false } }
  async function exportCsv() { const blob = await api.download('/exams/export'); const url = window.URL.createObjectURL(blob); const link = document.createElement('a'); link.href = url; link.download = 'exams.csv'; link.click(); window.URL.revokeObjectURL(url) }
  function setFilters(next) { filters.value = { ...filters.value, ...next } }
  function resetFilters() { filters.value = { ...initialFilters } }
  function select(exam) { selectedId.value = exam?.id || null }
  function selectById(id) { selectedId.value = id || null }
  return { exams, filteredExams, groups, subjects, teachers, classrooms, students, filters, selectedId, selectedExam, selectedResults, loading, saving, error, importSummary, academicYearOptions, groupOptions, subjectOptions, teacherOptions, classroomOptions, studentOptions, load, save, remove, saveResult, removeResult, importCsv, exportCsv, setFilters, resetFilters, select, selectById }
})
