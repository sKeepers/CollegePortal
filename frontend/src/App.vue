<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { api } from './services/api'

const sections = [
  { key: 'dashboard', label: 'Панель' },
  { key: 'students', label: 'Студенты', permission: 'manage_dictionaries' },
  { key: 'groups', label: 'Группы', permission: 'manage_dictionaries' },
  { key: 'specialties', label: 'Специальности', permission: 'manage_dictionaries' },
  { key: 'educationPrograms', label: 'Программы', permission: 'manage_dictionaries' },
  { key: 'applicantApplications', label: 'Заявления', permission: 'manage_dictionaries' },
  { key: 'teachers', label: 'Преподаватели', permission: 'manage_dictionaries' },
  { key: 'subjects', label: 'Дисциплины', permission: 'manage_dictionaries' },
  { key: 'classrooms', label: 'Аудитории', permission: 'manage_dictionaries' },
  { key: 'schedule', label: 'Расписание', permission: 'manage_schedule' },
  { key: 'journal', label: 'Журнал', permission: 'manage_journal' },
  { key: 'reports', label: 'Отчеты', permission: 'manage_journal' },
]

const activeSection = ref('dashboard')
const publicSection = ref('login')
const loading = ref(false)
const error = ref('')
const publicLoading = ref(false)
const publicError = ref('')
const publicStudyFormFilter = ref('all')
const publicProgramSearch = ref('')
const authUser = ref(null)
const selectedJournalLessonId = ref('')
const selectedStudentId = ref('')
const enrollmentForms = reactive({})
const groupImportFile = ref(null)
const groupImportSummary = ref(null)
const studentImportFile = ref(null)
const studentImportSummary = ref(null)
const teacherImportFile = ref(null)
const teacherImportSummary = ref(null)
const subjectImportFile = ref(null)
const subjectImportSummary = ref(null)
const classroomImportFile = ref(null)
const classroomImportSummary = ref(null)
const specialtyImportFile = ref(null)
const specialtyImportSummary = ref(null)
const educationProgramImportFile = ref(null)
const educationProgramImportSummary = ref(null)
const applicantApplicationImportFile = ref(null)
const applicantApplicationImportSummary = ref(null)
const loginForm = reactive({
  email: '',
  password: '',
})

const state = reactive({
  publicSpecialties: [],
  publicEducationPrograms: [],
  groups: [],
  specialties: [],
  educationPrograms: [],
  applicantApplications: [],
  students: [],
  teachers: [],
  subjects: [],
  classrooms: [],
  scheduleLessons: [],
  attendance: [],
  grades: [],
  attendanceReport: null,
  gradeReport: null,
})

const forms = reactive({
  group: {
    name: '',
    specialty: '',
    education_program_id: '',
    course: 1,
    year_start: new Date().getFullYear(),
    curator_id: '',
  },
  specialty: {
    code: '',
    name: '',
    education_level: 'Среднее профессиональное образование',
    qualification: '',
    normative_study_years: '',
    description: '',
  },
  educationProgram: {
    specialty_id: '',
    name: '',
    year_start: new Date().getFullYear(),
    study_form: 'Очная',
    study_years: '',
    is_active: true,
    description: '',
  },
  applicantApplication: {
    education_program_id: '',
    last_name: '',
    first_name: '',
    middle_name: '',
    birth_date: '',
    phone: '',
    email: '',
    education_base: 'after_9',
    status: 'new',
    submitted_at: new Date().toISOString().slice(0, 10),
    comment: '',
  },
  student: {
    group_id: '',
    last_name: '',
    first_name: '',
    middle_name: '',
    phone: '',
    email: '',
    status: 'active',
    enrollment_date: '',
  },
  teacher: {
    last_name: '',
    first_name: '',
    middle_name: '',
    phone: '',
    email: '',
    position: '',
    department: '',
    is_active: true,
  },
  subject: {
    name: '',
    code: '',
    department: '',
    description: '',
    teacher_ids: [],
  },
  classroom: {
    number: '',
    building: '',
    floor: '',
    capacity: '',
    type: '',
    description: '',
  },
  scheduleLesson: {
    group_id: '',
    teacher_id: '',
    subject_id: '',
    classroom_id: '',
    lesson_date: '',
    starts_at: '09:00',
    ends_at: '10:30',
    lesson_type: 'lesson',
    topic: '',
  },
})
const filters = reactive({
  students: {
    search: '',
    group_id: '',
    status: '',
  },
  applicantApplications: {
    search: '',
    education_program_id: '',
    status: '',
    education_base: '',
    documents: '',
    queue: '',
  },
  scheduleLessons: {
    group_id: '',
    teacher_id: '',
    subject_id: '',
    classroom_id: '',
    date: '',
  },
  attendanceReport: {
    group_id: '',
    date_from: '',
    date_to: '',
  },
  gradeReport: {
    group_id: '',
    subject_id: '',
    date_from: '',
    date_to: '',
  },
})
const editingIds = reactive({
  group: null,
  specialty: null,
  educationProgram: null,
  applicantApplication: null,
  student: null,
  teacher: null,
  subject: null,
  classroom: null,
  scheduleLesson: null,
})

const resourceFormKeys = {
  groups: 'group',
  specialties: 'specialty',
  'education-programs': 'educationProgram',
  'applicant-applications': 'applicantApplication',
  students: 'student',
  teachers: 'teacher',
  subjects: 'subject',
  classrooms: 'classroom',
  'schedule-lessons': 'scheduleLesson',
}

const studentStatusLabels = {
  active: 'Обучается',
  academic_leave: 'Академический отпуск',
  graduated: 'Выпущен',
  expelled: 'Отчислен',
}

const applicantApplicationStatusLabels = {
  new: 'Новое',
  accepted: 'Принято',
  needs_clarification: 'Требуется уточнение',
  rejected: 'Отклонено',
  enrolled: 'Зачислен',
}

const applicantEducationBaseLabels = {
  after_9: 'После 9 класса',
  after_11: 'После 11 класса',
}

const attendanceStatusLabels = {
  present: 'Присутствовал',
  absent: 'Отсутствовал',
  late: 'Опоздал',
  excused: 'Уважительная причина',
}

const lessonTypeLabels = {
  lesson: 'Занятие',
  lecture: 'Лекция',
  practice: 'Практика',
  exam: 'Экзамен',
  consultation: 'Консультация',
}

const gradeTypeLabels = {
  classwork: 'Работа на занятии',
  homework: 'Домашняя работа',
  test: 'Контрольная работа',
  exam: 'Экзамен',
  credit: 'Зачет',
}

const userPermissions = computed(() => authUser.value?.role?.permissions || [])
const isAdmin = computed(() => authUser.value?.role?.code === 'admin')
const visibleSections = computed(() =>
  sections.filter((section) => !section.permission || isAdmin.value || userPermissions.value.includes(section.permission)),
)
const activeTitle = computed(() => sections.find((item) => item.key === activeSection.value)?.label)

const stats = computed(() => [
  { label: 'Студенты', value: state.students.length },
  { label: 'Группы', value: state.groups.length },
  { label: 'Специальности', value: state.specialties.length },
  { label: 'Преподаватели', value: state.teachers.length },
  { label: 'Занятия', value: state.scheduleLessons.length },
])
const filteredApplicantApplications = computed(() => {
  return state.applicantApplications.filter((application) => {
    const isComplete = hasFullDocumentSet(application)

    if (filters.applicantApplications.documents === 'complete') {
      if (!isComplete) {
        return false
      }
    }

    if (filters.applicantApplications.documents === 'incomplete') {
      if (isComplete) {
        return false
      }
    }

    if (filters.applicantApplications.queue === 'ready') {
      return application.status !== 'enrolled' && isComplete
    }

    if (filters.applicantApplications.queue === 'incomplete') {
      return application.status !== 'enrolled' && !isComplete
    }

    if (filters.applicantApplications.queue === 'enrolled') {
      return application.status === 'enrolled'
    }

    return true
  })
})
const applicantReadinessSummary = computed(() => {
  const readyToEnroll = state.applicantApplications.filter(
    (application) => application.status !== 'enrolled' && hasFullDocumentSet(application),
  ).length
  const incompleteDocuments = state.applicantApplications.filter(
    (application) => application.status !== 'enrolled' && !hasFullDocumentSet(application),
  ).length
  const enrolled = state.applicantApplications.filter((application) => application.status === 'enrolled').length

  return [
    { key: 'ready', label: 'Готовы к зачислению', value: readyToEnroll },
    { key: 'incomplete', label: 'Неполный комплект', value: incompleteDocuments },
    { key: 'enrolled', label: 'Уже зачислены', value: enrolled },
  ]
})
const activeApplicantApplicationFilters = computed(() => {
  const selectedProgram = educationProgramOptions.value.find(
    (program) => Number(program.value) === Number(filters.applicantApplications.education_program_id),
  )
  const filterItems = []

  if (filters.applicantApplications.queue) {
    filterItems.push({
      key: 'queue',
      label: applicantReadinessSummary.value.find((item) => item.key === filters.applicantApplications.queue)?.label,
    })
  }

  if (filters.applicantApplications.search) {
    filterItems.push({ key: 'search', label: `Поиск: ${filters.applicantApplications.search}` })
  }

  if (selectedProgram) {
    filterItems.push({ key: 'education_program_id', label: `Программа: ${selectedProgram.label}` })
  }

  if (filters.applicantApplications.status) {
    filterItems.push({
      key: 'status',
      label: `Статус: ${applicantApplicationStatusLabel(filters.applicantApplications.status)}`,
    })
  }

  if (filters.applicantApplications.education_base) {
    filterItems.push({
      key: 'education_base',
      label: `База: ${applicantEducationBaseLabel(filters.applicantApplications.education_base)}`,
    })
  }

  if (filters.applicantApplications.documents) {
    filterItems.push({
      key: 'documents',
      label: filters.applicantApplications.documents === 'complete' ? 'Комплект полный' : 'Не хватает документов',
    })
  }

  return filterItems.filter((item) => item.label)
})
const applicantStatusSummary = computed(() => [
  { key: 'total', label: 'Всего', value: state.applicantApplications.length },
  ...Object.entries(applicantApplicationStatusLabels).map(([status, label]) => ({
    key: status,
    label,
    value: state.applicantApplications.filter((application) => application.status === status).length,
  })),
])
const applicantProgramSummary = computed(() => {
  const rows = new Map()

  state.applicantApplications.forEach((application) => {
    const program = application.education_program
    const key = program?.id || `unknown-${application.education_program_id || application.id}`
    const existing = rows.get(key) || {
      key,
      program: educationProgramLabel(program),
      studyForm: program?.study_form || '—',
      total: 0,
      accepted: 0,
      needsClarification: 0,
      enrolled: 0,
    }

    existing.total += 1
    existing.accepted += application.status === 'accepted' ? 1 : 0
    existing.needsClarification += application.status === 'needs_clarification' ? 1 : 0
    existing.enrolled += application.status === 'enrolled' ? 1 : 0
    rows.set(key, existing)
  })

  return [...rows.values()].sort((left, right) => right.total - left.total || left.program.localeCompare(right.program, 'ru'))
})
const applicantStudyFormSummary = computed(() => {
  const rows = new Map()

  state.applicantApplications.forEach((application) => {
    const studyForm = application.education_program?.study_form || 'Не указана'
    const existing = rows.get(studyForm) || { studyForm, total: 0, enrolled: 0 }

    existing.total += 1
    existing.enrolled += application.status === 'enrolled' ? 1 : 0
    rows.set(studyForm, existing)
  })

  return [...rows.values()].sort((left, right) => right.total - left.total || left.studyForm.localeCompare(right.studyForm, 'ru'))
})
const filteredStudents = computed(() => {
  const search = filters.students.search.trim().toLowerCase()

  return state.students.filter((student) => {
    const matchesSearch = search === '' || fullName(student).toLowerCase().includes(search)
    const matchesGroup = !filters.students.group_id || Number(student.group_id) === Number(filters.students.group_id)
    const matchesStatus = !filters.students.status || student.status === filters.students.status

    return matchesSearch && matchesGroup && matchesStatus
  })
})
const selectedStudent = computed(() =>
  state.students.find((student) => Number(student.id) === Number(selectedStudentId.value)) || null,
)
const selectedStudentAttendance = computed(() => {
  if (!selectedStudent.value) {
    return []
  }

  return state.attendance
    .filter((item) => Number(item.student_id) === Number(selectedStudent.value.id))
    .sort((left, right) => String(right.schedule_lesson?.lesson_date || '').localeCompare(String(left.schedule_lesson?.lesson_date || '')))
})
const selectedStudentGrades = computed(() => {
  if (!selectedStudent.value) {
    return []
  }

  return state.grades
    .filter((grade) => Number(grade.student_id) === Number(selectedStudent.value.id))
    .sort((left, right) => String(right.schedule_lesson?.lesson_date || '').localeCompare(String(left.schedule_lesson?.lesson_date || '')))
})
const selectedStudentAttendanceSummary = computed(() => {
  const rows = selectedStudentAttendance.value

  return {
    total: rows.length,
    present: rows.filter((item) => item.status === 'present').length,
    absent: rows.filter((item) => item.status === 'absent').length,
    late: rows.filter((item) => item.status === 'late').length,
    excused: rows.filter((item) => item.status === 'excused').length,
  }
})
const selectedStudentGradeSummary = computed(() => {
  const numericGrades = selectedStudentGrades.value
    .map((grade) => Number(grade.grade))
    .filter((grade) => !Number.isNaN(grade))
  const average = numericGrades.length
    ? (numericGrades.reduce((sum, grade) => sum + grade, 0) / numericGrades.length).toFixed(2)
    : null

  return {
    total: selectedStudentGrades.value.length,
    numeric: numericGrades.length,
    average,
  }
})
const filteredScheduleLessons = computed(() =>
  state.scheduleLessons.filter((lesson) => {
    const matchesDate = !filters.scheduleLessons.date || lesson.lesson_date === filters.scheduleLessons.date
    const matchesGroup = !filters.scheduleLessons.group_id || Number(lesson.group_id) === Number(filters.scheduleLessons.group_id)
    const matchesTeacher = !filters.scheduleLessons.teacher_id || Number(lesson.teacher_id) === Number(filters.scheduleLessons.teacher_id)
    const matchesSubject = !filters.scheduleLessons.subject_id || Number(lesson.subject_id) === Number(filters.scheduleLessons.subject_id)
    const matchesClassroom = !filters.scheduleLessons.classroom_id || Number(lesson.classroom_id) === Number(filters.scheduleLessons.classroom_id)

    return matchesDate && matchesGroup && matchesTeacher && matchesSubject && matchesClassroom
  }),
)
const attendanceReportRows = computed(() => state.attendanceReport?.students || [])
const attendanceReportSummary = computed(() => state.attendanceReport?.summary || null)
const gradeReportRows = computed(() => state.gradeReport?.students || [])
const gradeReportSummary = computed(() => state.gradeReport?.summary || null)

