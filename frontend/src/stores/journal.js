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
}

function extractRows(payload) {
  return Array.isArray(payload?.data) ? payload.data : []
}

function canUseLocalStorage() {
  return typeof window !== 'undefined' && Boolean(window.localStorage)
}

function loadStoredFilters() {
  if (!canUseLocalStorage()) {
    return { ...initialFilters }
  }

  try {
    return {
      ...initialFilters,
      ...JSON.parse(window.localStorage.getItem(JOURNAL_FILTERS_KEY) || '{}'),
    }
  } catch {
    return { ...initialFilters }
  }
}

function saveStoredFilters(filters) {
  if (!canUseLocalStorage()) {
    return
  }

  window.localStorage.setItem(JOURNAL_FILTERS_KEY, JSON.stringify(filters))
}

function lessonYear(date) {
  return Number(String(date || '').slice(0, 4))
}

function lessonMonth(date) {
  return Number(String(date || '').slice(5, 7))
}

function isLessonInAcademicYear(lesson, academicYear) {
  if (!academicYear) {
    return true
  }

  const [start, end] = String(academicYear).split('/').map(Number)
  const year = lessonYear(lesson.lesson_date)
  const month = lessonMonth(lesson.lesson_date)

  return (year === start && month >= 9) || (year === end && month <= 8)
}

function isLessonInSemester(lesson, semester) {
  if (!semester) {
    return true
  }

  const month = lessonMonth(lesson.lesson_date)

  if (String(semester) === '1') {
    return month >= 9 || month <= 1
  }

  return month >= 2 && month <= 8
}

function fullName(student) {
  return [student?.last_name, student?.first_name, student?.middle_name].filter(Boolean).join(' ')
}

function classroomLabel(classroom) {
  return [
    classroom?.number,
    classroom?.building ? `корп. ${classroom.building}` : '',
  ].filter(Boolean).join(' · ')
}

