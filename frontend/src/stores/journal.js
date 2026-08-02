import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'
import { teacherName } from './schedule'

const JOURNAL_FILTERS_KEY = 'collegePortal.journal.filters'

const initialFilters = {
  academic_year: '',
  semester: '',
  group_id: '',
  subject_id: '',
  teacher_id: '',
  date: '',
  date_from: '',
  date_to: '',
  mode: 'mine',
}

function extractRows(payload) {
  return Array.isArray(payload?.data) ? payload.data : []
}

function canUseLocalStorage() {
  return typeof window !== 'undefined' && Boolean(window.localStorage)
}

function loadStoredFilters() {
  if (!canUseLocalStorage()) return { ...initialFilters }
  try {
    const filters = { ...initialFilters, ...JSON.parse(window.localStorage.getItem(JOURNAL_FILTERS_KEY) || '{}') }
    if (filters.mode === 'today') filters.mode = 'mine'
    return filters
  } catch {
    return { ...initialFilters }
  }
}

function saveStoredFilters(filters) {
  if (canUseLocalStorage()) window.localStorage.setItem(JOURNAL_FILTERS_KEY, JSON.stringify(filters))
}

function lessonYear(date) { return Number(String(date || '').slice(0, 4)) }
function lessonMonth(date) { return Number(String(date || '').slice(5, 7)) }

function isLessonInAcademicYear(lesson, academicYear) {
  if (!academicYear) return true
  const [start, end] = String(academicYear).split('/').map(Number)
  const year = lessonYear(lesson.lesson_date)
  const month = lessonMonth(lesson.lesson_date)
  return (year === start && month >= 9) || (year === end && month <= 8)
}

function isLessonInSemester(lesson, semester) {
  if (!semester) return true
  const month = lessonMonth(lesson.lesson_date)
  return String(semester) === '1' ? month >= 9 || month <= 1 : month >= 2 && month <= 8
}

function fullName(person) {
  return [person?.last_name, person?.first_name, person?.middle_name].filter(Boolean).join(' ')
}

function compareStudents(left, right) {
  return [left?.last_name, left?.first_name, left?.middle_name]
    .join('\u0000')
    .localeCompare([right?.last_name, right?.first_name, right?.middle_name].join('\u0000'), 'ru', { sensitivity: 'base' })
}

function classroomLabel(classroom) {
  return [classroom?.number, classroom?.building ? `корп. ${classroom.building}` : ''].filter(Boolean).join(' · ')
}

function attendanceMark(status) {
  return { absent: 'Н', present: 'П', late: 'О', excused: 'У', sick: 'Б', remote: 'Д' }[status] || ''
}