const groupOptions = computed(() => state.groups.map((group) => ({ value: group.id, label: group.name })))
const specialtyOptions = computed(() =>
  state.specialties.map((specialty) => ({
    value: specialty.id,
    label: `${specialty.code} ${specialty.name}`,
  })),
)
const educationProgramOptions = computed(() =>
  state.educationPrograms.map((program) => ({
    value: program.id,
    label: [
      program.name,
      program.specialty?.code,
      program.year_start,
      program.study_form,
    ].filter(Boolean).join(' · '),
  })),
)
const activeEducationProgramOptions = computed(() =>
  state.educationPrograms
    .filter((program) => program.is_active)
    .map((program) => ({
      value: program.id,
      label: [
        program.name,
        program.specialty?.code,
        program.year_start,
        program.study_form,
      ].filter(Boolean).join(' · '),
    })),
)
const teacherOptions = computed(() =>
  state.teachers.map((teacher) => ({
    value: teacher.id,
    label: [teacher.last_name, teacher.first_name].filter(Boolean).join(' '),
  })),
)
const subjectOptions = computed(() => state.subjects.map((subject) => ({ value: subject.id, label: subject.name })))
const classroomOptions = computed(() =>
  state.classrooms.map((classroom) => ({
    value: classroom.id,
    label: [classroom.building, classroom.number].filter(Boolean).join(', '),
  })),
)
const journalLessonOptions = computed(() =>
  state.scheduleLessons.map((lesson) => ({
    value: lesson.id,
    label: lessonLabel(lesson),
  })),
)
const selectedJournalLesson = computed(() =>
  state.scheduleLessons.find((lesson) => Number(lesson.id) === Number(selectedJournalLessonId.value)),
)
const publicProgramCards = computed(() =>
  state.publicEducationPrograms.map((program) => ({
    ...program,
    specialtyName: program.specialty ? `${program.specialty.code} ${program.specialty.name}` : 'Специальность не указана',
    qualification: program.specialty?.qualification || 'Квалификация уточняется',
    duration: formatStudyDuration(program.study_years || program.specialty?.normative_study_years),
  })),
)
const filteredPublicProgramCards = computed(() => {
  const search = publicProgramSearch.value.trim().toLowerCase()

  return publicProgramCards.value.filter((program) => {
    const matchesStudyForm = publicStudyFormFilter.value === 'all' || program.study_form === publicStudyFormFilter.value
    const searchableText = [
      program.name,
      program.study_form,
      program.specialty?.code,
      program.specialty?.name,
      program.specialtyName,
      program.qualification,
      program.description,
    ]
      .filter(Boolean)
      .join(' ')
      .toLowerCase()

    return matchesStudyForm && (search === '' || searchableText.includes(search))
  })
})
const journalStudents = computed(() => {
  const lesson = selectedJournalLesson.value

  if (!lesson) {
    return []
  }

  return state.students.filter((student) => Number(student.group_id) === Number(lesson.group_id))
})
const attendanceByStudent = computed(() => {
  const lessonId = Number(selectedJournalLessonId.value)
  return Object.fromEntries(
    state.attendance
      .filter((item) => Number(item.schedule_lesson_id) === lessonId)
      .map((item) => [Number(item.student_id), item]),
  )
})
const classworkGradeByStudent = computed(() => {
  const lessonId = Number(selectedJournalLessonId.value)
  return Object.fromEntries(
    state.grades
      .filter((grade) => Number(grade.schedule_lesson_id) === lessonId && grade.grade_type === 'classwork')
      .map((grade) => [Number(grade.student_id), grade]),
  )
})

function fullName(person) {
  return [person.last_name, person.first_name, person.middle_name].filter(Boolean).join(' ')
}

function lessonLabel(lesson) {
  return [
    lesson.lesson_date,
    `${lesson.starts_at}–${lesson.ends_at}`,
    lesson.group?.name,
    lesson.subject?.name,
  ]
    .filter(Boolean)
    .join(' · ')
}

function studentStatusLabel(status) {
  return studentStatusLabels[status] || status || '—'
}

function applicantApplicationStatusLabel(status) {
  return applicantApplicationStatusLabels[status] || status || '—'
}

function applicantEducationBaseLabel(base) {
  return applicantEducationBaseLabels[base] || base || '—'
}

function hasFullDocumentSet(application) {
  return application.documents_total_count > 0 && application.documents_received_count === application.documents_total_count
}

function applicantDocumentTotals(application) {
  const total = application.documents_total_count || application.documents?.length || 0
  const received = application.documents_received_count || application.documents?.filter((document) => document.is_received).length || 0

  return { total, received }
}

function missingApplicantDocumentTitles(application) {
  return (application.documents || [])
    .filter((document) => !document.is_received)
    .map((document) => document.title)
}

function applicantDocumentSummaryLabel(application) {
  const { total, received } = applicantDocumentTotals(application)

  if (total === 0) {
    return 'Документы не заведены'
  }

  if (received === total) {
    return `Комплект полный: ${received}/${total}`
  }

  const missing = missingApplicantDocumentTitles(application)

  return missing.length > 0
    ? `Не хватает: ${missing.join(', ')}`
    : `Документы: ${received}/${total}`
}

function applicantDocumentSummaryClass(application) {
  return hasFullDocumentSet(application) ? 'complete' : 'incomplete'
}

function formatDateTime(value) {
  if (!value) {
    return '—'
  }

  return new Date(value).toLocaleString('ru-RU', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

function attendanceStatusLabel(status) {
  return attendanceStatusLabels[status] || status || '—'
}

function lessonTypeLabel(type) {
  return lessonTypeLabels[type] || type || '—'
}

function gradeTypeLabel(type) {
  return gradeTypeLabels[type] || type || '—'
}

function groupName(groupId) {
  return state.groups.find((group) => group.id === groupId)?.name || '—'
}

function selectStudent(student) {
  selectedStudentId.value = String(student.id)
}

function studentEducationProgramLabel(student) {
  const group = student.group || state.groups.find((item) => Number(item.id) === Number(student.group_id))

  return educationProgramLabel(group?.education_program)
}

function educationProgramLabel(program) {
  if (!program) {
    return '—'
  }

  return [program.name, program.specialty?.code, program.study_form].filter(Boolean).join(' · ')
}

function formatStudyDuration(value) {
  if (!value) {
    return '—'
  }

  const numeric = Number(value)

  if (Number.isNaN(numeric)) {
    return value
  }

  const years = Math.trunc(numeric)
  const months = Math.round((numeric - years) * 12)
  const parts = []

  if (years > 0) {
    parts.push(`${years} ${years === 1 ? 'год' : years >= 2 && years <= 4 ? 'года' : 'лет'}`)
  }

  if (months > 0) {
    parts.push(`${months} ${months === 1 ? 'месяц' : months >= 2 && months <= 4 ? 'месяца' : 'месяцев'}`)
  }

  return parts.join(' ')
}

async function loadPublicCatalog() {
  publicLoading.value = true
  publicError.value = ''

  try {
    const [specialties, programs] = await Promise.all([
      api.list('public/specialties'),
      api.list('public/education-programs', { active_only: 1 }),
    ])

    state.publicSpecialties = specialties.data || []
    state.publicEducationPrograms = programs.data || []
  } catch (caught) {
    publicError.value = caught.message
  } finally {
    publicLoading.value = false
  }
}

async function openApplicantSection() {
  publicSection.value = 'applicant'

  if (state.publicSpecialties.length === 0 && state.publicEducationPrograms.length === 0) {
    await loadPublicCatalog()
  }
}

function can(permission) {
  return isAdmin.value || userPermissions.value.includes(permission)
}

async function loadAll() {
  loading.value = true
  error.value = ''

  try {
    const dictionaries = can('manage_dictionaries')
      ? await Promise.all([
          api.list('groups'),
          api.list('specialties'),
          api.list('education-programs'),
          api.list('applicant-applications', { ...filters.applicantApplications }),
          api.list('students', { ...filters.students }),
          api.list('teachers'),
          api.list('subjects'),
          api.list('classrooms'),
        ])
      : [{ data: [] }, { data: [] }, { data: [] }, { data: [] }, { data: [] }, { data: [] }, { data: [] }, { data: [] }]

    const scheduleLessons = can('manage_schedule') ? await api.list('schedule-lessons', { ...filters.scheduleLessons }) : { data: [] }
    const attendance = can('manage_journal') ? await api.list('attendance') : { data: [] }
    const grades = can('manage_journal') ? await api.list('grades') : { data: [] }

    state.groups = dictionaries[0].data || []
    state.specialties = dictionaries[1].data || []
    state.educationPrograms = dictionaries[2].data || []
    state.applicantApplications = dictionaries[3].data || []
    syncEnrollmentForms()
    state.students = dictionaries[4].data || []
    state.teachers = dictionaries[5].data || []
    state.subjects = dictionaries[6].data || []
    state.classrooms = dictionaries[7].data || []
    state.scheduleLessons = scheduleLessons.data || []
    state.attendance = attendance.data || []
    state.grades = grades.data || []

    if (!selectedJournalLessonId.value && state.scheduleLessons.length > 0) {
      selectedJournalLessonId.value = String(state.scheduleLessons[0].id)
    }
  } catch (caught) {
    error.value = caught.message
  } finally {
    loading.value = false
  }
}

function syncEnrollmentForms() {
  const today = new Date().toISOString().slice(0, 10)
  const applicationIds = new Set(state.applicantApplications.map((application) => String(application.id)))

  state.applicantApplications.forEach((application) => {
    if (!enrollmentForms[application.id]) {
      enrollmentForms[application.id] = {
        group_id: '',
        enrollment_date: today,
      }
    }
  })

  Object.keys(enrollmentForms).forEach((id) => {
    if (!applicationIds.has(id)) {
      delete enrollmentForms[id]
    }
  })
}

async function applyFilters() {
  await loadAll()
}

async function resetStudentFilters() {
  Object.assign(filters.students, {
    search: '',
    group_id: '',
    status: '',
  })
  await loadAll()
}

async function resetApplicantApplicationFilters() {
  Object.assign(filters.applicantApplications, {
    search: '',
    education_program_id: '',
    status: '',
    education_base: '',
    documents: '',
    queue: '',
  })
  await loadAll()
}

async function applyApplicantReadinessFilter(queue) {
  filters.applicantApplications.queue = filters.applicantApplications.queue === queue ? '' : queue
  filters.applicantApplications.documents = ''
  filters.applicantApplications.status = ''
  await loadAll()
}

async function clearApplicantApplicationFilter(key) {
  filters.applicantApplications[key] = ''
  await loadAll()
}

async function resetScheduleFilters() {
  Object.assign(filters.scheduleLessons, {
    group_id: '',
    teacher_id: '',
    subject_id: '',
    classroom_id: '',
    date: '',
  })
  await loadAll()
}

async function bootstrapAuth() {
  if (!api.hasSession()) {
    return
  }

  loading.value = true
  error.value = ''

  try {
    const payload = await api.me()
    authUser.value = payload.data
    await loadAll()
  } catch {
    api.clearSession()
    authUser.value = null
  } finally {
    loading.value = false
  }
}

async function login() {
  loading.value = true
  error.value = ''

  try {
    const payload = await api.login(loginForm)
    authUser.value = payload.user
    await loadAll()
  } catch (caught) {
    error.value = caught.message
  } finally {
    loading.value = false
  }
}

async function logout() {
  loading.value = true
  error.value = ''

  try {
    await api.logout()
  } catch {
    // Даже если сервер не ответил, признак сессии на стороне браузера снять надо:
    // сам токен лежит в httpOnly cookie и отсюда недостижим.
  } finally {
    api.clearSession()
    authUser.value = null
    activeSection.value = 'dashboard'
    loading.value = false
  }
}

function normalizePayload(payload) {
  return Object.fromEntries(
    Object.entries(payload)
      .map(([key, value]) => [key, value === '' ? null : value])
      .filter(([, value]) => value !== null),
  )
}

function formKeyForResource(resource) {
  return resourceFormKeys[resource]
}

function valueOrEmpty(value) {
  return value ?? ''
}

function normalizeForForm(item, fields) {
  return Object.fromEntries(fields.map((field) => [field, valueOrEmpty(item[field])]))
}

function buildQuery(params) {
  const query = new URLSearchParams()

  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') {
      query.set(key, value)
    }
  })

  return query.toString()
}

