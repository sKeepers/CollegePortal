import { api } from './api'
import { formatPhone } from '../utils/phone'

const MAX_RESULTS_PER_PROVIDER = 5

function extractRows(payload) {
  return Array.isArray(payload?.data) ? payload.data : []
}

function normalizeText(value) {
  return String(value || '').trim().toLowerCase()
}

function fullName(person) {
  return [person?.last_name, person?.first_name, person?.middle_name].filter(Boolean).join(' ')
}

function teacherName(teacher) {
  return fullName(teacher)
}

function programLabel(program) {
  return [
    program?.name,
    program?.specialty?.code,
    program?.year_start,
    program?.study_form,
  ].filter(Boolean).join(' · ')
}

function containsQuery(query, values) {
  const normalizedQuery = normalizeText(query)
  return values.some((value) => normalizeText(value).includes(normalizedQuery))
}

function scoreResult(query, values) {
  const normalizedQuery = normalizeText(query)
  const normalizedValues = values.map((value) => normalizeText(value)).filter(Boolean)

  if (normalizedValues.some((value) => value === normalizedQuery)) {
    return 0
  }

  if (normalizedValues.some((value) => value.startsWith(normalizedQuery))) {
    return 1
  }

  return 2
}

function sortByRelevance(query, records, fields) {
  return [...records].sort((left, right) => (
    scoreResult(query, fields(left)) - scoreResult(query, fields(right))
  ))
}

async function searchStudents(query) {
  const payload = await api.listAll('students', { search: query })
  const rows = extractRows(payload)
  const matched = rows.filter((student) => containsQuery(query, [
    fullName(student),
    student.group?.name,
    student.phone,
    student.email,
    student.status,
    student.enrollment_date,
  ]))

  return sortByRelevance(query, matched.length ? matched : rows, (student) => [
    fullName(student),
    student.group?.name,
    student.email,
  ])
    .slice(0, MAX_RESULTS_PER_PROVIDER)
    .map((student) => ({
      id: student.id,
      type: 'student',
      group: 'Студенты',
      title: fullName(student) || `Студент #${student.id}`,
      subtitle: [student.group?.name, student.status].filter(Boolean).join(' · ') || 'Карточка студента',
      meta: [formatPhone(student.phone), student.email].filter(Boolean),
      route: {
        path: `/students/${student.id}`,
        query: {
          search: fullName(student) || query,
        },
      },
      entity: student,
    }))
}

async function searchTeachers(query) {
  const payload = await api.listAll('teachers', { search: query })
  const rows = extractRows(payload)
  const matched = rows.filter((teacher) => containsQuery(query, [
    fullName(teacher),
    teacher.phone,
    teacher.email,
    teacher.position,
    teacher.department,
    teacher.is_active ? 'активен' : 'неактивен',
  ]))

  return sortByRelevance(query, matched.length ? matched : rows, (teacher) => [
    fullName(teacher),
    teacher.department,
    teacher.email,
  ])
    .slice(0, MAX_RESULTS_PER_PROVIDER)
    .map((teacher) => ({
      id: teacher.id,
      type: 'teacher',
      group: 'Преподаватели',
      title: fullName(teacher) || `Преподаватель #${teacher.id}`,
      subtitle: [teacher.department, teacher.position].filter(Boolean).join(' · ') || 'Карточка преподавателя',
      meta: [formatPhone(teacher.phone), teacher.email, teacher.is_active ? 'Активен' : 'Неактивен'].filter(Boolean),
      route: {
        path: `/teachers/${teacher.id}`,
        query: {
          search: fullName(teacher) || query,
        },
      },
      entity: teacher,
    }))
}

async function searchSubjects(query) {
  const payload = await api.listAll('subjects', { search: query })
  const rows = extractRows(payload)
  const matched = rows.filter((subject) => containsQuery(query, [
    subject.name,
    subject.code,
    subject.department,
    subject.description,
    ...(Array.isArray(subject.teachers) ? subject.teachers.map(teacherName) : []),
  ]))

  return sortByRelevance(query, matched.length ? matched : rows, (subject) => [
    subject.name,
    subject.code,
    subject.department,
  ])
    .slice(0, MAX_RESULTS_PER_PROVIDER)
    .map((subject) => ({
      id: subject.id,
      type: 'subject',
      group: 'Дисциплины',
      title: subject.name || `Дисциплина #${subject.id}`,
      subtitle: [subject.code, subject.department].filter(Boolean).join(' · ') || 'Карточка дисциплины',
      meta: [
        Array.isArray(subject.teachers) && subject.teachers.length
          ? subject.teachers.map(teacherName).filter(Boolean).join(', ')
          : '',
        subject.description,
      ].filter(Boolean),
      route: {
        path: `/subjects/${subject.id}`,
        query: {
          search: subject.name || query,
        },
      },
      entity: subject,
    }))
}

