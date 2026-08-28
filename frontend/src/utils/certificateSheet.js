import { escapeHtml } from './print'

/**
 * Печатный бланк справки студенту.
 *
 * Собирается отдельным документом со своими стилями, а не печатается страницей.
 * Приём общий с ведомостью выдачи карт и книгой регистрации дипломов, и он
 * оплачен: лист, печатавшийся самой страницей, однажды вышел чистым при трёх
 * живых строках, а 28.08.2026 на карточках с учётными данными нашлось, что
 * правила `@media print` вовсе не применялись — их перебивал соседний
 * scoped-блок. В отдельном документе каскада приложения нет, и спорить не с чем.
 *
 * Две справки на лист — так печатает колледж: в обоих образцах владельца на
 * странице по две штуки с соседними номерами.
 */

/**
 * Форма обучения в родительном падеже: «очной формы обучения».
 *
 * В справочнике она хранится именительным — «Очная», «Заочная», — а в обороте
 * бланка нужен родительный. Замечено глазами на первом же снимке: печаталось
 * «студентом 2 курса очная формы обучения». Незнакомое значение печатается как
 * есть: неверный падеж читается, выдуманное слово — нет.
 */
function studyForm(value) {
  const known = {
    'очная': 'очной',
    'заочная': 'заочной',
    'очно-заочная': 'очно-заочной',
  }
  const lowered = String(value ?? '').trim().toLowerCase()
  return known[lowered] || lowered
}

function ru(date) {
  if (!date) return ''
  const [year, month, day] = String(date).slice(0, 10).split('-')
  return `${day}.${month}.${year}`
}

/**
 * Один бланк.
 *
 * Первый курс и второй-четвёртый отличаются ровно одной строкой: у старших
 * курсов рядом с приказом о зачислении стоит приказ о переводе. Пустого места
 * в бланке нет вовсе — если приказа о переводе нет, строки нет тоже.
 */
function certificate(row, letterhead) {
  const transferred = Number(row.course) > 1 && row.transfer_order_number

  const enrollment = transferred
    ? `Зачислен(а) на 1 курс приказом от ${ru(row.enrollment_order_date)} г. № ${escapeHtml(row.enrollment_order_number)}.`
    : `Зачислен (а) приказом от: ${ru(row.enrollment_order_date)} г. № ${escapeHtml(row.enrollment_order_number)}`

  const transfer = transferred
    ? `<p class="line">Переведен(а) на ${escapeHtml(row.course)} курс приказом от ${ru(row.transfer_order_date)} г. № ${escapeHtml(row.transfer_order_number)}.</p>`
    : ''

  return `<section class="sheet">
  <header class="head">
    <div>${escapeHtml(letterhead.founder)}</div>
    <div>${escapeHtml(letterhead.fullName)}</div>
    <div>${escapeHtml(letterhead.shortName)}</div>
    <div class="small">${escapeHtml(letterhead.contacts)}</div>
    <div class="small">${escapeHtml(letterhead.requisites)}</div>
  </header>

  <div class="meta">
    <span>${ru(row.issued_on)} г.</span>
    <span>№ ${escapeHtml(row.number)}</span>
  </div>

  <h1>СПРАВКА</h1>

  <p class="line">Подтверждает, что ${escapeHtml(row.full_name)}, ${ru(row.birth_date)} года рождения,
  действительно является студентом ${escapeHtml(row.course)} курса ${escapeHtml(letterhead.genitiveName)}
  ${escapeHtml(studyForm(row.study_form))} формы обучения,
  специальности: ${escapeHtml(row.specialty)}.</p>

  <p class="line">${enrollment}</p>
  ${transfer}

  <p class="line">Начало обучения: ${ru(row.study_start)} г.</p>
  <p class="line">Срок окончания обучения: ${ru(row.study_end)} г.</p>

  <p class="line">Справка дана для представления по месту требования.</p>

  <div class="sign">
    <span>Директор</span>
    <span>${escapeHtml(letterhead.director)}</span>
  </div>
</section>`
}

export function buildCertificateSheet(rows, letterhead) {
  const blanks = rows.map((row) => certificate(row, letterhead)).join('\n')

  return `<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Справки студентам</title>
<style>
  @page { size: A4 portrait; margin: 14mm; }
  body { margin: 0; color: #000; font-family: "Times New Roman", Times, serif; font-size: 13px; }
  /* Две справки на лист: третья начинает новую страницу. Так печатает колледж. */
  .sheet { min-height: 122mm; padding-bottom: 6mm; }
  .sheet:nth-of-type(2n) { page-break-after: always; }
  .sheet:last-of-type { page-break-after: auto; }
  .head { text-align: center; line-height: 1.25; }
  .head .small { font-size: 11px; }
  .meta { display: flex; justify-content: space-between; margin: 10px 0 6px; }
  h1 { font-size: 15px; text-align: center; margin: 8px 0 10px; letter-spacing: 2px; }
  .line { margin: 0 0 6px; text-align: justify; line-height: 1.35; }
  .sign { display: flex; justify-content: space-between; margin-top: 16px; }
</style>
</head>
<body>
${blanks}
</body>
</html>`
}