async function submit(resource, form, reset) {
  loading.value = true
  error.value = ''

  try {
    const formKey = formKeyForResource(resource)
    const editingId = formKey ? editingIds[formKey] : null

    if (editingId) {
      await api.update(resource, editingId, normalizePayload(form))
      editingIds[formKey] = null
    } else {
      await api.create(resource, normalizePayload(form))
    }

    reset()
    await loadAll()
  } catch (caught) {
    error.value = caught.message
  } finally {
    loading.value = false
  }
}

function editItem(resource, item) {
  const formKey = formKeyForResource(resource)

  if (!formKey) {
    return
  }

  editingIds[formKey] = item.id

  if (formKey === 'group') {
    Object.assign(forms.group, normalizeForForm(item, ['name', 'specialty', 'education_program_id', 'course', 'year_start', 'curator_id']))
  }

  if (formKey === 'specialty') {
    Object.assign(forms.specialty, normalizeForForm(item, [
      'code',
      'name',
      'education_level',
      'qualification',
      'normative_study_years',
      'description',
    ]))
  }

  if (formKey === 'educationProgram') {
    Object.assign(forms.educationProgram, normalizeForForm(item, [
      'specialty_id',
      'name',
      'year_start',
      'study_form',
      'study_years',
      'is_active',
      'description',
    ]))
  }

  if (formKey === 'applicantApplication') {
    Object.assign(forms.applicantApplication, normalizeForForm(item, [
      'education_program_id',
      'last_name',
      'first_name',
      'middle_name',
      'birth_date',
      'phone',
      'email',
      'education_base',
      'status',
      'submitted_at',
      'comment',
    ]))
  }

  if (formKey === 'student') {
    Object.assign(forms.student, normalizeForForm(item, [
      'group_id',
      'last_name',
      'first_name',
      'middle_name',
      'phone',
      'email',
      'status',
      'enrollment_date',
    ]))
  }

  if (formKey === 'teacher') {
    Object.assign(forms.teacher, normalizeForForm(item, [
      'last_name',
      'first_name',
      'middle_name',
      'phone',
      'email',
      'position',
      'department',
      'is_active',
    ]))
  }

  if (formKey === 'subject') {
    Object.assign(forms.subject, {
      ...normalizeForForm(item, ['name', 'code', 'department', 'description']),
      teacher_ids: item.teachers?.map((teacher) => teacher.id) || [],
    })
  }

  if (formKey === 'classroom') {
    Object.assign(forms.classroom, normalizeForForm(item, [
      'number',
      'building',
      'floor',
      'capacity',
      'type',
      'description',
    ]))
  }

  if (formKey === 'scheduleLesson') {
    Object.assign(forms.scheduleLesson, normalizeForForm(item, [
      'group_id',
      'teacher_id',
      'subject_id',
      'classroom_id',
      'lesson_date',
      'starts_at',
      'ends_at',
      'lesson_type',
      'topic',
    ]))
  }
}

function cancelEdit(formKey, reset) {
  editingIds[formKey] = null
  reset()
}

async function removeItem(resource, item, label) {
  if (!window.confirm(`Удалить запись «${label}»?`)) {
    return
  }

  loading.value = true
  error.value = ''

  try {
    await api.delete(resource, item.id)
    const formKey = formKeyForResource(resource)

    if (formKey && editingIds[formKey] === item.id) {
      editingIds[formKey] = null
    }

    await loadAll()
  } catch (caught) {
    error.value = caught.message
  } finally {
    loading.value = false
  }
}

async function exportStudents() {
  loading.value = true
  error.value = ''

  try {
    const blob = await api.download('/students/export')
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')

    link.href = url
    link.download = 'students.csv'
    link.click()
    URL.revokeObjectURL(url)
  } catch (caught) {
    error.value = caught.message
  } finally {
    loading.value = false
  }
}

async function exportApplicantApplications() {
  loading.value = true
  error.value = ''

  try {
    const query = buildQuery(filters.applicantApplications)
    const blob = await api.download(`/applicant-applications/export${query ? `?${query}` : ''}`)
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')

    link.href = url
    link.download = 'applicant-applications.csv'
    link.click()
    URL.revokeObjectURL(url)
  } catch (caught) {
    error.value = caught.message
  } finally {
    loading.value = false
  }
}

async function importApplicantApplications() {
  if (!applicantApplicationImportFile.value) {
    error.value = 'Выберите CSV-файл для импорта заявлений.'
    return
  }

  loading.value = true
  error.value = ''
  applicantApplicationImportSummary.value = null

  try {
    const formData = new FormData()
    formData.append('file', applicantApplicationImportFile.value)

    const payload = await api.upload('/applicant-applications/import', formData)
    applicantApplicationImportSummary.value = payload.data
    applicantApplicationImportFile.value = null
    await loadAll()
  } catch (caught) {
    error.value = caught.message
  } finally {
    loading.value = false
  }
}

function onApplicantApplicationImportFileChange(event) {
  applicantApplicationImportFile.value = event.target.files?.[0] || null
}

async function enrollApplicantApplication(application) {
  const enrollmentForm = enrollmentForms[application.id]

  if (!enrollmentForm?.group_id) {
    error.value = 'Выберите группу для зачисления.'
    return
  }

  loading.value = true
  error.value = ''

  try {
    await api.create(`applicant-applications/${application.id}/enroll`, {
      group_id: enrollmentForm.group_id,
      enrollment_date: enrollmentForm.enrollment_date,
    })
    await loadAll()
  } catch (caught) {
    error.value = caught.message
  } finally {
    loading.value = false
  }
}

async function updateApplicantApplicationDocument(application, document, changes = {}) {
  const nextDocument = { ...document, ...changes }

  if ('is_received' in changes && nextDocument.is_received && !nextDocument.received_at) {
    nextDocument.received_at = new Date().toISOString().slice(0, 10)
  }

  if (!nextDocument.is_received) {
    nextDocument.received_at = null
  }

  loading.value = true
  error.value = ''

  try {
    await api.update(`applicant-applications/${application.id}/documents`, document.type, {
      is_received: nextDocument.is_received,
      received_at: nextDocument.received_at || null,
      number: nextDocument.number,
      comment: nextDocument.comment,
    })
    await loadAll()
  } catch (caught) {
    error.value = caught.message
  } finally {
    loading.value = false
  }
}

async function exportGroups() {
  loading.value = true
  error.value = ''

  try {
    const blob = await api.download('/groups/export')
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')

    link.href = url
    link.download = 'groups.csv'
    link.click()
    URL.revokeObjectURL(url)
  } catch (caught) {
    error.value = caught.message
  } finally {
    loading.value = false
  }
}

async function importGroups() {
  if (!groupImportFile.value) {
    error.value = 'Выберите CSV-файл для импорта групп.'
    return
  }

  loading.value = true
  error.value = ''
  groupImportSummary.value = null

  try {
    const formData = new FormData()
    formData.append('file', groupImportFile.value)

    const payload = await api.upload('/groups/import', formData)
    groupImportSummary.value = payload.data
    groupImportFile.value = null
    await loadAll()
  } catch (caught) {
    error.value = caught.message
  } finally {
    loading.value = false
  }
}

function onGroupImportFileChange(event) {
  groupImportFile.value = event.target.files?.[0] || null
}

async function exportSpecialties() {
  loading.value = true
  error.value = ''

  try {
    const blob = await api.download('/specialties/export')
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')

    link.href = url
    link.download = 'specialties.csv'
    link.click()
    URL.revokeObjectURL(url)
  } catch (caught) {
    error.value = caught.message
  } finally {
    loading.value = false
  }
}

async function importSpecialties() {
  if (!specialtyImportFile.value) {
    error.value = 'Выберите CSV-файл для импорта специальностей.'
    return
  }

  loading.value = true
  error.value = ''
  specialtyImportSummary.value = null

  try {
    const formData = new FormData()
    formData.append('file', specialtyImportFile.value)

    const payload = await api.upload('/specialties/import', formData)
    specialtyImportSummary.value = payload.data
    specialtyImportFile.value = null
    await loadAll()
  } catch (caught) {
    error.value = caught.message
  } finally {
    loading.value = false
  }
}

function onSpecialtyImportFileChange(event) {
  specialtyImportFile.value = event.target.files?.[0] || null
}

async function exportEducationPrograms() {
  loading.value = true
  error.value = ''

  try {
    const blob = await api.download('/education-programs/export')
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')

    link.href = url
    link.download = 'education-programs.csv'
    link.click()
    URL.revokeObjectURL(url)
  } catch (caught) {
    error.value = caught.message
  } finally {
    loading.value = false
  }
}

async function importEducationPrograms() {
  if (!educationProgramImportFile.value) {
    error.value = 'Выберите CSV-файл для импорта образовательных программ.'
    return
  }

  loading.value = true
  error.value = ''
  educationProgramImportSummary.value = null

  try {
    const formData = new FormData()
    formData.append('file', educationProgramImportFile.value)

    const payload = await api.upload('/education-programs/import', formData)
    educationProgramImportSummary.value = payload.data
    educationProgramImportFile.value = null
    await loadAll()
  } catch (caught) {
    error.value = caught.message
  } finally {
    loading.value = false
  }
}

function onEducationProgramImportFileChange(event) {
  educationProgramImportFile.value = event.target.files?.[0] || null
}

async function importStudents() {
  if (!studentImportFile.value) {
    error.value = 'Выберите CSV-файл для импорта.'
    return
  }

  loading.value = true
  error.value = ''
  studentImportSummary.value = null

  try {
    const formData = new FormData()
    formData.append('file', studentImportFile.value)

    const payload = await api.upload('/students/import', formData)
    studentImportSummary.value = payload.data
    studentImportFile.value = null
    await loadAll()
  } catch (caught) {
    error.value = caught.message
  } finally {
    loading.value = false
  }
}

function onStudentImportFileChange(event) {
  studentImportFile.value = event.target.files?.[0] || null
}

async function exportTeachers() {
  loading.value = true
  error.value = ''

  try {
    const blob = await api.download('/teachers/export')
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')

    link.href = url
    link.download = 'teachers.csv'
    link.click()
    URL.revokeObjectURL(url)
  } catch (caught) {
    error.value = caught.message
  } finally {
    loading.value = false
  }
}

async function importTeachers() {
  if (!teacherImportFile.value) {
    error.value = 'Выберите CSV-файл для импорта преподавателей.'
    return
  }

  loading.value = true
  error.value = ''
  teacherImportSummary.value = null

  try {
    const formData = new FormData()
    formData.append('file', teacherImportFile.value)

    const payload = await api.upload('/teachers/import', formData)
    teacherImportSummary.value = payload.data
    teacherImportFile.value = null
    await loadAll()
  } catch (caught) {
    error.value = caught.message
  } finally {
    loading.value = false
  }
}

function onTeacherImportFileChange(event) {
  teacherImportFile.value = event.target.files?.[0] || null
}

async function exportSubjects() {
  loading.value = true
  error.value = ''

  try {
    const blob = await api.download('/subjects/export')
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')

    link.href = url
    link.download = 'subjects.csv'
    link.click()
    URL.revokeObjectURL(url)
  } catch (caught) {
    error.value = caught.message
  } finally {
    loading.value = false
  }
}

async function importSubjects() {
  if (!subjectImportFile.value) {
    error.value = 'Выберите CSV-файл для импорта дисциплин.'
    return
  }

  loading.value = true
  error.value = ''
  subjectImportSummary.value = null

  try {
    const formData = new FormData()
    formData.append('file', subjectImportFile.value)

    const payload = await api.upload('/subjects/import', formData)
    subjectImportSummary.value = payload.data
    subjectImportFile.value = null
    await loadAll()
  } catch (caught) {
    error.value = caught.message
  } finally {
    loading.value = false
  }
}

function onSubjectImportFileChange(event) {
  subjectImportFile.value = event.target.files?.[0] || null
}

async function exportClassrooms() {
  loading.value = true
  error.value = ''

  try {
    const blob = await api.download('/classrooms/export')
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')

    link.href = url
    link.download = 'classrooms.csv'
    link.click()
    URL.revokeObjectURL(url)
  } catch (caught) {
    error.value = caught.message
  } finally {
    loading.value = false
  }
}

async function importClassrooms() {
  if (!classroomImportFile.value) {
    error.value = 'Выберите CSV-файл для импорта аудиторий.'
    return
  }

  loading.value = true
  error.value = ''
  classroomImportSummary.value = null

  try {
    const formData = new FormData()
    formData.append('file', classroomImportFile.value)

    const payload = await api.upload('/classrooms/import', formData)
    classroomImportSummary.value = payload.data
    classroomImportFile.value = null
    await loadAll()
  } catch (caught) {
    error.value = caught.message
  } finally {
    loading.value = false
  }
}

function onClassroomImportFileChange(event) {
  classroomImportFile.value = event.target.files?.[0] || null
}

