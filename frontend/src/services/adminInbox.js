import { api } from './api'

/**
 * Входящие администратора: заявки и напоминания, требующие решения.
 *
 * Раньше этот список жил внутри `AppLayout.vue` и был доступен только
 * «колокольчику» на десктопе. Мобильному кабинету (`MOB-004`) нужен **тот же**
 * список, а требование задачи — чтобы он совпадал с десктопным. Совпадение,
 * написанное дважды, разъезжается на первой же новой строке, поэтому сборка
 * вынесена сюда, и оба места зовут её.
 *
 * Каждый источник спрашивает своё право до запроса: без этого экран
 * администратора без `uat.manage` собирал бы половину входящих из ответов 403.
 */

/** Напоминание о неполных карточках повторяется раз в восемь часов, а не при каждом опросе. */
export const STUDENT_CARD_REMINDER_PERIOD = 8 * 60 * 60 * 1000

/** «Учебная часть 2» ведёт контингент — напоминание о карточках приходит ей. */
export function canReceiveStudentCardReminder(auth) {
  return auth.hasRole('study_records') && auth.can('students.view')
}

export function canReceiveAdminInbox(auth) {
  return auth.can('uat.manage')
    || auth.can('journal.reopen')
    || auth.can('trash.manage')
    || canReceiveStudentCardReminder(auth)
}

/**
 * @returns {Promise<Array<{id: string, kind: string, title: string, description: string, to: object}>>}
 */
export async function loadAdminInbox(auth, now = Date.now()) {
  if (!canReceiveAdminInbox(auth)) return []

  const requests = []

  if (auth.can('uat.manage')) {
    requests.push(api.list('admin/uat/feedback', { status: 'new', per_page: 10 }).then((payload) => (payload?.data || []).map((item) => ({
      id: `feedback-${item.id}`,
      kind: 'uat_feedback',
      title: 'Новое сообщение о проблеме',
      description: item.title || 'Требуется проверка',
      to: { path: '/admin/uat', query: { feedback: item.id } },
    }))))
  }

  if (auth.can('journal.reopen')) {
    requests.push(api.list('journal/edit-requests/pending').then((payload) => (payload?.data || []).map((item) => ({
      id: `journal-${item.id}`,
      kind: 'journal_edit_request',
      // Идентификатор самой заявки нужен мобильному кабинету: решение по ней
      // принимается прямо с телефона, а не переходом на десктопную страницу.
      requestId: item.id,
      title: 'Запрос на редактирование журнала',
      description: `${item.lesson?.subject || 'Занятие'} · ${item.lesson?.group || 'Группа'}`,
      reason: item.reason || '',
      requestedBy: item.requested_by_name || '',
      to: { path: '/journal', query: { journalLesson: item.journal_lesson_id } },
    }))))
  }

  if (auth.can('trash.manage')) {
    requests.push(api.list('deletion-requests/pending').then((payload) => (payload?.data || []).map((item) => ({
      id: `trash-${item.id}`,
      kind: 'deletion_request',
      title: 'Заявка на удаление карточки',
      description: `${item.subject_label || 'Карточка'} · ${item.requested_by || 'неизвестно'}`,
      to: { path: '/admin/trash' },
    }))))
  }

  if (canReceiveStudentCardReminder(auth)) {
    requests.push(api.list('students/card-completeness/summary').then((payload) => {
      const summary = payload?.data || {}
      if (!summary.incomplete) return []

      return [{
        id: `students-incomplete-${Math.floor(now / STUDENT_CARD_REMINDER_PERIOD)}`,
        kind: 'student_cards',
        title: `Неполные карточки студентов: ${summary.incomplete}`,
        description: `Нет паспорта: ${summary.missing_identity || 0} · нет документа об образовании: ${summary.missing_education || 0} · нет СНИЛС: ${summary.missing_snils || 0}`,
        to: { path: '/students', query: { completeness: 'incomplete' } },
      }]
    }))
  }

  return (await Promise.all(requests)).flat()
}
