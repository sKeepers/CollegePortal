import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'

const initialFilters = {
  academic_year: '',
  semester: '',
  group_id: '',
  teacher_id: '',
  classroom_id: '',
  subject_id: '',
  week_type: '',
  status: '',
  conflicts_only: false,
}

function extractRows(payload) {
  return Array.isArray(payload?.data) ? payload.data : []
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

  if (!year || !month) {
    return false
  }

  return (year === start && month >= 9) || (year === end && month <= 8)
}

function isLessonInSemester(lesson, semester) {
  if (!semester) {
    return true
  }

  const month = lessonMonth(lesson.lesson_date)

  if (!month) {
    return false
  }

  if (String(semester) === '1') {
    return month >= 9 || month <= 1
  }

  return month >= 2 && month <= 8
}

function overlaps(left, right) {
  if (!left?.starts_at || !left?.ends_at || !right?.starts_at || !right?.ends_at) {
    return false
  }

  return left.starts_at < right.ends_at && right.starts_at < left.ends_at
}

export function teacherName(teacher) {
  return [teacher?.last_name, teacher?.first_name, teacher?.middle_name].filter(Boolean).join(' ')
}

export function classroomLabel(classroom) {
  return [
    classroom?.number,
    classroom?.building ? `корп. ${classroom.building}` : '',
  ].filter(Boolean).join(' · ')
}

export const lessonTypeLabels = {
  lesson: 'Занятие',
  lecture: 'Лекция',
  practice: 'Практика',
  exam: 'Экзамен',
  consultation: 'Консультация',
}

export const lessonTypeTones = {
  lesson: 'info',
  lecture: 'neutral',
  practice: 'success',
  exam: 'danger',
  consultation: 'warning',
}

