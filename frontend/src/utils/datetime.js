/**
 * Дата и время на экранах и в печатных документах — в одном месте.
 *
 * Портал живёт в UTC (`config/app.php`), и это правильно: в базе однозначное
 * время, не зависящее от того, где стоит сервер. Но человеку показывать надо
 * **время колледжа**, а не время его машины. До 03.09.2026 показ шёл 40
 * вызовами `toLocale*` в 28 файлах, и `timeZone` не был задан **ни в одном** —
 * то есть портал колледжа рисовал час того, кто смотрит. Пока все смотрят из
 * одного пояса, это незаметно; одна машина с другими часами — и она видит
 * другие числа, чем все остальные, причём молча. Разбор целиком —
 * `docs/TIME_ON_PRINTED_DOCUMENTS.md`.
 *
 * **Здесь два вида значений, и путать их нельзя — перевод в пояс ломает второй.**
 *
 * 1. **Мгновение** — `2026-08-21T21:17:49.000000Z` из колонки `timestamp`.
 *    У него есть пояс, и показывать его надо по часам колледжа.
 * 2. **Календарная дата** — `2026-09-01` из колонки `date`. Пояса у неё нет
 *    вовсе: занятие первого сентября первого сентября и есть, где бы ни стоял
 *    браузер. Такую дату нельзя разбирать как момент: `new Date('2026-07-29')`
 *    — это полночь UTC, и западнее Гринвича `toLocaleDateString` покажет
 *    **28 июля**. Замерено: в поясе `America/New_York` даёт `28.07.2026`.
 *
 * Поэтому вид определяется по самому значению, а не по имени вызова: голая
 * `ГГГГ-ММ-ДД` считается календарной датой и рисуется на полдне UTC в поясе
 * UTC — такая пара не может съехать на соседний день ни в одном поясе Земли.
 * Всё остальное считается мгновением и рисуется в поясе колледжа.
 *
 * **Календарная дата не получает выдуманного часа.** `formatDateTime('2026-09-01')`
 * возвращает `01.09.2026`, а не `01.09.2026, 03:00`: до этой правки именно
 * такой час и рисовался — полночь UTC, пересчитанная в пояс смотрящего.
 */

/** Пояс колледжа. Зеркало `App\Support\Time\CollegeTime::ZONE` на сервере — значения обязаны совпадать. */
export const COLLEGE_TIME_ZONE = 'Europe/Moscow'

const CALENDAR_DAY = /^(\d{4})-(\d{2})-(\d{2})$/

const DATE_OPTIONS = { day: '2-digit', month: '2-digit', year: 'numeric' }
const TIME_OPTIONS = { hour: '2-digit', minute: '2-digit', hour12: false }

/**
 * Значение, приведённое к паре «момент + пояс, в котором его рисовать».
 *
 * @returns {{ at: Date, zone: string, calendar: boolean } | null}
 */
function moment(value) {
  const day = CALENDAR_DAY.exec(String(value ?? '').trim())

  if (day) {
    // Полдень UTC: до соседних суток от него не дотягивается ни один пояс.
    return { at: new Date(Date.UTC(Number(day[1]), Number(day[2]) - 1, Number(day[3]), 12)), zone: 'UTC', calendar: true }
  }

  const at = new Date(value)

  return Number.isNaN(at.getTime()) ? null : { at, zone: COLLEGE_TIME_ZONE, calendar: false }
}

function render(value, options, empty) {
  if (value === null || value === undefined || value === '') {
    return empty
  }

  const parsed = moment(value)

  if (!parsed) {
    return String(value)
  }

  return new Intl.DateTimeFormat('ru-RU', { ...options, timeZone: parsed.zone }).format(parsed.at)
}

/** Дата: `01.09.2026`. Годится и для календарной даты, и для мгновения. */
export function formatDate(value, options = {}, empty = '—') {
  return render(value, { ...DATE_OPTIONS, ...options }, empty)
}

/** Дата и время по часам колледжа: `21.08.2026, 21:17`. У календарной даты часа нет — она возвращается одной датой. */
export function formatDateTime(value, options = {}, empty = '—') {
  if (CALENDAR_DAY.test(String(value ?? '').trim())) {
    return formatDate(value, options, empty)
  }

  return render(value, { ...DATE_OPTIONS, ...TIME_OPTIONS, ...options }, empty)
}

/** Время по часам колледжа: `21:17`. */
export function formatTime(value, options = {}, empty = '—') {
  return render(value, { ...TIME_OPTIONS, ...options }, empty)
}

/**
 * Календарная дата строкой, без обращения к календарю вовсе: `2026-07-29` → `29.07.2026`.
 *
 * Оставлено отдельно от `formatDate`, потому что печатным листам нужен ровно
 * этот вид и ровно это поведение при непонятном значении — вернуть его самим,
 * а не прочерк: в подвале документа прочерк вместо даты читается как ошибка
 * данных. Заведено 28.08.2026, когда в отчёте заселённости на одном листе
 * стояло «За период 2026-07-29 — 2026-08-28» и «Составлен 28.08.2026».
 */
export function formatDay(value) {
  const day = CALENDAR_DAY.exec(String(value ?? '').trim())

  return day ? `${day[3]}.${day[2]}.${day[1]}` : String(value ?? '')
}

/** Час, когда документ напечатан, — по часам колледжа, а не по часам печатающего. */
export function printedAtNow() {
  return formatDateTime(new Date().toISOString())
}

/** Час, когда документ напечатан, но без часа — для листа, который висит на двери месяцами. */
export function printedDayNow() {
  return formatDate(new Date().toISOString())
}

/**
 * Объект `Date`, собранный кодом из местных суток, — обратно в календарную дату.
 *
 * Такой `Date` описывает **день**, а не мгновение: его собирают из `getDate()`
 * соседнего дня, и часы в нём случайны. Рисовать его в поясе колледжа нельзя —
 * у смотрящего восточнее Москвы полночь его суток попадёт в предыдущий день
 * колледжа, и в шапке расписания встанет вчерашнее число.
 */
export function toCalendarDay(date) {
  const at = date instanceof Date ? date : new Date(date)

  if (Number.isNaN(at.getTime())) {
    return String(date ?? '')
  }

  return [at.getFullYear(), String(at.getMonth() + 1).padStart(2, '0'), String(at.getDate()).padStart(2, '0')].join('-')
}
