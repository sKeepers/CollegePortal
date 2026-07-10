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
  return new Intl.DateTimeFormat('ru-RU', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  }).format(new Date())
}

export function formatShortDateTime(value) {
  if (!value) {
    return '—'
  }

  const date = new Date(value)

  if (Number.isNaN(date.getTime())) {
    return value
  }

  return new Intl.DateTimeFormat('ru-RU', {
    day: '2-digit',
    month: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  }).format(date)
}

export function teacherName(teacher) {
  return [teacher?.last_name, teacher?.first_name, teacher?.middle_name].filter(Boolean).join(' ')
}

export function groupName(group) {
  return group?.name || 'Группа не указана'
}