async function loadAttendanceReport() {
  if (!filters.attendanceReport.group_id) {
    error.value = 'Выберите группу для отчета.'
    return
  }

  loading.value = true
  error.value = ''

  try {
    const payload = await api.list('reports/attendance-by-group', { ...filters.attendanceReport })
    state.attendanceReport = payload.data
  } catch (caught) {
    error.value = caught.message
  } finally {
    loading.value = false
  }
}

async function exportAttendanceReport() {
  if (!filters.attendanceReport.group_id) {
    error.value = 'Выберите группу для экспорта отчета.'
    return
  }

  loading.value = true
  error.value = ''

  try {
    const query = buildQuery(filters.attendanceReport)
    const blob = await api.download(`/reports/attendance-by-group/export?${query}`)
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')

    link.href = url
    link.download = 'attendance-report.csv'
    link.click()
    URL.revokeObjectURL(url)
  } catch (caught) {
    error.value = caught.message
  } finally {
    loading.value = false
  }
}

async function loadGradeReport() {
  if (!filters.gradeReport.group_id || !filters.gradeReport.subject_id) {
    error.value = 'Выберите группу и дисциплину для отчета.'
    return
  }

  loading.value = true
  error.value = ''

  try {
    const payload = await api.list('reports/grades-by-group', { ...filters.gradeReport })
    state.gradeReport = payload.data
  } catch (caught) {
    error.value = caught.message
  } finally {
    loading.value = false
  }
}

async function exportGradeReport() {
  if (!filters.gradeReport.group_id || !filters.gradeReport.subject_id) {
    error.value = 'Выберите группу и дисциплину для экспорта отчета.'
    return
  }

  loading.value = true
  error.value = ''

  try {
    const query = buildQuery(filters.gradeReport)
    const blob = await api.download(`/reports/grades-by-group/export?${query}`)
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')

    link.href = url
    link.download = 'grades-report.csv'
    link.click()
    URL.revokeObjectURL(url)
  } catch (caught) {
    error.value = caught.message
  } finally {
    loading.value = false
  }
}

async function saveAttendance(student, status) {
  if (!selectedJournalLesson.value || !status) {
    return
  }

  loading.value = true
  error.value = ''

  try {
    const existing = attendanceByStudent.value[Number(student.id)]
    const payload = {
      schedule_lesson_id: selectedJournalLesson.value.id,
      student_id: student.id,
      status,
    }

    if (existing) {
      await api.update('attendance', existing.id, { status })
    } else {
      await api.create('attendance', payload)
    }

    await loadAll()
  } catch (caught) {
    error.value = caught.message
  } finally {
    loading.value = false
  }
}

async function saveClassworkGrade(student, grade) {
  if (!selectedJournalLesson.value || !grade) {
    return
  }

  loading.value = true
  error.value = ''

  try {
    const existing = classworkGradeByStudent.value[Number(student.id)]
    const payload = {
      schedule_lesson_id: selectedJournalLesson.value.id,
      student_id: student.id,
      grade,
      grade_type: 'classwork',
    }

    if (existing) {
      await api.update('grades', existing.id, { grade })
    } else {
      await api.create('grades', payload)
    }

    await loadAll()
  } catch (caught) {
    error.value = caught.message
  } finally {
    loading.value = false
  }
}

function resetGroupForm() {
  Object.assign(forms.group, {
    name: '',
    specialty: '',
    education_program_id: '',
    course: 1,
    year_start: new Date().getFullYear(),
    curator_id: '',
  })
}

function resetSpecialtyForm() {
  Object.assign(forms.specialty, {
    code: '',
    name: '',
    education_level: 'Среднее профессиональное образование',
    qualification: '',
    normative_study_years: '',
    description: '',
  })
}

function resetEducationProgramForm() {
  Object.assign(forms.educationProgram, {
    specialty_id: '',
    name: '',
    year_start: new Date().getFullYear(),
    study_form: 'Очная',
    study_years: '',
    is_active: true,
    description: '',
  })
}

function resetApplicantApplicationForm() {
  Object.assign(forms.applicantApplication, {
    education_program_id: '',
    last_name: '',
    first_name: '',
    middle_name: '',
    birth_date: '',
    phone: '',
    email: '',
    education_base: 'after_9',
    status: 'new',
    submitted_at: new Date().toISOString().slice(0, 10),
    comment: '',
  })
}

function resetStudentForm() {
  Object.assign(forms.student, {
    group_id: '',
    last_name: '',
    first_name: '',
    middle_name: '',
    phone: '',
    email: '',
    status: 'active',
    enrollment_date: '',
  })
}

function resetTeacherForm() {
  Object.assign(forms.teacher, {
    last_name: '',
    first_name: '',
    middle_name: '',
    phone: '',
    email: '',
    position: '',
    department: '',
    is_active: true,
  })
}

function resetSubjectForm() {
  Object.assign(forms.subject, {
    name: '',
    code: '',
    department: '',
    description: '',
    teacher_ids: [],
  })
}

function resetClassroomForm() {
  Object.assign(forms.classroom, {
    number: '',
    building: '',
    floor: '',
    capacity: '',
    type: '',
    description: '',
  })
}

function resetScheduleLessonForm() {
  Object.assign(forms.scheduleLesson, {
    group_id: '',
    teacher_id: '',
    subject_id: '',
    classroom_id: '',
    lesson_date: '',
    starts_at: '09:00',
    ends_at: '10:30',
    lesson_type: 'lesson',
    topic: '',
  })
}

onMounted(async () => {
  await loadPublicCatalog()
  await bootstrapAuth()
})
</script>

