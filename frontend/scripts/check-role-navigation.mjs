/**
 * Проверка правила доступа к разделам на стороне браузера.
 *
 * Зачем отдельный скрипт: набора тестов у фронтенда нет вовсе, а обход прав по API
 * (`scripts/deploy/check-role-access.sh`) этот дефект поймать не мог — сервер отвечал
 * `200`, отказ выдавал маршрутизатор в браузере. Проверка запускается без единой
 * зависимости:
 *
 *   node frontend/scripts/check-role-navigation.mjs
 */
import { canOpenRoute, isRoleScopedRouteAllowed } from '../src/services/roleNavigation.js'

const auth = (roleCode, permissions = []) => ({
  user: { role: { code: roleCode } },
  roleCodes: [roleCode],
  isAdmin: roleCode === 'admin',
  hasRole: (codes) => (Array.isArray(codes) ? codes : [codes]).includes(roleCode),
  can: (code) => permissions.includes(code),
})

const account = { path: '/account', meta: { title: 'Моя учётная запись' } }
const journal = { path: '/journal', meta: { title: 'Журнал', permission: 'journal.view' } }
const cabinet = { path: '/student', meta: { title: 'Успеваемость', roles: ['student'], permission: 'mobile.student.view' } }

const cases = [
  // То, из-за чего задача заводилась: раздел без объявленного права обязан открываться
  // каждому вошедшему, какая бы у него ни была роль.
  ['«Моя учётная запись» открыта студенту', isRoleScopedRouteAllowed(auth('student'), account), true],
  ['«Моя учётная запись» открыта преподавателю', isRoleScopedRouteAllowed(auth('teacher'), account), true],
  ['«Моя учётная запись» открыта приёмной комиссии', isRoleScopedRouteAllowed(auth('admission'), account), true],
  ['«Моя учётная запись» открыта директору', isRoleScopedRouteAllowed(auth('director'), account), true],

  // И обратное: правило не должно открыть роль-зависимые разделы.
  ['Журнал закрыт студенту', isRoleScopedRouteAllowed(auth('student'), journal), false],
  ['Журнал открыт преподавателю', isRoleScopedRouteAllowed(auth('teacher'), journal), true],
  // Кабинет студента спрашивается **решением целиком**, а не слоем списка путей.
  //
  // До 04.09.2026 здесь стояло `isRoleScopedRouteAllowed(...) === false`. Строка
  // закодировала механизм вместо предмета: кабинет закрывает не список путей, а
  // `roles: ['student']` у маршрута. Пока у приёмной комиссии был свой список,
  // оба ответа совпадали; список убрали по решению владельца («что разрешено, то
  // и показывается») — и строка начала требовать его возврата, то есть отмены
  // решения руками сторожа.
  //
  // Что это было не утечкой, замерено дважды и разными способами: та же первая
  // заслонка отвечала «не мешаю» и для `director`, `hr`, `study`,
  // `academic_office`, `curator`, `commandant`, `employee` — семи ролей, у
  // которых списка не было никогда; и поведением на стенде — роль `admission` на
  // `/student` попадает на `/forbidden`, кабинета в её меню нет.
  //
  // Две строки ниже — про роли без списка — как раз то, чего прежнее
  // утверждение не проверяло вовсе.
  ['Кабинет студента закрыт приёмной комиссии', canOpenRoute(auth('admission'), cabinet), false],
  ['Кабинет студента закрыт директору', canOpenRoute(auth('director'), cabinet), false],
  ['Кабинет студента закрыт кадрам', canOpenRoute(auth('hr'), cabinet), false],
  ['Кабинет студента закрыт студенту без права', canOpenRoute(auth('student'), cabinet), false],
  ['Кабинет студента открыт студенту с правом', canOpenRoute(auth('student', ['mobile.student.view']), cabinet), true],

  // Эти две строки разделяют **две разные заслонки**, и без такого разделения
  // сторож зелен на внесённом дефекте. Проверено 04.09.2026: если убрать из
  // `canOpenRoute` проверку `meta.roles`, все случаи выше остаются зелёными —
  // роли `director` и `hr` не имеют и права `mobile.student.view`, поэтому их
  // всё равно закрывает проверка права, а не роль. Заслонку видно только там,
  // где право есть, а роли нет, и наоборот.
  ['Кабинет закрыт по роли: право есть, роль не та', canOpenRoute(auth('hr', ['mobile.student.view']), cabinet), false],
  ['Кабинет закрыт по праву: роль та, права нет', canOpenRoute(auth('student', []), cabinet), false],
  ['Журнал закрыт преподавателю без права', canOpenRoute(auth('teacher'), journal), false],
  ['Журнал открыт преподавателю с правом', canOpenRoute(auth('teacher', ['journal.view']), journal), true],

  // Совместимость: вызов с одним путём по-прежнему работает по таблице префиксов.
  ['Путь строкой разбирается как раньше', isRoleScopedRouteAllowed(auth('student'), '/journal'), false],
]

let failed = 0

for (const [name, actual, expected] of cases) {
  if (actual === expected) {
    console.log(`  ок — ${name}`)
  } else {
    console.error(`  СБОЙ — ${name}: ожидалось ${expected}, получено ${actual}`)
    failed++
  }
}

if (failed > 0) {
  console.error(`Расхождений: ${failed}`)
  process.exit(1)
}

console.log('Правила доступа к разделам в порядке.')
