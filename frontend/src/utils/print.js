/**
 * Печать отдельным документом, а не текущей страницей.
 *
 * Оплачено пустым листом: ведомость выдачи карт печаталась самой страницей —
 * прятала соседей правилом `body > *:not(.печатное)` и выносила себя в корень
 * через `Teleport`. Владелец нажал «Печать» при трёх живых строках и получил
 * чистую бумагу, а выяснить, какой из стилей приложения победил, без браузера
 * невозможно.
 *
 * Поэтому каскада приложения здесь нет вовсе. Документ собирается целиком со
 * своими стилями и печатается из скрытой рамки: ни Quasar, ни контейнеры
 * разметки, ни порядок узлов в `body` до него не достают. Что собрано, то и
 * печатается.
 *
 * Рамка, а не новое окно: всплывающие окна блокируются, и человек видит, что
 * «ничего не произошло».
 */
export function printHtmlDocument(html) {
  const frame = document.createElement('iframe')
  frame.setAttribute('aria-hidden', 'true')
  frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;'
  frame.srcdoc = html

  frame.onload = () => {
    const win = frame.contentWindow
    if (!win) return

    win.addEventListener('afterprint', () => frame.remove(), { once: true })
    win.focus()
    win.print()
    // Если браузер не пришлёт `afterprint`, рамка не должна остаться навсегда.
    window.setTimeout(() => frame.remove(), 60000)
  }

  document.body.appendChild(frame)
}

/** Экранирование: в печатные листы попадают настоящие ФИО и телефоны. */
export function escapeHtml(value) {
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
}

/** Общая обёртка листа: шапка, подпись внизу и одни и те же поля. */
export function printPage({ title, subtitle = '', body, footer = '', landscape = true }) {
  return `<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>${escapeHtml(title)}</title>
<style>
  @page { size: A4 ${landscape ? 'landscape' : 'portrait'}; margin: 12mm; }
  body { margin: 0; color: #000; font-family: Arial, "Helvetica Neue", Helvetica, sans-serif; }
  h1 { font-size: 16px; margin: 0 0 4px; }
  .subtitle { font-size: 12px; margin-bottom: 10px; }
  table { width: 100%; border-collapse: collapse; font-size: 11px; }
  th, td { border: 1px solid #000; padding: 4px 6px; text-align: left; vertical-align: top; }
  th { background: #eeeeee; }
  h2 { font-size: 13px; margin: 14px 0 4px; }
  .footer { margin-top: 10px; font-size: 11px; }
  .sign { width: 22%; }
</style>
</head>
<body>
<h1>${escapeHtml(title)}</h1>
${subtitle ? `<div class="subtitle">${escapeHtml(subtitle)}</div>` : ''}
${body}
${footer ? `<div class="footer">${escapeHtml(footer)}</div>` : ''}
</body>
</html>`
}