export const useScheduleStore = defineStore('schedule', () => {
  const lessons = ref([])
  const groups = ref([])
  const teachers = ref([])
  const subjects = ref([])
  const classrooms = ref([])
  const filters = ref({ ...initialFilters })
  const selectedId = ref(null)
  const conflicts = ref([])
  const coverage = ref([])
  const previewResult = ref(null)
  const templates = ref([])
  const moveDraft = ref(null)
  const loading = ref(false)
  const error = ref('')

  const selectedLesson = computed(() => (
    lessons.value.find((lesson) => Number(lesson.id) === Number(selectedId.value)) || null
  ))

  const groupOptions = computed(() => groups.value.map((group) => ({
    label: group.name,
    value: group.id,
    group,
  })))

  const teacherOptions = computed(() => teachers.value.map((teacher) => ({
    label: teacherName(teacher),
    value: teacher.id,
    teacher,
  })))

  const subjectOptions = computed(() => subjects.value.map((subject) => ({
    label: subject.name,
    value: subject.id,
    subject,
  })))

  const classroomOptions = computed(() => classrooms.value.map((classroom) => ({
    label: classroomLabel(classroom),
    value: classroom.id,
    classroom,
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

    if (years.size === 0) {
      const current = new Date()
      const start = current.getMonth() + 1 >= 9 ? current.getFullYear() : current.getFullYear() - 1
      years.add(`${start}/${start + 1}`)
    }

    return [...years].sort().reverse().map((year) => ({
      label: `${year} учебный год`,
      value: year,
    }))
  })

  const filteredLessons = computed(() => lessons.value.filter((lesson) => {
    const matchesAcademicYear = isLessonInAcademicYear(lesson, filters.value.academic_year)
    const matchesSemester = isLessonInSemester(lesson, filters.value.semester)

    return matchesAcademicYear && matchesSemester
  }))

  const selectedLessonConflicts = computed(() => {
    const lesson = selectedLesson.value

    if (!lesson) {
      return []
    }

    return lessons.value
      .filter((candidate) => Number(candidate.id) !== Number(lesson.id))
      .filter((candidate) => candidate.lesson_date === lesson.lesson_date && overlaps(candidate, lesson))
      .flatMap((candidate) => {
        const conflicts = []

        if (lesson.group_id && Number(candidate.group_id) === Number(lesson.group_id)) {
          conflicts.push(`Группа уже занята: ${candidate.subject?.name || 'занятие'} ${candidate.starts_at}-${candidate.ends_at}`)
        }

        if (lesson.teacher_id && Number(candidate.teacher_id) === Number(lesson.teacher_id)) {
          conflicts.push(`Преподаватель уже занят: ${candidate.group?.name || 'группа'} ${candidate.starts_at}-${candidate.ends_at}`)
        }

        if (lesson.classroom_id && Number(candidate.classroom_id) === Number(lesson.classroom_id)) {
          conflicts.push(`Аудитория уже занята: ${candidate.group?.name || 'группа'} ${candidate.starts_at}-${candidate.ends_at}`)
        }

        return conflicts
      })
  })

  async function load(range = {}) {
    loading.value = true
    error.value = ''

    try {
      const apiFilters = {
        group_id: filters.value.group_id,
        teacher_id: filters.value.teacher_id,
        subject_id: filters.value.subject_id,
        classroom_id: filters.value.classroom_id,
        date_from: range.date_from,
        date_to: range.date_to,
        per_page: 200,
      }

      const [lessonsResult, groupsResult, teachersResult, subjectsResult, classroomsResult, conflictsResult, coverageResult, templatesResult] = await Promise.allSettled([
        api.list('schedule-lessons', apiFilters),
        api.list('groups'),
        api.list('teachers', { active_only: 1 }),
        api.list('subjects'),
        api.list('classrooms'),
        api.list('schedule/conflicts', apiFilters),
        api.list('schedule/coverage', apiFilters),
        api.list('schedule/templates', apiFilters),
      ])

      if (lessonsResult.status === 'rejected') {
        throw lessonsResult.reason
      }

      lessons.value = extractRows(lessonsResult.value)
      groups.value = groupsResult.status === 'fulfilled' ? extractRows(groupsResult.value) : []
      teachers.value = teachersResult.status === 'fulfilled' ? extractRows(teachersResult.value) : []
      subjects.value = subjectsResult.status === 'fulfilled' ? extractRows(subjectsResult.value) : []
      classrooms.value = classroomsResult.status === 'fulfilled' ? extractRows(classroomsResult.value) : []
      conflicts.value = conflictsResult.status === 'fulfilled' ? extractRows(conflictsResult.value) : []
      coverage.value = coverageResult.status === 'fulfilled' ? extractRows(coverageResult.value) : []
      templates.value = templatesResult.status === 'fulfilled' ? extractRows(templatesResult.value) : []

      if (selectedId.value && !selectedLesson.value) {
        selectedId.value = null
      }
    } catch (err) {
      error.value = err.message || 'Не удалось загрузить расписание'
    } finally {
      loading.value = false
    }
  }

  function setFilters(nextFilters) {
    filters.value = {
      ...filters.value,
      ...nextFilters,
    }
  }

  function resetFilters() {
    filters.value = { ...initialFilters }
  }

  function selectLesson(lesson) {
    selectedId.value = lesson?.id || null
  }

  function selectLessonById(id) {
    selectedId.value = id || null
  }

  async function previewEntry(payload) {
    previewResult.value = await api.create('schedule/preview', payload)
    return previewResult.value
  }

  async function applyEntry(payload, range = {}) {
    const result = await api.create('schedule/apply', payload)
    await load(range)
    return result
  }

  async function previewMove(lesson, target) {
    const payload = {
      academic_year: filters.value.academic_year || '',
      semester: filters.value.semester || 1,
      date: target.date,
      lesson_number: target.lesson_number,
      starts_at: target.starts_at,
      ends_at: target.ends_at,
      group_id: lesson.group_id,
      subject_id: lesson.subject_id,
      teacher_id: lesson.teacher_id,
      classroom_id: lesson.classroom_id || '',
      status: lesson.status || 'scheduled',
      comment: lesson.topic || '',
    }
    moveDraft.value = { lesson, target, payload }
    previewResult.value = await api.create('schedule/preview', payload)
    return previewResult.value
  }

  async function applyMove(range = {}) {
    if (!moveDraft.value?.lesson?.schedule_entry_id) {
      throw new Error('Занятие создано до Schedule Engine и не может быть перенесено drag & drop.')
    }
    const lesson = moveDraft.value.lesson
    const target = moveDraft.value.target
    const result = await api.create(`schedule/entries/${lesson.schedule_entry_id}/move`, {
      date: target.date,
      lesson_number: target.lesson_number,
      starts_at: target.starts_at,
      ends_at: target.ends_at,
    })
    moveDraft.value = null
    await load(range)
    return result
  }

  async function createTemplate(payload, range = {}) {
    const result = await api.create('schedule/templates', payload)
    await load(range)
    return result
  }

  async function previewTemplateApply(templateId, payload) {
    previewResult.value = await api.create(`schedule/templates/${templateId}/apply-preview`, payload)
    return previewResult.value
  }

  async function applyTemplate(templateId, payload, range = {}) {
    const result = await api.create(`schedule/templates/${templateId}/apply`, payload)
    await load(range)
    return result
  }

  return {
    lessons,
    filteredLessons,
    conflicts,
    coverage,
    previewResult,
    templates,
    moveDraft,
    groups,
    teachers,
    subjects,
    classrooms,
    filters,
    selectedId,
    selectedLesson,
    selectedLessonConflicts,
    groupOptions,
    teacherOptions,
    subjectOptions,
    classroomOptions,
    academicYearOptions,
    loading,
    error,
    load,
    setFilters,
    resetFilters,
    selectLesson,
    selectLessonById,
    previewEntry,
    applyEntry,
    previewMove,
    applyMove,
    createTemplate,
    previewTemplateApply,
    applyTemplate,
  }
})