function classroomLabel(classroom) {
  return [
    classroom?.number,
    classroom?.building ? `корп. ${classroom.building}` : '',
  ].filter(Boolean).join(' · ')
}

async function searchClassrooms(query) {
  const payload = await api.listAll('classrooms')
  const rows = extractRows(payload)
  const matched = rows.filter((classroom) => containsQuery(query, [
    classroomLabel(classroom),
    classroom.number,
    classroom.building,
    classroom.floor,
    classroom.capacity,
    classroom.type,
    classroom.description,
  ]))

  return sortByRelevance(query, matched, (classroom) => [
    classroomLabel(classroom),
    classroom.number,
    classroom.type,
  ])
    .slice(0, MAX_RESULTS_PER_PROVIDER)
    .map((classroom) => ({
      id: classroom.id,
      type: 'classroom',
      group: 'Аудитории',
      title: classroomLabel(classroom) || `Аудитория #${classroom.id}`,
      subtitle: [classroom.type, classroom.capacity ? `${classroom.capacity} мест` : ''].filter(Boolean).join(' · ') || 'Карточка аудитории',
      meta: [
        classroom.floor !== null && classroom.floor !== undefined ? `${classroom.floor} этаж` : '',
        classroom.description,
      ].filter(Boolean),
      route: {
        path: `/classrooms/${classroom.id}`,
        query: {
          search: classroom.number || query,
        },
      },
      entity: classroom,
    }))
}

async function searchApplicantApplications(query) {
  const payload = await api.list('applicant-applications', { search: query })
  const rows = extractRows(payload)
  const matched = rows.filter((application) => containsQuery(query, [
    fullName(application),
    application.phone,
    application.email,
    application.status,
    application.education_program?.name,
    application.education_program?.specialty?.code,
    application.education_program?.specialty?.name,
  ]))

  return sortByRelevance(query, matched.length ? matched : rows, (application) => [
    fullName(application),
    application.email,
    application.education_program?.name,
  ])
    .slice(0, MAX_RESULTS_PER_PROVIDER)
    .map((application) => ({
      id: application.id,
      type: 'applicant',
      group: 'Приёмная комиссия',
      title: fullName(application) || `Заявление #${application.id}`,
      subtitle: [
        application.education_program?.specialty?.name,
        application.education_program?.name,
      ].filter(Boolean).join(' · ') || 'Заявление абитуриента',
      meta: [formatPhone(application.phone), application.email, application.status].filter(Boolean),
      route: {
        path: `/admissions/${application.id}`,
        query: {
          search: fullName(application) || query,
        },
      },
      entity: application,
    }))
}

async function searchGroups(query) {
  const payload = await api.listAll('groups', { per_page: 200 })
  const rows = extractRows(payload)
  const matched = rows.filter((group) => containsQuery(query, [
    group.name,
    group.course,
    group.year_start,
    group.specialty,
    programLabel(group.education_program),
    group.education_program?.study_form,
    teacherName(group.curator),
  ]))

  return sortByRelevance(query, matched, (group) => [
    group.name,
    group.specialty,
    programLabel(group.education_program),
  ])
    .slice(0, MAX_RESULTS_PER_PROVIDER)
    .map((group) => ({
      id: group.id,
      type: 'group',
      group: 'Группы',
      title: group.name || `Группа #${group.id}`,
      subtitle: [
        group.course ? `${group.course} курс` : '',
        group.year_start ? `${group.year_start} год набора` : '',
        group.education_program?.study_form,
      ].filter(Boolean).join(' · ') || 'Карточка группы',
      meta: [group.specialty, programLabel(group.education_program), teacherName(group.curator)].filter(Boolean),
      route: {
        path: `/groups/${group.id}`,
      },
      entity: group,
    }))
}

const providers = [
  {
    type: 'student',
    label: 'Студенты',
    search: searchStudents,
  },
  {
    type: 'group',
    label: 'Группы',
    search: searchGroups,
  },
  {
    type: 'teacher',
    label: 'Преподаватели',
    search: searchTeachers,
  },
  {
    type: 'subject',
    label: 'Дисциплины',
    search: searchSubjects,
  },
  {
    type: 'classroom',
    label: 'Аудитории',
    search: searchClassrooms,
  },
  {
    type: 'applicant',
    label: 'Приёмная комиссия',
    search: searchApplicantApplications,
  },
]

export async function search(query) {
  const normalizedQuery = String(query || '').trim()

  if (normalizedQuery.length < 2) {
    return []
  }

  const settled = await Promise.allSettled(
    providers.map((provider) => provider.search(normalizedQuery)),
  )

  return settled.flatMap((result) => (result.status === 'fulfilled' ? result.value : []))
}

export const searchService = {
  search,
  providers,
}
