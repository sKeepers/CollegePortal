import { formatDate as formatCollegeDate, formatDateTime as formatCollegeDateTime } from '../../../utils/datetime'
export function extractRows(payload) {
  return Array.isArray(payload?.data) ? payload.data : []
}

export function extractTotal(payload) {
  return Number(payload?.meta?.total ?? extractRows(payload).length ?? 0)
}

export function todayIso() {
  return new Date().toISOString().slice(0, 10)
}

export function currentDateRu() {
  // Сегодня — по календарю колледжа, а не по календарю смотрящего.
  return formatCollegeDate(new Date().toISOString(), { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
}

export function formatShortDateTime(value) {
  if (!value) {
    return '—'
  }

  return formatCollegeDateTime(value, { year: undefined })
}

export function teacherName(teacher) {
  return [teacher?.last_name, teacher?.first_name, teacher?.middle_name].filter(Boolean).join(' ')
}

export function groupName(group) {
  return group?.name || 'Группа не указана'
}
