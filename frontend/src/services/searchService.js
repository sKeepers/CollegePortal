import { api } from './api'

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
  const payload = await api.list('students', { search: query })
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
      meta: [student.phone, student.email].filter(Boolean),
      route: {
        path: '/students',
        query: {
          selected: student.id,
          search: fullName(student) || query,
        },
      },
      entity: student,
    }))
}

async function searchGroups(query) {
  const payload = await api.list('groups')
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
        path: '/groups',
        query: { selected: group.id },
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