<template>
  <main v-if="!authUser && publicSection === 'applicant'" class="public-screen">
    <header class="public-header">
      <div class="brand login-brand">
        <div class="brand-mark">CP</div>
        <div>
          <strong>CollegePortal</strong>
          <span>Колледж искусств</span>
        </div>
      </div>
      <button class="secondary-public-button" type="button" @click="publicSection = 'login'">Вход в портал</button>
    </header>

    <section class="public-intro">
      <div>
        <p class="eyebrow">Абитуриенту</p>
        <h1>Специальности и образовательные программы</h1>
      </div>
      <p>
        Выберите направление подготовки и посмотрите квалификацию, форму обучения и срок освоения программы.
      </p>
    </section>

    <div v-if="publicLoading" class="loading-line">Загружаем программы...</div>
    <div v-if="publicError" class="notice">{{ publicError }}</div>

    <section class="public-filter-panel" aria-label="Фильтр программ">
      <label class="public-search-field">
        Поиск
        <input
          v-model="publicProgramSearch"
          type="search"
          placeholder="Код, специальность, квалификация"
        />
      </label>
      <div>
        <span>Форма обучения</span>
        <div class="public-filter-actions">
          <button
            type="button"
            :class="{ active: publicStudyFormFilter === 'all' }"
            @click="publicStudyFormFilter = 'all'"
          >
            Все
          </button>
          <button
            type="button"
            :class="{ active: publicStudyFormFilter === 'Очная' }"
            @click="publicStudyFormFilter = 'Очная'"
          >
            Очная
          </button>
          <button
            type="button"
            :class="{ active: publicStudyFormFilter === 'Заочная' }"
            @click="publicStudyFormFilter = 'Заочная'"
          >
            Заочная
          </button>
        </div>
      </div>
      <strong class="public-filter-count">Найдено: {{ filteredPublicProgramCards.length }}</strong>
    </section>

    <section class="program-card-grid">
      <article v-for="program in filteredPublicProgramCards" :key="program.id" class="program-card">
        <div class="program-card-header">
          <span>{{ program.specialty?.code || 'СПО' }}</span>
          <strong>{{ program.study_form }}</strong>
        </div>
        <h2>{{ program.name }}</h2>
        <dl>
          <div>
            <dt>Специальность</dt>
            <dd>{{ program.specialtyName }}</dd>
          </div>
          <div>
            <dt>Квалификация</dt>
            <dd>{{ program.qualification }}</dd>
          </div>
          <div>
            <dt>Год начала</dt>
            <dd>{{ program.year_start }}</dd>
          </div>
          <div>
            <dt>Срок обучения</dt>
            <dd>{{ program.duration }}</dd>
          </div>
        </dl>
        <p v-if="program.description">{{ program.description }}</p>
      </article>
      <article v-if="!publicLoading && filteredPublicProgramCards.length === 0" class="program-card empty-program-card">
        Программ с выбранной формой обучения пока нет.
      </article>
    </section>

    <section class="public-specialties panel">
      <h2>Все специальности</h2>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Код</th><th>Название</th><th>Квалификация</th><th>Срок</th></tr></thead>
          <tbody>
            <tr v-for="specialty in state.publicSpecialties" :key="specialty.id">
              <td>{{ specialty.code }}</td>
              <td>{{ specialty.name }}</td>
              <td>{{ specialty.qualification || '—' }}</td>
              <td>{{ specialty.normative_study_years || '—' }}</td>
            </tr>
            <tr v-if="!publicLoading && state.publicSpecialties.length === 0">
              <td colspan="4">Специальности пока не опубликованы.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </main>

  <main v-else-if="!authUser" class="login-screen">
    <section class="login-panel">
      <div class="brand login-brand">
        <div class="brand-mark">CP</div>
        <div>
          <strong>CollegePortal</strong>
          <span>Колледж искусств</span>
        </div>
      </div>

      <form class="login-form" @submit.prevent="login">
        <div>
          <p class="eyebrow">Вход</p>
          <h1>Учебный портал</h1>
        </div>

        <label>Email <input v-model="loginForm.email" autocomplete="username" type="email" required /></label>
        <label>Пароль <input v-model="loginForm.password" autocomplete="current-password" type="password" required /></label>

        <button type="submit" :disabled="loading">Войти</button>
        <button class="public-link-button" type="button" @click="openApplicantSection">Абитуриенту</button>
        <div v-if="error" class="notice">{{ error }}</div>
      </form>
    </section>
  </main>

  <div v-else class="app-shell">
    <aside class="sidebar">
      <div class="brand">
        <div class="brand-mark">CP</div>
        <div>
          <strong>CollegePortal</strong>
          <span>Колледж искусств</span>
        </div>
      </div>

      <nav class="nav-list" aria-label="Основная навигация">
        <button
          v-for="section in visibleSections"
          :key="section.key"
          type="button"
          :class="{ active: activeSection === section.key }"
          @click="activeSection = section.key"
        >
          {{ section.label }}
        </button>
      </nav>

      <div class="api-status">
        <span>API</span>
        <code>{{ api.baseUrl }}</code>
      </div>
    </aside>

    <main class="workspace">
      <header class="topbar">
        <div>
          <p class="eyebrow">MVP</p>
          <h1>{{ activeTitle }}</h1>
        </div>
        <div class="topbar-actions">
          <span class="user-chip">{{ authUser.name }}</span>
          <span class="topbar-loading" :class="{ visible: loading }" aria-live="polite">
            Загрузка данных...
          </span>
          <button class="refresh-button" type="button" :disabled="loading" @click="loadAll">
            Обновить
          </button>
          <button class="logout-button" type="button" :disabled="loading" @click="logout">
            Выйти
          </button>
        </div>
      </header>

      <div v-if="error" class="notice">{{ error }}</div>

      <section v-if="activeSection === 'dashboard'" class="stack">
        <div class="stats-grid">
          <article v-for="item in stats" :key="item.label" class="stat-card">
            <span>{{ item.label }}</span>
            <strong>{{ item.value }}</strong>
          </article>
        </div>

        <section class="panel">
          <div class="panel-header">
            <h2>Ближайшие занятия</h2>
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Дата</th>
                  <th>Время</th>
                  <th>Группа</th>
                  <th>Дисциплина</th>
                  <th>Преподаватель</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="lesson in state.scheduleLessons" :key="lesson.id">
                  <td>{{ lesson.lesson_date }}</td>
                  <td>{{ lesson.starts_at }}–{{ lesson.ends_at }}</td>
                  <td>{{ lesson.group?.name }}</td>
                  <td>{{ lesson.subject?.name }}</td>
                  <td>{{ lesson.teacher ? fullName(lesson.teacher) : '—' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

      </section>

      <section v-if="activeSection === 'groups'" class="two-column">
        <form class="panel form-panel" @submit.prevent="submit('groups', forms.group, resetGroupForm)">
          <h2>{{ editingIds.group ? 'Редактирование группы' : 'Новая группа' }}</h2>
          <label>Название <input v-model="forms.group.name" required /></label>
          <label>Специальность <input v-model="forms.group.specialty" required /></label>
          <label>
            Образовательная программа
            <select v-model="forms.group.education_program_id">
              <option value="">Не выбрана</option>
              <option v-for="program in educationProgramOptions" :key="program.value" :value="program.value">
                {{ program.label }}
              </option>
            </select>
          </label>
          <label>Курс <input v-model.number="forms.group.course" min="1" max="6" type="number" required /></label>
          <label>Год набора <input v-model.number="forms.group.year_start" type="number" required /></label>
          <label>
            Куратор
            <select v-model="forms.group.curator_id">
              <option value="">Не назначен</option>
              <option v-for="teacher in teacherOptions" :key="teacher.value" :value="teacher.value">
                {{ teacher.label }}
              </option>
            </select>
          </label>
          <div class="form-actions">
            <button type="submit">{{ editingIds.group ? 'Сохранить' : 'Создать' }}</button>
            <button v-if="editingIds.group" class="secondary-button" type="button" @click="cancelEdit('group', resetGroupForm)">
              Отмена
            </button>
          </div>
        </form>

        <section class="panel">
          <div class="panel-header">
            <h2>Группы</h2>
            <button class="export-button" type="button" :disabled="loading" @click="exportGroups">
              Экспорт CSV
            </button>
          </div>

          <form class="import-panel" @submit.prevent="importGroups">
            <label>
              Импорт групп из CSV
              <input accept=".csv,text/csv,text/plain" type="file" @change="onGroupImportFileChange" />
            </label>
            <button type="submit" :disabled="loading">Импортировать</button>
          </form>

          <div v-if="groupImportSummary" class="success-note">
            Создано: {{ groupImportSummary.created }},
            обновлено: {{ groupImportSummary.updated }},
            строк с ошибками: {{ groupImportSummary.errors.length }}.
          </div>

          <div v-if="groupImportSummary?.errors.length" class="import-errors">
            <strong>Ошибки импорта</strong>
            <ul>
              <li v-for="item in groupImportSummary.errors" :key="item.line">
                Строка {{ item.line }}: {{ item.messages.join(' ') }}
              </li>
            </ul>
          </div>

          <div class="table-wrap">
            <table>
              <thead><tr><th>Группа</th><th>Специальность</th><th>Программа</th><th>Курс</th><th>Студенты</th><th>Действия</th></tr></thead>
              <tbody>
                <tr v-for="group in state.groups" :key="group.id">
                  <td>{{ group.name }}</td>
                  <td>{{ group.specialty }}</td>
                  <td>{{ educationProgramLabel(group.education_program) }}</td>
                  <td>{{ group.course }}</td>
                  <td>{{ group.students_count ?? '—' }}</td>
                  <td>
                    <div class="row-actions">
                      <button type="button" @click="editItem('groups', group)">Редактировать</button>
                      <button class="danger-button" type="button" @click="removeItem('groups', group, group.name)">Удалить</button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </section>

      <section v-if="activeSection === 'specialties'" class="two-column">
        <form class="panel form-panel" @submit.prevent="submit('specialties', forms.specialty, resetSpecialtyForm)">
          <h2>{{ editingIds.specialty ? 'Редактирование специальности' : 'Новая специальность' }}</h2>
          <p class="form-hint">
            Эти сведения используются в справочнике и в публичном разделе «Абитуриенту».
          </p>
          <label>Код <input v-model="forms.specialty.code" placeholder="53.02.04" required /></label>
          <label>Название <input v-model="forms.specialty.name" required /></label>
          <label>Уровень образования <input v-model="forms.specialty.education_level" required /></label>
          <label>Квалификация <input v-model="forms.specialty.qualification" /></label>
          <label>
            Нормативный срок
            <input v-model.number="forms.specialty.normative_study_years" min="0.5" max="10" step="0.1" type="number" />
            <span class="field-hint">Например: 3.8 будет показано как 3 года 10 месяцев.</span>
          </label>
          <label>Описание <textarea v-model="forms.specialty.description" rows="3" /></label>
          <div class="form-actions">
            <button type="submit">{{ editingIds.specialty ? 'Сохранить' : 'Создать' }}</button>
            <button v-if="editingIds.specialty" class="secondary-button" type="button" @click="cancelEdit('specialty', resetSpecialtyForm)">
              Отмена
            </button>
          </div>
        </form>

        <section class="panel">
          <div class="panel-header">
            <h2>Специальности</h2>
            <button class="export-button" type="button" :disabled="loading" @click="exportSpecialties">
              Экспорт CSV
            </button>
          </div>

          <form class="import-panel" @submit.prevent="importSpecialties">
            <label>
              Импорт специальностей из CSV
              <input accept=".csv,text/csv,text/plain" type="file" @change="onSpecialtyImportFileChange" />
            </label>
            <button type="submit" :disabled="loading">Импортировать</button>
          </form>

          <div v-if="specialtyImportSummary" class="success-note">
            Создано: {{ specialtyImportSummary.created }},
            обновлено: {{ specialtyImportSummary.updated }},
            строк с ошибками: {{ specialtyImportSummary.errors.length }}.
          </div>

          <div v-if="specialtyImportSummary?.errors.length" class="import-errors">
            <strong>Ошибки импорта</strong>
            <ul>
              <li v-for="item in specialtyImportSummary.errors" :key="item.line">
                Строка {{ item.line }}: {{ item.messages.join(' ') }}
              </li>
            </ul>
          </div>

          <div class="table-wrap">
            <table>
              <thead><tr><th>Код</th><th>Название</th><th>Квалификация</th><th>Срок</th><th>Действия</th></tr></thead>
              <tbody>
                <tr v-for="specialty in state.specialties" :key="specialty.id">
                  <td>{{ specialty.code }}</td>
                  <td>{{ specialty.name }}</td>
                  <td>{{ specialty.qualification || '—' }}</td>
                  <td>{{ formatStudyDuration(specialty.normative_study_years) }}</td>
                  <td>
                    <div class="row-actions">
                      <button type="button" @click="editItem('specialties', specialty)">Редактировать</button>
                      <button class="danger-button" type="button" @click="removeItem('specialties', specialty, specialty.name)">Удалить</button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </section>

      <section v-if="activeSection === 'educationPrograms'" class="two-column">
        <form class="panel form-panel" @submit.prevent="submit('education-programs', forms.educationProgram, resetEducationProgramForm)">
          <h2>{{ editingIds.educationProgram ? 'Редактирование программы' : 'Новая программа' }}</h2>
          <p class="form-hint">
            Активные программы автоматически отображаются на странице «Абитуриенту».
          </p>
          <label>
            Специальность
            <select v-model="forms.educationProgram.specialty_id" required>
              <option value="">Выберите специальность</option>
              <option v-for="specialty in specialtyOptions" :key="specialty.value" :value="specialty.value">
                {{ specialty.label }}
              </option>
            </select>
          </label>
          <label>Название <input v-model="forms.educationProgram.name" required /></label>
          <label>Год начала <input v-model.number="forms.educationProgram.year_start" type="number" required /></label>
          <label>
            Форма обучения
            <select v-model="forms.educationProgram.study_form" required>
              <option value="Очная">Очная</option>
              <option value="Заочная">Заочная</option>
              <option value="Очно-заочная">Очно-заочная</option>
            </select>
          </label>
          <label>
            Срок обучения
            <input v-model.number="forms.educationProgram.study_years" min="0.5" max="10" step="0.1" type="number" />
            <span class="field-hint">Например: 2.8 будет показано как 2 года 10 месяцев.</span>
          </label>
          <label class="checkbox-label">
            <input v-model="forms.educationProgram.is_active" type="checkbox" />
            Опубликовать на странице «Абитуриенту»
          </label>
          <label>Описание <textarea v-model="forms.educationProgram.description" rows="3" /></label>
          <div class="form-actions">
            <button type="submit">{{ editingIds.educationProgram ? 'Сохранить' : 'Создать' }}</button>
            <button v-if="editingIds.educationProgram" class="secondary-button" type="button" @click="cancelEdit('educationProgram', resetEducationProgramForm)">
              Отмена
            </button>
          </div>
        </form>

        <section class="panel">
          <div class="panel-header">
            <h2>Образовательные программы</h2>
            <button class="export-button" type="button" :disabled="loading" @click="exportEducationPrograms">
              Экспорт CSV
            </button>
          </div>

          <form class="import-panel" @submit.prevent="importEducationPrograms">
            <label>
              Импорт программ из CSV
              <input accept=".csv,text/csv,text/plain" type="file" @change="onEducationProgramImportFileChange" />
            </label>
            <button type="submit" :disabled="loading">Импортировать</button>
          </form>

          <div v-if="educationProgramImportSummary" class="success-note">
            Создано: {{ educationProgramImportSummary.created }},
            обновлено: {{ educationProgramImportSummary.updated }},
            строк с ошибками: {{ educationProgramImportSummary.errors.length }}.
          </div>

          <div v-if="educationProgramImportSummary?.errors.length" class="import-errors">
            <strong>Ошибки импорта</strong>
            <ul>
              <li v-for="item in educationProgramImportSummary.errors" :key="item.line">
                Строка {{ item.line }}: {{ item.messages.join(' ') }}
              </li>
            </ul>
          </div>

          <div class="table-wrap">
            <table>
              <thead><tr><th>Программа</th><th>Специальность</th><th>Год</th><th>Форма</th><th>Срок</th><th>Публикация</th><th>Действия</th></tr></thead>
              <tbody>
                <tr v-for="program in state.educationPrograms" :key="program.id">
                  <td>
                    <strong>{{ program.name }}</strong>
                    <span v-if="program.description" class="table-muted">{{ program.description }}</span>
                  </td>
                  <td>{{ program.specialty ? `${program.specialty.code} ${program.specialty.name}` : '—' }}</td>
                  <td>{{ program.year_start }}</td>
                  <td>{{ program.study_form }}</td>
                  <td>{{ formatStudyDuration(program.study_years || program.specialty?.normative_study_years) }}</td>
                  <td>
                    <span :class="['status-pill', { muted: !program.is_active }]">
                      {{ program.is_active ? 'Опубликована' : 'Скрыта' }}
                    </span>
                  </td>
                  <td>
                    <div class="row-actions">
                      <button type="button" @click="editItem('education-programs', program)">Редактировать</button>
                      <button class="danger-button" type="button" @click="removeItem('education-programs', program, program.name)">Удалить</button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </section>

      <section v-if="activeSection === 'applicantApplications'" class="two-column">
        <form class="panel form-panel" @submit.prevent="submit('applicant-applications', forms.applicantApplication, resetApplicantApplicationForm)">
          <h2>{{ editingIds.applicantApplication ? 'Редактирование заявления' : 'Новое заявление' }}</h2>
          <p class="form-hint">
            Внутренний реестр приемной комиссии. Публичная подача заявления будет добавлена отдельным шагом.
          </p>
          <label>
            Образовательная программа
            <select v-model="forms.applicantApplication.education_program_id" required>
              <option value="">Выберите программу</option>
              <option v-for="program in educationProgramOptions" :key="program.value" :value="program.value">
                {{ program.label }}
              </option>
            </select>
          </label>
          <div class="form-row">
            <label>Фамилия <input v-model="forms.applicantApplication.last_name" required /></label>
            <label>Имя <input v-model="forms.applicantApplication.first_name" required /></label>
          </div>
          <label>Отчество <input v-model="forms.applicantApplication.middle_name" /></label>
          <div class="form-row">
            <label>Дата рождения <input v-model="forms.applicantApplication.birth_date" type="date" /></label>
            <label>Дата подачи <input v-model="forms.applicantApplication.submitted_at" type="date" required /></label>
          </div>
          <div class="form-row">
            <label>Телефон <input v-model="forms.applicantApplication.phone" placeholder="+7..." /></label>
            <label>Email <input v-model="forms.applicantApplication.email" type="email" /></label>
          </div>
          <div class="form-row">
            <label>
              База поступления
              <select v-model="forms.applicantApplication.education_base" required>
                <option value="after_9">После 9 класса</option>
                <option value="after_11">После 11 класса</option>
              </select>
            </label>
            <label>
              Статус
              <select v-model="forms.applicantApplication.status" required>
                <option value="new">Новое</option>
                <option value="accepted">Принято</option>
                <option value="needs_clarification">Требуется уточнение</option>
                <option value="rejected">Отклонено</option>
                <option value="enrolled">Зачислен</option>
              </select>
            </label>
          </div>
          <label>Комментарий приемной комиссии <textarea v-model="forms.applicantApplication.comment" rows="3" /></label>
          <div class="form-actions">
            <button type="submit">{{ editingIds.applicantApplication ? 'Сохранить' : 'Создать заявление' }}</button>
            <button
              v-if="editingIds.applicantApplication"
              class="secondary-button"
              type="button"
              @click="cancelEdit('applicantApplication', resetApplicantApplicationForm)"
            >
              Отмена
            </button>
          </div>
        </form>

        <section class="panel">
          <div class="panel-header">
            <h2>Заявления абитуриентов</h2>
            <div class="panel-actions">
              <span class="status-pill">{{ filteredApplicantApplications.length }} в списке</span>
              <button class="export-button" type="button" :disabled="loading" @click="exportApplicantApplications">
                Экспорт CSV
              </button>
            </div>
          </div>

          <section class="applicant-dashboard">
            <div class="applicant-readiness-grid">
              <article
                v-for="item in applicantReadinessSummary"
                :key="item.key"
                :class="{ active: filters.applicantApplications.queue === item.key }"
                role="button"
                tabindex="0"
                @click="applyApplicantReadinessFilter(item.key)"
                @keydown.enter.prevent="applyApplicantReadinessFilter(item.key)"
                @keydown.space.prevent="applyApplicantReadinessFilter(item.key)"
              >
                <span>{{ item.label }}</span>
                <strong>{{ item.value }}</strong>
              </article>
            </div>

            <div class="applicant-status-grid">
              <article v-for="item in applicantStatusSummary" :key="item.key">
                <span>{{ item.label }}</span>
                <strong>{{ item.value }}</strong>
              </article>
            </div>

            <div class="applicant-summary-columns">
              <section>
                <h3>По программам</h3>
                <div class="table-wrap compact-table-wrap">
                  <table>
                    <thead>
                      <tr>
                        <th>Программа</th>
                        <th>Форма</th>
                        <th>Всего</th>
                        <th>Принято</th>
                        <th>Уточнить</th>
                        <th>Зачислено</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="row in applicantProgramSummary" :key="row.key">
                        <td>{{ row.program }}</td>
                        <td>{{ row.studyForm }}</td>
                        <td>{{ row.total }}</td>
                        <td>{{ row.accepted }}</td>
                        <td>{{ row.needsClarification }}</td>
                        <td>{{ row.enrolled }}</td>
                      </tr>
                      <tr v-if="applicantProgramSummary.length === 0">
                        <td colspan="6">Нет данных для сводки.</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </section>

              <section>
                <h3>По форме обучения</h3>
                <div class="table-wrap compact-table-wrap">
                  <table>
                    <thead>
                      <tr>
                        <th>Форма</th>
                        <th>Всего</th>
                        <th>Зачислено</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="row in applicantStudyFormSummary" :key="row.studyForm">
                        <td>{{ row.studyForm }}</td>
                        <td>{{ row.total }}</td>
                        <td>{{ row.enrolled }}</td>
                      </tr>
                      <tr v-if="applicantStudyFormSummary.length === 0">
                        <td colspan="3">Нет данных для сводки.</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </section>
            </div>
          </section>

          <form class="filter-panel applicant-filter-panel" @submit.prevent="applyFilters">
            <label>
              Поиск
              <input v-model="filters.applicantApplications.search" placeholder="ФИО, телефон или email" />
            </label>
            <label>
              Программа
              <select v-model="filters.applicantApplications.education_program_id">
                <option value="">Все программы</option>
                <option v-for="program in educationProgramOptions" :key="program.value" :value="program.value">
                  {{ program.label }}
                </option>
              </select>
            </label>
            <label>
              Статус
              <select v-model="filters.applicantApplications.status">
                <option value="">Все статусы</option>
                <option value="new">Новое</option>
                <option value="accepted">Принято</option>
                <option value="needs_clarification">Требуется уточнение</option>
                <option value="rejected">Отклонено</option>
                <option value="enrolled">Зачислен</option>
              </select>
            </label>
            <label>
              База
              <select v-model="filters.applicantApplications.education_base">
                <option value="">Любая</option>
                <option value="after_9">После 9 класса</option>
                <option value="after_11">После 11 класса</option>
              </select>
            </label>
            <label>
              Документы
              <select v-model="filters.applicantApplications.documents">
                <option value="">Любой комплект</option>
                <option value="complete">Комплект полный</option>
                <option value="incomplete">Не хватает документов</option>
              </select>
            </label>
            <div class="filter-actions">
              <button type="submit" :disabled="loading">Применить</button>
              <button class="secondary-filter-button" type="button" :disabled="loading" @click="resetApplicantApplicationFilters">
                Сбросить
              </button>
            </div>
          </form>

          <div v-if="activeApplicantApplicationFilters.length" class="active-filter-row">
            <span>Отбор</span>
            <button
              v-for="item in activeApplicantApplicationFilters"
              :key="item.key"
              type="button"
              :disabled="loading"
              @click="clearApplicantApplicationFilter(item.key)"
            >
              {{ item.label }}
              <span aria-hidden="true">×</span>
            </button>
            <button class="clear-all-filter-button" type="button" :disabled="loading" @click="resetApplicantApplicationFilters">
              Сбросить все
            </button>
          </div>

          <form class="import-panel" @submit.prevent="importApplicantApplications">
            <label>
              Импорт заявлений из CSV
              <input accept=".csv,text/csv,text/plain" type="file" @change="onApplicantApplicationImportFileChange" />
            </label>
            <button type="submit" :disabled="loading">Импортировать</button>
          </form>

          <div v-if="applicantApplicationImportSummary" class="success-note">
            Создано: {{ applicantApplicationImportSummary.created }},
            обновлено: {{ applicantApplicationImportSummary.updated }},
            строк с ошибками: {{ applicantApplicationImportSummary.errors.length }}.
          </div>

          <div v-if="applicantApplicationImportSummary?.errors.length" class="import-errors">
            <strong>Ошибки импорта</strong>
            <ul>
              <li v-for="item in applicantApplicationImportSummary.errors" :key="item.line">
                Строка {{ item.line }}: {{ item.messages.join(' ') }}
              </li>
            </ul>
          </div>

          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Абитуриент</th>
                  <th>Программа</th>
                  <th>База</th>
                  <th>Статус</th>
                  <th>Дата</th>
                  <th>Контакты</th>
                  <th>Действия</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="application in filteredApplicantApplications" :key="application.id">
                  <td>
                    <strong>{{ fullName(application) }}</strong>
                    <span :class="['document-summary-chip', applicantDocumentSummaryClass(application)]">
                      {{ applicantDocumentSummaryLabel(application) }}
                    </span>
                    <span v-if="application.comment" class="table-muted">{{ application.comment }}</span>
                    <details v-if="application.events?.length" class="history-details">
                      <summary>История: {{ application.events.length }}</summary>
                      <ul>
                        <li v-for="event in application.events" :key="event.id">
                          <strong>{{ event.title }}</strong>
                          <span>{{ formatDateTime(event.created_at) }}</span>
                          <small v-if="event.description">{{ event.description }}</small>
                        </li>
                      </ul>
                    </details>
                    <details v-if="application.documents?.length" class="document-details">
                      <summary>
                        Документы: {{ applicantDocumentTotals(application).received }}/{{ applicantDocumentTotals(application).total }}
                      </summary>
                      <ul>
                        <li v-for="document in application.documents" :key="document.id">
                          <div class="document-row-header">
                            <label>
                              <input
                                :checked="document.is_received"
                                :disabled="loading"
                                type="checkbox"
                                @change="updateApplicantApplicationDocument(application, document, { is_received: $event.target.checked })"
                              />
                              {{ document.title }}
                            </label>
                            <span :class="['document-state', { complete: document.is_received }]">
                              {{ document.is_received ? 'Получен' : 'Не получен' }}
                            </span>
                          </div>
                          <div class="document-fields">
                            <label>
                              Дата
                              <input
                                v-model="document.received_at"
                                :disabled="loading || !document.is_received"
                                type="date"
                                @change="updateApplicantApplicationDocument(application, document, { received_at: document.received_at })"
                              />
                            </label>
                            <label>
                              Номер
                              <input
                                v-model="document.number"
                                :disabled="loading"
                                placeholder="Не указан"
                                @change="updateApplicantApplicationDocument(application, document, { number: document.number })"
                              />
                            </label>
                            <label class="document-comment-field">
                              Комментарий
                              <input
                                v-model="document.comment"
                                :disabled="loading"
                                placeholder="Без комментария"
                                @change="updateApplicantApplicationDocument(application, document, { comment: document.comment })"
                              />
                            </label>
                          </div>
                        </li>
                      </ul>
                    </details>
                  </td>
                  <td>{{ educationProgramLabel(application.education_program) }}</td>
                  <td>{{ applicantEducationBaseLabel(application.education_base) }}</td>
                  <td>
                    <span :class="['status-pill', { muted: ['rejected', 'needs_clarification'].includes(application.status) }]">
                      {{ applicantApplicationStatusLabel(application.status) }}
                    </span>
                  </td>
                  <td>{{ application.submitted_at }}</td>
                  <td>
                    <span>{{ application.phone || '—' }}</span>
                    <span v-if="application.email" class="table-muted">{{ application.email }}</span>
                  </td>
                  <td>
                    <div class="row-actions">
                      <template v-if="application.status !== 'enrolled'">
                        <select v-model="enrollmentForms[application.id].group_id" class="inline-select compact-inline-select">
                          <option value="">Группа</option>
                          <option v-for="group in groupOptions" :key="group.value" :value="group.value">
                            {{ group.label }}
                          </option>
                        </select>
                        <input
                          v-model="enrollmentForms[application.id].enrollment_date"
                          class="compact-date-input"
                          type="date"
                        />
                        <button
                          type="button"
                          :disabled="!hasFullDocumentSet(application)"
                          @click="enrollApplicantApplication(application)"
                        >
                          Зачислить
                        </button>
                        <span v-if="!hasFullDocumentSet(application)" class="action-hint">
                          {{ applicantDocumentSummaryLabel(application) }}
                        </span>
                      </template>
                      <button type="button" @click="editItem('applicant-applications', application)">Редактировать</button>
                      <button class="danger-button" type="button" @click="removeItem('applicant-applications', application, fullName(application))">Удалить</button>
                    </div>
                  </td>
                </tr>
                <tr v-if="!loading && filteredApplicantApplications.length === 0">
                  <td colspan="7">Заявлений пока нет.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </section>

      <section v-if="activeSection === 'students'" class="two-column">
        <form class="panel form-panel" @submit.prevent="submit('students', forms.student, resetStudentForm)">
          <h2>{{ editingIds.student ? 'Редактирование студента' : 'Новый студент' }}</h2>
          <label>Фамилия <input v-model="forms.student.last_name" required /></label>
          <label>Имя <input v-model="forms.student.first_name" required /></label>
          <label>Отчество <input v-model="forms.student.middle_name" /></label>
          <label>
            Группа
            <select v-model="forms.student.group_id" required>
              <option value="">Выберите группу</option>
              <option v-for="group in groupOptions" :key="group.value" :value="group.value">{{ group.label }}</option>
            </select>
          </label>
          <label>Телефон <input v-model="forms.student.phone" /></label>
          <label>Email <input v-model="forms.student.email" type="email" /></label>
          <label>
            Статус
            <select v-model="forms.student.status" required>
              <option value="active">Обучается</option>
              <option value="academic_leave">Академический отпуск</option>
              <option value="graduated">Выпущен</option>
              <option value="expelled">Отчислен</option>
            </select>
          </label>
          <label>Дата зачисления <input v-model="forms.student.enrollment_date" type="date" /></label>
          <div class="form-actions">
            <button type="submit">{{ editingIds.student ? 'Сохранить' : 'Создать' }}</button>
            <button v-if="editingIds.student" class="secondary-button" type="button" @click="cancelEdit('student', resetStudentForm)">
              Отмена
            </button>
          </div>
        </form>

        <section class="panel">
          <div class="panel-header">
            <h2>Студенты</h2>
            <button class="export-button" type="button" :disabled="loading" @click="exportStudents">
              Экспорт CSV
            </button>
          </div>

          <form class="import-panel" @submit.prevent="importStudents">
            <label>
              Импорт студентов из CSV
              <input accept=".csv,text/csv,text/plain" type="file" @change="onStudentImportFileChange" />
            </label>
            <button type="submit" :disabled="loading">Импортировать</button>
          </form>

          <div v-if="studentImportSummary" class="success-note">
            Создано: {{ studentImportSummary.created }},
            обновлено: {{ studentImportSummary.updated }},
            строк с ошибками: {{ studentImportSummary.errors.length }}.
          </div>

          <div v-if="studentImportSummary?.errors.length" class="import-errors">
            <strong>Ошибки импорта</strong>
            <ul>
              <li v-for="item in studentImportSummary.errors" :key="item.line">
                Строка {{ item.line }}: {{ item.messages.join(' ') }}
              </li>
            </ul>
          </div>

          <form class="filter-panel" @submit.prevent="applyFilters">
            <label>
              Поиск
              <input v-model="filters.students.search" placeholder="Фамилия, имя или отчество" />
            </label>
            <label>
              Группа
              <select v-model="filters.students.group_id">
                <option value="">Все группы</option>
                <option v-for="group in groupOptions" :key="group.value" :value="group.value">{{ group.label }}</option>
              </select>
            </label>
            <label>
              Статус
              <select v-model="filters.students.status">
                <option value="">Все статусы</option>
                <option value="active">Обучается</option>
                <option value="academic_leave">Академический отпуск</option>
                <option value="graduated">Выпущен</option>
                <option value="expelled">Отчислен</option>
              </select>
            </label>
            <div class="filter-actions">
              <button type="submit" :disabled="loading">Применить</button>
              <button class="secondary-filter-button" type="button" :disabled="loading" @click="resetStudentFilters">
                Сбросить
              </button>
            </div>
          </form>

          <div class="table-wrap">
            <table>
              <thead><tr><th>ФИО</th><th>Группа</th><th>Статус</th><th>Контакты</th><th>Действия</th></tr></thead>
              <tbody>
                <tr
                  v-for="student in filteredStudents"
                  :key="student.id"
                  :class="{ selected: Number(selectedStudentId) === Number(student.id) }"
                >
                  <td>
                    <strong>{{ fullName(student) }}</strong>
                    <span class="table-muted">{{ studentEducationProgramLabel(student) }}</span>
                  </td>
                  <td>
                    {{ student.group?.name || groupName(student.group_id) }}
                    <span class="table-muted">Курс {{ student.group?.course || '—' }}</span>
                  </td>
                  <td><span class="status-pill">{{ studentStatusLabel(student.status) }}</span></td>
                  <td>
                    <span>{{ student.phone || '—' }}</span>
                    <span v-if="student.email" class="table-muted">{{ student.email }}</span>
                  </td>
                  <td>
                    <div class="row-actions">
                      <button type="button" @click="selectStudent(student)">Открыть</button>
                      <button type="button" @click="editItem('students', student)">Редактировать</button>
                      <button class="danger-button" type="button" @click="removeItem('students', student, fullName(student))">Удалить</button>
                    </div>
                  </td>
                </tr>
                <tr v-if="!loading && filteredStudents.length === 0">
                  <td colspan="5">Студентов по выбранным условиям нет.</td>
                </tr>
              </tbody>
            </table>
          </div>

          <section v-if="selectedStudent" class="student-card">
            <div class="student-card-header">
              <div>
                <p class="eyebrow">Карточка студента</p>
                <h3>{{ fullName(selectedStudent) }}</h3>
              </div>
              <span class="status-pill">{{ studentStatusLabel(selectedStudent.status) }}</span>
            </div>

            <div class="student-card-grid">
              <section>
                <h4>Основные данные</h4>
                <dl class="detail-list">
                  <div><dt>Группа</dt><dd>{{ selectedStudent.group?.name || groupName(selectedStudent.group_id) }}</dd></div>
                  <div><dt>Программа</dt><dd>{{ studentEducationProgramLabel(selectedStudent) }}</dd></div>
                  <div><dt>Дата рождения</dt><dd>{{ selectedStudent.birth_date || '—' }}</dd></div>
                  <div><dt>Дата зачисления</dt><dd>{{ selectedStudent.enrollment_date || '—' }}</dd></div>
                </dl>
              </section>

              <section>
                <h4>Контакты</h4>
                <dl class="detail-list">
                  <div><dt>Телефон</dt><dd>{{ selectedStudent.phone || '—' }}</dd></div>
                  <div><dt>Email</dt><dd>{{ selectedStudent.email || '—' }}</dd></div>
                </dl>
              </section>

              <section>
                <h4>Посещаемость</h4>
                <div class="student-mini-stats">
                  <article><span>Всего</span><strong>{{ selectedStudentAttendanceSummary.total }}</strong></article>
                  <article><span>Присутствий</span><strong>{{ selectedStudentAttendanceSummary.present }}</strong></article>
                  <article><span>Отсутствий</span><strong>{{ selectedStudentAttendanceSummary.absent }}</strong></article>
                  <article><span>Опозданий</span><strong>{{ selectedStudentAttendanceSummary.late }}</strong></article>
                </div>
              </section>

              <section>
                <h4>Оценки</h4>
                <div class="student-mini-stats">
                  <article><span>Всего</span><strong>{{ selectedStudentGradeSummary.total }}</strong></article>
                  <article><span>Числовых</span><strong>{{ selectedStudentGradeSummary.numeric }}</strong></article>
                  <article><span>Средний балл</span><strong>{{ selectedStudentGradeSummary.average || '—' }}</strong></article>
                </div>
              </section>
            </div>

            <div class="student-card-grid two">
              <section>
                <h4>Последние посещения</h4>
                <div class="compact-table-wrap">
                  <table>
                    <thead><tr><th>Дата</th><th>Занятие</th><th>Статус</th></tr></thead>
                    <tbody>
                      <tr v-for="item in selectedStudentAttendance.slice(0, 5)" :key="item.id">
                        <td>{{ item.schedule_lesson?.lesson_date || '—' }}</td>
                        <td>{{ item.schedule_lesson?.subject?.name || '—' }}</td>
                        <td>{{ attendanceStatusLabel(item.status) }}</td>
                      </tr>
                      <tr v-if="selectedStudentAttendance.length === 0">
                        <td colspan="3">Отметок пока нет.</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </section>

              <section>
                <h4>Последние оценки</h4>
                <div class="compact-table-wrap">
                  <table>
                    <thead><tr><th>Дата</th><th>Дисциплина</th><th>Оценка</th></tr></thead>
                    <tbody>
                      <tr v-for="grade in selectedStudentGrades.slice(0, 5)" :key="grade.id">
                        <td>{{ grade.schedule_lesson?.lesson_date || '—' }}</td>
                        <td>{{ grade.schedule_lesson?.subject?.name || '—' }}</td>
                        <td>{{ grade.grade }}</td>
                      </tr>
                      <tr v-if="selectedStudentGrades.length === 0">
                        <td colspan="3">Оценок пока нет.</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </section>
            </div>
          </section>
        </section>
      </section>

      <section v-if="activeSection === 'teachers'" class="two-column">
        <form class="panel form-panel" @submit.prevent="submit('teachers', forms.teacher, resetTeacherForm)">
          <h2>{{ editingIds.teacher ? 'Редактирование преподавателя' : 'Новый преподаватель' }}</h2>
          <label>Фамилия <input v-model="forms.teacher.last_name" required /></label>
          <label>Имя <input v-model="forms.teacher.first_name" required /></label>
          <label>Отчество <input v-model="forms.teacher.middle_name" /></label>
          <label>Должность <input v-model="forms.teacher.position" /></label>
          <label>Отделение <input v-model="forms.teacher.department" /></label>
          <label>Email <input v-model="forms.teacher.email" type="email" /></label>
          <div class="form-actions">
            <button type="submit">{{ editingIds.teacher ? 'Сохранить' : 'Создать' }}</button>
            <button v-if="editingIds.teacher" class="secondary-button" type="button" @click="cancelEdit('teacher', resetTeacherForm)">
              Отмена
            </button>
          </div>
        </form>

        <section class="panel">
          <div class="panel-header">
            <h2>Преподаватели</h2>
            <button class="export-button" type="button" :disabled="loading" @click="exportTeachers">
              Экспорт CSV
            </button>
          </div>

          <form class="import-panel" @submit.prevent="importTeachers">
            <label>
              Импорт преподавателей из CSV
              <input accept=".csv,text/csv,text/plain" type="file" @change="onTeacherImportFileChange" />
            </label>
            <button type="submit" :disabled="loading">Импортировать</button>
          </form>

          <div v-if="teacherImportSummary" class="success-note">
            Создано: {{ teacherImportSummary.created }},
            обновлено: {{ teacherImportSummary.updated }},
            строк с ошибками: {{ teacherImportSummary.errors.length }}.
          </div>

          <div v-if="teacherImportSummary?.errors.length" class="import-errors">
            <strong>Ошибки импорта</strong>
            <ul>
              <li v-for="item in teacherImportSummary.errors" :key="item.line">
                Строка {{ item.line }}: {{ item.messages.join(' ') }}
              </li>
            </ul>
          </div>

          <div class="table-wrap">
            <table>
              <thead><tr><th>ФИО</th><th>Отделение</th><th>Должность</th><th>Email</th><th>Действия</th></tr></thead>
              <tbody>
                <tr v-for="teacher in state.teachers" :key="teacher.id">
                  <td>{{ fullName(teacher) }}</td>
                  <td>{{ teacher.department || '—' }}</td>
                  <td>{{ teacher.position || '—' }}</td>
                  <td>{{ teacher.email || '—' }}</td>
                  <td>
                    <div class="row-actions">
                      <button type="button" @click="editItem('teachers', teacher)">Редактировать</button>
                      <button class="danger-button" type="button" @click="removeItem('teachers', teacher, fullName(teacher))">Удалить</button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </section>

      <section v-if="activeSection === 'subjects'" class="two-column">
        <form class="panel form-panel" @submit.prevent="submit('subjects', forms.subject, resetSubjectForm)">
          <h2>{{ editingIds.subject ? 'Редактирование дисциплины' : 'Новая дисциплина' }}</h2>
          <label>Название <input v-model="forms.subject.name" required /></label>
          <label>Код <input v-model="forms.subject.code" /></label>
          <label>Отделение <input v-model="forms.subject.department" /></label>
          <label>Описание <textarea v-model="forms.subject.description" rows="3" /></label>
          <label>
            Преподаватели
            <select v-model="forms.subject.teacher_ids" multiple>
              <option v-for="teacher in teacherOptions" :key="teacher.value" :value="teacher.value">
                {{ teacher.label }}
              </option>
            </select>
          </label>
          <div class="form-actions">
            <button type="submit">{{ editingIds.subject ? 'Сохранить' : 'Создать' }}</button>
            <button v-if="editingIds.subject" class="secondary-button" type="button" @click="cancelEdit('subject', resetSubjectForm)">
              Отмена
            </button>
          </div>
        </form>

        <section class="panel">
          <div class="panel-header">
            <h2>Дисциплины</h2>
            <button class="export-button" type="button" :disabled="loading" @click="exportSubjects">
              Экспорт CSV
            </button>
          </div>

          <form class="import-panel" @submit.prevent="importSubjects">
            <label>
              Импорт дисциплин из CSV
              <input accept=".csv,text/csv,text/plain" type="file" @change="onSubjectImportFileChange" />
            </label>
            <button type="submit" :disabled="loading">Импортировать</button>
          </form>

          <div v-if="subjectImportSummary" class="success-note">
            Создано: {{ subjectImportSummary.created }},
            обновлено: {{ subjectImportSummary.updated }},
            строк с ошибками: {{ subjectImportSummary.errors.length }}.
          </div>

          <div v-if="subjectImportSummary?.errors.length" class="import-errors">
            <strong>Ошибки импорта</strong>
            <ul>
              <li v-for="item in subjectImportSummary.errors" :key="item.line">
                Строка {{ item.line }}: {{ item.messages.join(' ') }}
              </li>
            </ul>
          </div>

          <div class="table-wrap">
            <table>
              <thead><tr><th>Название</th><th>Код</th><th>Отделение</th><th>Преподаватели</th><th>Действия</th></tr></thead>
              <tbody>
                <tr v-for="subject in state.subjects" :key="subject.id">
                  <td>{{ subject.name }}</td>
                  <td>{{ subject.code || '—' }}</td>
                  <td>{{ subject.department || '—' }}</td>
                  <td>{{ subject.teachers?.map(fullName).join(', ') || '—' }}</td>
                  <td>
                    <div class="row-actions">
                      <button type="button" @click="editItem('subjects', subject)">Редактировать</button>
                      <button class="danger-button" type="button" @click="removeItem('subjects', subject, subject.name)">Удалить</button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </section>

      <section v-if="activeSection === 'classrooms'" class="two-column">
        <form class="panel form-panel" @submit.prevent="submit('classrooms', forms.classroom, resetClassroomForm)">
          <h2>{{ editingIds.classroom ? 'Редактирование аудитории' : 'Новая аудитория' }}</h2>
          <label>Номер <input v-model="forms.classroom.number" required /></label>
          <label>Корпус <input v-model="forms.classroom.building" /></label>
          <label>Этаж <input v-model.number="forms.classroom.floor" type="number" /></label>
          <label>Вместимость <input v-model.number="forms.classroom.capacity" type="number" /></label>
          <label>Тип <input v-model="forms.classroom.type" /></label>
          <label>Описание <textarea v-model="forms.classroom.description" rows="3" /></label>
          <div class="form-actions">
            <button type="submit">{{ editingIds.classroom ? 'Сохранить' : 'Создать' }}</button>
            <button v-if="editingIds.classroom" class="secondary-button" type="button" @click="cancelEdit('classroom', resetClassroomForm)">
              Отмена
            </button>
          </div>
        </form>

        <section class="panel">
          <div class="panel-header">
            <h2>Аудитории</h2>
            <button class="export-button" type="button" :disabled="loading" @click="exportClassrooms">
              Экспорт CSV
            </button>
          </div>

          <form class="import-panel" @submit.prevent="importClassrooms">
            <label>
              Импорт аудиторий из CSV
              <input accept=".csv,text/csv,text/plain" type="file" @change="onClassroomImportFileChange" />
            </label>
            <button type="submit" :disabled="loading">Импортировать</button>
          </form>

          <div v-if="classroomImportSummary" class="success-note">
            Создано: {{ classroomImportSummary.created }},
            обновлено: {{ classroomImportSummary.updated }},
            строк с ошибками: {{ classroomImportSummary.errors.length }}.
          </div>

          <div v-if="classroomImportSummary?.errors.length" class="import-errors">
            <strong>Ошибки импорта</strong>
            <ul>
              <li v-for="item in classroomImportSummary.errors" :key="item.line">
                Строка {{ item.line }}: {{ item.messages.join(' ') }}
              </li>
            </ul>
          </div>

          <div class="table-wrap">
            <table>
              <thead><tr><th>Номер</th><th>Корпус</th><th>Этаж</th><th>Вместимость</th><th>Тип</th><th>Действия</th></tr></thead>
              <tbody>
                <tr v-for="classroom in state.classrooms" :key="classroom.id">
                  <td>{{ classroom.number }}</td>
                  <td>{{ classroom.building || '—' }}</td>
                  <td>{{ classroom.floor ?? '—' }}</td>
                  <td>{{ classroom.capacity ?? '—' }}</td>
                  <td>{{ classroom.type || '—' }}</td>
                  <td>
                    <div class="row-actions">
                      <button type="button" @click="editItem('classrooms', classroom)">Редактировать</button>
                      <button class="danger-button" type="button" @click="removeItem('classrooms', classroom, classroom.number)">Удалить</button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </section>

      <section v-if="activeSection === 'schedule'" class="two-column">
        <form class="panel form-panel" @submit.prevent="submit('schedule-lessons', forms.scheduleLesson, resetScheduleLessonForm)">
          <h2>{{ editingIds.scheduleLesson ? 'Редактирование занятия' : 'Новое занятие' }}</h2>
          <label>
            Дата
            <input v-model="forms.scheduleLesson.lesson_date" type="date" required />
          </label>
          <div class="form-row">
            <label>
              Начало
              <input v-model="forms.scheduleLesson.starts_at" type="time" required />
            </label>
            <label>
              Окончание
              <input v-model="forms.scheduleLesson.ends_at" type="time" required />
            </label>
          </div>
          <label>
            Тип занятия
            <select v-model="forms.scheduleLesson.lesson_type" required>
              <option value="lesson">Занятие</option>
              <option value="lecture">Лекция</option>
              <option value="practice">Практика</option>
              <option value="exam">Экзамен</option>
              <option value="consultation">Консультация</option>
            </select>
          </label>
          <label>
            Группа
            <select v-model="forms.scheduleLesson.group_id" required>
              <option value="">Выберите группу</option>
              <option v-for="group in groupOptions" :key="group.value" :value="group.value">{{ group.label }}</option>
            </select>
          </label>
          <label>
            Преподаватель
            <select v-model="forms.scheduleLesson.teacher_id" required>
              <option value="">Выберите преподавателя</option>
              <option v-for="teacher in teacherOptions" :key="teacher.value" :value="teacher.value">{{ teacher.label }}</option>
            </select>
          </label>
          <label>
            Дисциплина
            <select v-model="forms.scheduleLesson.subject_id" required>
              <option value="">Выберите дисциплину</option>
              <option v-for="subject in subjectOptions" :key="subject.value" :value="subject.value">{{ subject.label }}</option>
            </select>
          </label>
          <label>
            Аудитория
            <select v-model="forms.scheduleLesson.classroom_id">
              <option value="">Без аудитории</option>
              <option v-for="classroom in classroomOptions" :key="classroom.value" :value="classroom.value">{{ classroom.label }}</option>
            </select>
          </label>
          <label>Тема <input v-model="forms.scheduleLesson.topic" /></label>
          <div class="form-actions">
            <button type="submit">{{ editingIds.scheduleLesson ? 'Сохранить' : 'Создать занятие' }}</button>
            <button
              v-if="editingIds.scheduleLesson"
              class="secondary-button"
              type="button"
              @click="cancelEdit('scheduleLesson', resetScheduleLessonForm)"
            >
              Отмена
            </button>
          </div>
        </form>

        <section class="panel">
          <h2>Расписание</h2>

          <form class="filter-panel schedule-filter-panel" @submit.prevent="applyFilters">
            <label>
              Дата
              <input v-model="filters.scheduleLessons.date" type="date" />
            </label>
            <label>
              Группа
              <select v-model="filters.scheduleLessons.group_id">
                <option value="">Все группы</option>
                <option v-for="group in groupOptions" :key="group.value" :value="group.value">{{ group.label }}</option>
              </select>
            </label>
            <label>
              Преподаватель
              <select v-model="filters.scheduleLessons.teacher_id">
                <option value="">Все преподаватели</option>
                <option v-for="teacher in teacherOptions" :key="teacher.value" :value="teacher.value">{{ teacher.label }}</option>
              </select>
            </label>
            <label>
              Дисциплина
              <select v-model="filters.scheduleLessons.subject_id">
                <option value="">Все дисциплины</option>
                <option v-for="subject in subjectOptions" :key="subject.value" :value="subject.value">{{ subject.label }}</option>
              </select>
            </label>
            <label>
              Аудитория
              <select v-model="filters.scheduleLessons.classroom_id">
                <option value="">Все аудитории</option>
                <option v-for="classroom in classroomOptions" :key="classroom.value" :value="classroom.value">{{ classroom.label }}</option>
              </select>
            </label>
            <div class="filter-actions">
              <button type="submit" :disabled="loading">Применить</button>
              <button class="secondary-filter-button" type="button" :disabled="loading" @click="resetScheduleFilters">
                Сбросить
              </button>
            </div>
          </form>

          <div class="table-wrap">
            <table>
              <thead><tr><th>Дата</th><th>Время</th><th>Тип</th><th>Группа</th><th>Дисциплина</th><th>Аудитория</th><th>Тема</th><th>Действия</th></tr></thead>
              <tbody>
                <tr v-for="lesson in filteredScheduleLessons" :key="lesson.id">
                  <td>{{ lesson.lesson_date }}</td>
                  <td>{{ lesson.starts_at }}–{{ lesson.ends_at }}</td>
                  <td>{{ lessonTypeLabel(lesson.lesson_type) }}</td>
                  <td>{{ lesson.group?.name }}</td>
                  <td>{{ lesson.subject?.name }}</td>
                  <td>{{ lesson.classroom?.number || '—' }}</td>
                  <td>{{ lesson.topic || '—' }}</td>
                  <td>
                    <div class="row-actions">
                      <button type="button" @click="editItem('schedule-lessons', lesson)">Редактировать</button>
                      <button class="danger-button" type="button" @click="removeItem('schedule-lessons', lesson, lessonLabel(lesson))">Удалить</button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </section>

      <section v-if="activeSection === 'journal'" class="stack">
        <section class="panel">
          <div class="panel-header">
            <h2>Журнал занятия</h2>
            <label class="toolbar-field">
              Занятие
              <select v-model="selectedJournalLessonId">
                <option value="">Выберите занятие</option>
                <option v-for="lesson in journalLessonOptions" :key="lesson.value" :value="lesson.value">
                  {{ lesson.label }}
                </option>
              </select>
            </label>
          </div>

          <div v-if="selectedJournalLesson" class="lesson-summary">
            <span>{{ selectedJournalLesson.group?.name || 'Группа не указана' }}</span>
            <span>{{ selectedJournalLesson.subject?.name || 'Дисциплина не указана' }}</span>
            <span>{{ selectedJournalLesson.teacher ? fullName(selectedJournalLesson.teacher) : 'Преподаватель не указан' }}</span>
            <span>{{ lessonTypeLabel(selectedJournalLesson.lesson_type) }}</span>
          </div>

          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Студент</th>
                  <th>Группа</th>
                  <th>Посещаемость</th>
                  <th>Оценка</th>
                  <th>Комментарий</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="!selectedJournalLesson">
                  <td colspan="5">Выберите занятие, чтобы открыть журнал группы.</td>
                </tr>
                <tr v-else-if="journalStudents.length === 0">
                  <td colspan="5">В группе пока нет студентов.</td>
                </tr>
                <template v-else>
                  <tr v-for="student in journalStudents" :key="student.id">
                    <td>{{ fullName(student) }}</td>
                    <td>{{ student.group?.name || groupName(student.group_id) }}</td>
                    <td>
                      <select
                        class="inline-select"
                        :value="attendanceByStudent[student.id]?.status || ''"
                        :disabled="loading"
                        @change="saveAttendance(student, $event.target.value)"
                      >
                        <option value="">Не отмечено</option>
                        <option value="present">Присутствовал</option>
                        <option value="absent">Отсутствовал</option>
                        <option value="late">Опоздал</option>
                        <option value="excused">Уважительная причина</option>
                      </select>
                    </td>
                    <td>
                      <select
                        class="inline-select grade-select"
                        :value="classworkGradeByStudent[student.id]?.grade || ''"
                        :disabled="loading"
                        @change="saveClassworkGrade(student, $event.target.value)"
                      >
                        <option value="">Без оценки</option>
                        <option value="5">5</option>
                        <option value="4">4</option>
                        <option value="3">3</option>
                        <option value="2">2</option>
                        <option value="зачет">Зачет</option>
                        <option value="н/а">Не аттестован</option>
                      </select>
                    </td>
                    <td>
                      {{ attendanceByStudent[student.id]?.comment || classworkGradeByStudent[student.id]?.comment || '—' }}
                    </td>
                  </tr>
                </template>
              </tbody>
            </table>
          </div>
        </section>

        <section class="panel">
          <h2>История оценок</h2>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Студент</th><th>Занятие</th><th>Оценка</th><th>Тип</th><th>Комментарий</th></tr></thead>
              <tbody>
                <tr v-for="grade in state.grades" :key="grade.id">
                  <td>{{ grade.student ? fullName(grade.student) : grade.student_id }}</td>
                  <td>{{ grade.schedule_lesson?.lesson_date }} {{ grade.schedule_lesson?.starts_at }}</td>
                  <td>{{ grade.grade }}</td>
                  <td>{{ gradeTypeLabel(grade.grade_type) }}</td>
                  <td>{{ grade.comment || '—' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </section>

      <section v-if="activeSection === 'reports'" class="stack">
        <section class="panel">
          <div class="panel-header">
            <h2>Посещаемость группы</h2>
            <button
              class="export-button"
              type="button"
              :disabled="loading || !state.attendanceReport"
              @click="exportAttendanceReport"
            >
              Экспорт CSV
            </button>
          </div>

          <form class="filter-panel report-filter-panel" @submit.prevent="loadAttendanceReport">
            <label>
              Группа
              <select v-model="filters.attendanceReport.group_id" required>
                <option value="">Выберите группу</option>
                <option v-for="group in groupOptions" :key="group.value" :value="group.value">{{ group.label }}</option>
              </select>
            </label>
            <label>
              Начало периода
              <input v-model="filters.attendanceReport.date_from" type="date" />
            </label>
            <label>
              Окончание периода
              <input v-model="filters.attendanceReport.date_to" type="date" />
            </label>
            <div class="filter-actions">
              <button type="submit" :disabled="loading">Сформировать</button>
            </div>
          </form>

          <div v-if="attendanceReportSummary" class="report-summary-grid">
            <article>
              <span>Занятий</span>
              <strong>{{ attendanceReportSummary.total_lessons }}</strong>
            </article>
            <article>
              <span>Студентов</span>
              <strong>{{ attendanceReportSummary.students_count }}</strong>
            </article>
            <article>
              <span>Присутствий</span>
              <strong>{{ attendanceReportSummary.present }}</strong>
            </article>
            <article>
              <span>Отсутствий</span>
              <strong>{{ attendanceReportSummary.absent }}</strong>
            </article>
            <article>
              <span>Опозданий</span>
              <strong>{{ attendanceReportSummary.late }}</strong>
            </article>
            <article>
              <span>Не отмечено</span>
              <strong>{{ attendanceReportSummary.unmarked }}</strong>
            </article>
          </div>

          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Студент</th>
                  <th>Присутствовал</th>
                  <th>Отсутствовал</th>
                  <th>Опоздал</th>
                  <th>Уважительная причина</th>
                  <th>Не отмечено</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="!state.attendanceReport">
                  <td colspan="6">Выберите группу и сформируйте отчет.</td>
                </tr>
                <tr v-else-if="attendanceReportRows.length === 0">
                  <td colspan="6">В выбранной группе нет студентов.</td>
                </tr>
                <template v-else>
                  <tr v-for="student in attendanceReportRows" :key="student.id">
                    <td>{{ student.name }}</td>
                    <td>{{ student.present }}</td>
                    <td>{{ student.absent }}</td>
                    <td>{{ student.late }}</td>
                    <td>{{ student.excused }}</td>
                    <td>{{ student.unmarked }}</td>
                  </tr>
                </template>
              </tbody>
            </table>
          </div>
        </section>

        <section class="panel">
          <div class="panel-header">
            <h2>Оценки группы</h2>
            <button
              class="export-button"
              type="button"
              :disabled="loading || !state.gradeReport"
              @click="exportGradeReport"
            >
              Экспорт CSV
            </button>
          </div>

          <form class="filter-panel grade-report-filter-panel" @submit.prevent="loadGradeReport">
            <label>
              Группа
              <select v-model="filters.gradeReport.group_id" required>
                <option value="">Выберите группу</option>
                <option v-for="group in groupOptions" :key="group.value" :value="group.value">{{ group.label }}</option>
              </select>
            </label>
            <label>
              Дисциплина
              <select v-model="filters.gradeReport.subject_id" required>
                <option value="">Выберите дисциплину</option>
                <option v-for="subject in subjectOptions" :key="subject.value" :value="subject.value">{{ subject.label }}</option>
              </select>
            </label>
            <label>
              Начало периода
              <input v-model="filters.gradeReport.date_from" type="date" />
            </label>
            <label>
              Окончание периода
              <input v-model="filters.gradeReport.date_to" type="date" />
            </label>
            <div class="filter-actions">
              <button type="submit" :disabled="loading">Сформировать</button>
            </div>
          </form>

          <div v-if="gradeReportSummary" class="report-summary-grid">
            <article>
              <span>Занятий</span>
              <strong>{{ gradeReportSummary.lessons_count }}</strong>
            </article>
            <article>
              <span>Студентов</span>
              <strong>{{ gradeReportSummary.students_count }}</strong>
            </article>
            <article>
              <span>Оценок</span>
              <strong>{{ gradeReportSummary.grades_count }}</strong>
            </article>
            <article>
              <span>Числовых</span>
              <strong>{{ gradeReportSummary.numeric_grades_count }}</strong>
            </article>
            <article>
              <span>Средний балл</span>
              <strong>{{ gradeReportSummary.average_grade ?? '—' }}</strong>
            </article>
          </div>

          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Студент</th>
                  <th>Оценки</th>
                  <th>Всего оценок</th>
                  <th>Числовых оценок</th>
                  <th>Средний балл</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="!state.gradeReport">
                  <td colspan="5">Выберите группу, дисциплину и сформируйте отчет.</td>
                </tr>
                <tr v-else-if="gradeReportRows.length === 0">
                  <td colspan="5">В выбранной группе нет студентов.</td>
                </tr>
                <template v-else>
                  <tr v-for="student in gradeReportRows" :key="student.id">
                    <td>{{ student.name }}</td>
                    <td>{{ student.grades.length ? student.grades.join(', ') : '—' }}</td>
                    <td>{{ student.grades_count }}</td>
                    <td>{{ student.numeric_grades_count }}</td>
                    <td>{{ student.average_grade ?? '—' }}</td>
                  </tr>
                </template>
              </tbody>
            </table>
          </div>
        </section>
      </section>
    </main>
  </div>
</template>