function attendanceMark(status) {
  const marks = {
    absent: 'Н',
    present: 'П',
    late: 'У',
    excused: 'У',
  }

  return marks[status] || ''
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
  const selectedLessonId = ref(null)
  const loading = ref(false)
  const detailsLoading = ref(false)
  const error = ref('')

  const filteredLessons = computed(() => lessons.value.filter((lesson) => (
    isLessonInAcademicYear(lesson, filters.value.academic_year)
    && isLessonInSemester(lesson, filters.value.semester)
    && (!filters.value.date || lesson.lesson_date === filters.value.date)
  )))

  const journalLessons = computed(() => filteredLessons.value
    .slice()
    .sort((left, right) => `${left.lesson_date} ${left.starts_at}`.localeCompare(`${right.lesson_date} ${right.starts_at}`))
    .slice(0, 8))

  const selectedLesson = computed(() => (
    journalLessons.value.find((lesson) => Number(lesson.id) === Number(selectedLessonId.value))
    || journalLessons.value[0]
    || null
  ))

  const effectiveGroupId = computed(() => (
    filters.value.group_id || selectedLesson.value?.group_id || ''
  ))

  const groupOptions = computed(() => groups.value.map((group) => ({
    label: group.name,
    value: group.id,
  })))

  const teacherOptions = computed(() => teachers.value.map((teacher) => ({
    label: teacherName(teacher),
    value: teacher.id,
  })))

  const subjectOptions = computed(() => subjects.value.map((subject) => ({
    label: subject.name,
    value: subject.id,
  })))

  const academicYearOptions = computed(() => {
    const years = new Set()

    lessons.value.forEach((lesson) => {
      const year = lessonYear(lesson.lesson_date)
      const month = lessonMonth(lesson.lesson_date)

      if (!year || !month) {
        return
      }

      const start = month >= 9 ? year : year - 1
      years.add(`${start}/${start + 1}`)
    })

    return [...years].sort().reverse().map((year) => ({
      label: `${year} учебный год`,
      value: year,
    }))
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
    const grade = grades.value.find((entry) => (
      Number(entry.student_id) === Number(studentId)
      && Number(entry.journal_lesson_id) === Number(lessonId)
      && entry.value
    ))

    if (grade) {
      return {
        value: String(grade.value),
        type: 'grade',
      }
    }

    const attendanceEntry = attendance.value.find((entry) => (
      Number(entry.student_id) === Number(studentId)
      && Number(entry.journal_lesson_id) === Number(lessonId)
    ))

    return {
      value: attendanceMark(attendanceEntry?.status),
      type: attendanceEntry?.status || 'empty',
    }
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
      }

      const [lessonsPayload, groupsPayload, teachersPayload, subjectsPayload] = await Promise.all([
        api.list('journal/lessons', { ...apiFilters, per_page: 50 }),
        api.list('groups'),
        api.list('teachers', { active_only: 1 }),
        api.list('subjects'),
      ])

      lessons.value = extractRows(lessonsPayload)
      groups.value = extractRows(groupsPayload)
      teachers.value = extractRows(teachersPayload)
      subjects.value = extractRows(subjectsPayload)

      if (!selectedLessonId.value && journalLessons.value[0]) {
        selectedLessonId.value = journalLessons.value[0].id
      }

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
        students.value = []
        attendance.value = []
        grades.value = []
        files.value = []
        return
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
    filters.value = {
      ...filters.value,
      ...nextFilters,
    }
    saveStoredFilters(filters.value)
  }

  function resetFilters() {
    filters.value = { ...initialFilters }
    saveStoredFilters(filters.value)
  }

  async function selectLesson(lesson) {
    selectedLessonId.value = lesson?.id || null
    await loadJournalData()
  }

  async function saveLesson(data) {
    if (!selectedLesson.value?.id) return
    const payload = await api.put(`journal/lessons/${selectedLesson.value.id}`, data)
    const updated = payload.data
    lessons.value = lessons.value.map((lesson) => Number(lesson.id) === Number(updated.id) ? updated : lesson)
  }

  async function completeLesson() {
    if (!selectedLesson.value?.id) return
    const payload = await api.post(`journal/lessons/${selectedLesson.value.id}/complete`)
    const updated = payload.data
    lessons.value = lessons.value.map((lesson) => Number(lesson.id) === Number(updated.id) ? updated : lesson)
  }

  async function signLesson() {
    if (!selectedLesson.value?.id) return
    const payload = await api.post(`journal/lessons/${selectedLesson.value.id}/sign`)
    const updated = payload.data
    lessons.value = lessons.value.map((lesson) => Number(lesson.id) === Number(updated.id) ? updated : lesson)
  }

  async function markAllPresent() {
    if (!selectedLesson.value?.id) return
    const payload = await api.put(`journal/lessons/${selectedLesson.value.id}/attendance`, {
      attendance: students.value.map((student) => ({ student_id: student.id, status: 'present' })),
    })
    const updated = payload.data
    lessons.value = lessons.value.map((lesson) => Number(lesson.id) === Number(updated.id) ? updated : lesson)
    await loadJournalData()
  }

  async function openFromSchedule(scheduleEntryId) {
    if (!scheduleEntryId) return
    const payload = await api.post(`journal/from-schedule/${scheduleEntryId}/open`)
    const opened = payload.data
    const exists = lessons.value.some((lesson) => Number(lesson.id) === Number(opened.id))
    lessons.value = exists
      ? lessons.value.map((lesson) => Number(lesson.id) === Number(opened.id) ? opened : lesson)
      : [opened, ...lessons.value]
    selectedLessonId.value = opened.id
    await loadJournalData()
  }

  function lessonLabel(lesson) {
    return [
      lesson?.lesson_date,
      lesson?.starts_at,
      lesson?.subject?.name,
    ].filter(Boolean).join(' · ')
  }

  return {
    filters,
    lessons,
    filteredLessons,
    journalLessons,
    groups,
    teachers,
    subjects,
    students,
    studentRows,
    attendance,
    grades,
    files,
    selectedLessonId,
    selectedLesson,
    effectiveGroupId,
    groupOptions,
    teacherOptions,
    subjectOptions,
    academicYearOptions,
    loading,
    detailsLoading,
    error,
    load,
    loadJournalData,
    setFilters,
    resetFilters,
    selectLesson,
    saveLesson,
    completeLesson,
    signLesson,
    markAllPresent,
    openFromSchedule,
    lessonLabel,
    fullName,
    classroomLabel,
  }
})