export const useJournalStore = defineStore('journal', () => {
  const filters = ref(loadStoredFilters())
  const lessons = ref([])
  const groups = ref([])
  const teachers = ref([])
  const subjects = ref([])
  const students = ref([])
  const attendance = ref([])
  const grades = ref([])
  const files = ref([])
  const attendanceSuggestion = ref([])
  const selectedLessonId = ref(null)
  const loading = ref(false)
  const detailsLoading = ref(false)
  const saving = ref(false)
  const error = ref('')

  const filteredLessons = computed(() => lessons.value.filter((lesson) => (
    isLessonInAcademicYear(lesson, filters.value.academic_year)
    && isLessonInSemester(lesson, filters.value.semester)
    && (!filters.value.date || lesson.lesson_date === filters.value.date)
  )))

  const journalLessons = computed(() => filteredLessons.value.slice().sort((left, right) => `${left.lesson_date} ${left.starts_at}`.localeCompare(`${right.lesson_date} ${right.starts_at}`)).slice(0, 20))

  const selectedLesson = computed(() => (
    journalLessons.value.find((lesson) => Number(lesson.id) === Number(selectedLessonId.value))
    || journalLessons.value[0]
    || null
  ))

  const selectedAttendance = computed(() => selectedLesson.value?.attendance || attendance.value.filter((entry) => Number(entry.journal_lesson_id) === Number(selectedLesson.value?.id)))
  const selectedGrades = computed(() => selectedLesson.value?.grades || grades.value.filter((entry) => Number(entry.journal_lesson_id) === Number(selectedLesson.value?.id)))
  const selectedFiles = computed(() => selectedLesson.value?.files || files.value.filter((entry) => Number(entry.journal_lesson_id) === Number(selectedLesson.value?.id)))

  const lessonStudents = computed(() => selectedAttendance.value
    .slice()
    .sort((left, right) => compareStudents(left.student, right.student))
    .map((entry, index) => {
      const grade = selectedGrades.value.find((item) => Number(item.student_id) === Number(entry.student_id))
      return {
        number: index + 1,
        student: entry.student,
        student_id: entry.student_id,
        attendance_id: entry.id,
        attendance_status: entry.status || '',
        minutes_late: entry.minutes_late || '',
        attendance_comment: entry.comment || '',
        grade_id: grade?.id || null,
        grade_value: grade?.value || '',
        grade_comment: grade?.comment || '',
      }
    }))

  const dashboardStats = computed(() => ({
    total: lessons.value.length,
    today: lessons.value.filter((lesson) => filters.value.mode === 'today' || lesson.lesson_date === new Date().toISOString().slice(0, 10)).length,
    needsFill: lessons.value.filter((lesson) => ['draft', 'in_progress', 'reopened', 'planned', 'opened'].includes(lesson.status) || !lesson.topic).length,
    awaitingSign: lessons.value.filter((lesson) => lesson.status === 'completed').length,
    signed: lessons.value.filter((lesson) => lesson.status === 'signed').length,
  }))

  const groupOptions = computed(() => groups.value.map((group) => ({ label: group.name, value: group.id })))
  const teacherOptions = computed(() => teachers.value.map((teacher) => ({ label: teacherName(teacher), value: teacher.id })))
  const subjectOptions = computed(() => subjects.value.map((subject) => ({ label: subject.name, value: subject.id })))

  const academicYearOptions = computed(() => {
    const years = new Set()
    lessons.value.forEach((lesson) => {
      const year = lessonYear(lesson.lesson_date)
      const month = lessonMonth(lesson.lesson_date)
      if (!year || !month) return
      const start = month >= 9 ? year : year - 1
      years.add(`${start}/${start + 1}`)
    })
    return [...years].sort().reverse().map((year) => ({ label: `${year} учебный год`, value: year }))
  })

  const studentRows = computed(() => students.value
    .slice()
    .sort((left, right) => fullName(left).localeCompare(fullName(right), 'ru'))
    .map((student) => ({
      student,
      fullName: fullName(student),
      cells: journalLessons.value.map((lesson) => journalCell(student.id, lesson.id)),
    })))

  function journalCell(studentId, lessonId) {
    const grade = grades.value.find((entry) => Number(entry.student_id) === Number(studentId) && Number(entry.journal_lesson_id) === Number(lessonId) && entry.value)
    if (grade) return { value: String(grade.value), type: 'grade' }
    const attendanceEntry = attendance.value.find((entry) => Number(entry.student_id) === Number(studentId) && Number(entry.journal_lesson_id) === Number(lessonId))
    return { value: attendanceMark(attendanceEntry?.status), type: attendanceEntry?.status || 'empty' }
  }

  async function load() {
    loading.value = true
    error.value = ''
    try {
      const apiFilters = {
        group_id: filters.value.group_id,
        teacher_id: filters.value.teacher_id,
        subject_id: filters.value.subject_id,
        date: filters.value.date,
        date_from: filters.value.date_from,
        date_to: filters.value.date_to,
        mode: filters.value.mode,
      }
      const lessonsPayload = await api.list('journal/lessons', { ...apiFilters, per_page: 100 })
      lessons.value = extractRows(lessonsPayload)
      groups.value = [...new Map(lessons.value.filter((lesson) => lesson.group?.id).map((lesson) => [lesson.group.id, lesson.group])).values()]
      teachers.value = [...new Map(lessons.value.filter((lesson) => lesson.teacher?.id).map((lesson) => [lesson.teacher.id, lesson.teacher])).values()]
      subjects.value = [...new Map(lessons.value.filter((lesson) => lesson.subject?.id).map((lesson) => [lesson.subject.id, lesson.subject])).values()]
      if (!selectedLessonId.value && journalLessons.value[0]) selectedLessonId.value = journalLessons.value[0].id
      if (selectedLessonId.value && !journalLessons.value.some((lesson) => Number(lesson.id) === Number(selectedLessonId.value))) selectedLessonId.value = journalLessons.value[0]?.id || null
      await loadJournalData()
    } catch (err) {
      error.value = err.message || 'Не удалось загрузить журнал'
    } finally {
      loading.value = false
    }
  }

  async function loadJournalData() {
    detailsLoading.value = true
    try {
      const lessonIds = journalLessons.value.map((lesson) => lesson.id)
      if (lessonIds.length === 0) {
        students.value = []; attendance.value = []; grades.value = []; files.value = []; return
      }
      const lessonPayloads = await Promise.all(lessonIds.map((lessonId) => api.get(`journal/lessons/${lessonId}`)))
      const detailedLessons = lessonPayloads.map((payload) => payload.data).filter(Boolean)
      const byId = new Map(detailedLessons.map((lesson) => [Number(lesson.id), lesson]))
      lessons.value = lessons.value.map((lesson) => byId.get(Number(lesson.id)) || lesson)
      attendance.value = detailedLessons.flatMap((lesson) => lesson.attendance || [])
      grades.value = detailedLessons.flatMap((lesson) => lesson.grades || [])
      files.value = detailedLessons.flatMap((lesson) => lesson.files || [])
      students.value = [...new Map(attendance.value.map((entry) => [Number(entry.student_id), entry.student]).filter(([, student]) => student)).values()]
    } catch (err) {
      error.value = err.message || 'Не удалось загрузить данные журнала'
    } finally {
      detailsLoading.value = false
    }
  }

  function setFilters(nextFilters) {
    filters.value = { ...filters.value, ...nextFilters }
    saveStoredFilters(filters.value)
  }

  function resetFilters() {
    filters.value = { ...initialFilters }
    saveStoredFilters(filters.value)
  }

  async function selectLesson(lesson) {
    selectedLessonId.value = lesson?.id || null
    attendanceSuggestion.value = []
    await loadJournalData()
  }

  function replaceLesson(updated) {
    lessons.value = lessons.value.map((lesson) => Number(lesson.id) === Number(updated.id) ? updated : lesson)
  }

  async function saveLesson(data) {
    if (!selectedLesson.value?.id) return
    saving.value = true
    try {
      const payload = await api.put(`journal/lessons/${selectedLesson.value.id}`, data)
      replaceLesson(payload.data)
    } finally {
      saving.value = false
    }
  }

  async function completeLesson() {
    if (!selectedLesson.value?.id) return
    const payload = await api.post(`journal/lessons/${selectedLesson.value.id}/complete`)
    replaceLesson(payload.data)
  }

  async function signLesson() {
    if (!selectedLesson.value?.id) return
    const payload = await api.post(`journal/lessons/${selectedLesson.value.id}/sign`)
    replaceLesson(payload.data)
  }

  async function reopenLesson(reason) {
    if (!selectedLesson.value?.id) return
    const payload = await api.post(`journal/lessons/${selectedLesson.value.id}/reopen`, { reason })
    replaceLesson(payload.data)
  }

  async function saveAttendanceRows(rows) {
    if (!selectedLesson.value?.id) return
    const payload = await api.put(`journal/lessons/${selectedLesson.value.id}/attendance`, { attendance: rows })
    replaceLesson(payload.data)
    await loadJournalData()
  }

  async function saveGradeRows(rows) {
    if (!selectedLesson.value?.id) return
    const payload = await api.put(`journal/lessons/${selectedLesson.value.id}/grades`, { grades: rows })
    replaceLesson(payload.data)
    await loadJournalData()
  }

  async function markAllPresent() {
    await saveAttendanceRows(lessonStudents.value.map((row) => ({ student_id: row.student_id, status: 'present', comment: row.attendance_comment || null })))
  }

  async function markSelectedAbsent(studentIds) {
    const selected = new Set(studentIds.map(Number))
    await saveAttendanceRows(lessonStudents.value.filter((row) => selected.has(Number(row.student_id))).map((row) => ({ student_id: row.student_id, status: 'absent', comment: row.attendance_comment || null })))
  }

  async function loadAttendanceSuggestion() {
    if (!selectedLesson.value?.id) return []
    const payload = await api.get(`journal/lessons/${selectedLesson.value.id}/attendance-suggestion`)
    attendanceSuggestion.value = payload.data || []
    return attendanceSuggestion.value
  }

  async function applyAttendanceSuggestion() {
    if (!selectedLesson.value?.id) return
    const payload = await api.post(`journal/lessons/${selectedLesson.value.id}/attendance-suggestion/apply`)
    replaceLesson(payload.data)
    attendanceSuggestion.value = []
    await loadJournalData()
  }

  async function uploadLessonFile(file) {
    if (!selectedLesson.value?.id || !file) return
    const formData = new FormData()
    formData.append('file', file)
    await api.upload(`/journal/lessons/${selectedLesson.value.id}/files`, formData)
    await loadJournalData()
  }

  async function deleteLessonFile(fileId) {
    if (!selectedLesson.value?.id || !fileId) return
    await api.delete(`journal/lessons/${selectedLesson.value.id}/files`, fileId)
    await loadJournalData()
  }

  async function openFromSchedule(scheduleEntryId) {
    if (!scheduleEntryId) return
    const payload = await api.post(`journal/from-schedule/${scheduleEntryId}/open`)
    const opened = payload.data
    const exists = lessons.value.some((lesson) => Number(lesson.id) === Number(opened.id))
    lessons.value = exists ? lessons.value.map((lesson) => Number(lesson.id) === Number(opened.id) ? opened : lesson) : [opened, ...lessons.value]
    selectedLessonId.value = opened.id
    await loadJournalData()
  }

  async function requestEdit(reason) {
    if (!selectedLesson.value?.id) return
    const payload = await api.post(`journal/lessons/${selectedLesson.value.id}/edit-requests`, { reason })
    replaceLesson(payload.data)
  }

  async function reviewEditRequest(id, approved) {
    const payload = await api.post(`journal/edit-requests/${id}/review`, { approved })
    replaceLesson(payload.data)
  }

  async function openFromLegacySchedule(scheduleLessonId) {
    if (!scheduleLessonId) return
    const payload = await api.post(`journal/from-legacy-schedule/${scheduleLessonId}/open`)
    const opened = payload.data
    const exists = lessons.value.some((lesson) => Number(lesson.id) === Number(opened.id))
    lessons.value = exists ? lessons.value.map((lesson) => Number(lesson.id) === Number(opened.id) ? opened : lesson) : [opened, ...lessons.value]
    selectedLessonId.value = opened.id
    await loadJournalData()
  }

  function lessonLabel(lesson) {
    return [lesson?.lesson_date, lesson?.starts_at, lesson?.subject?.name].filter(Boolean).join(' · ')
  }

  return {
    filters, lessons, filteredLessons, journalLessons, groups, teachers, subjects, students, studentRows,
    attendance, grades, files, attendanceSuggestion, selectedLessonId, selectedLesson, selectedAttendance,
    selectedGrades, selectedFiles, lessonStudents, dashboardStats, groupOptions, teacherOptions, subjectOptions,
    academicYearOptions, loading, detailsLoading, saving, error, load, loadJournalData, setFilters, resetFilters,
    selectLesson, saveLesson, completeLesson, signLesson, reopenLesson, requestEdit, reviewEditRequest, saveAttendanceRows, saveGradeRows,
    markAllPresent, markSelectedAbsent, loadAttendanceSuggestion, applyAttendanceSuggestion, uploadLessonFile,
    deleteLessonFile, openFromSchedule, openFromLegacySchedule, lessonLabel, fullName, classroomLabel, attendanceMark,
  }
})
