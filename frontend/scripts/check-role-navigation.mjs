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
import { isRoleScopedRouteAllowed } from '../src/services/roleNavigation.js'

const auth = (roleCode) => ({
  user: { role: { code: roleCode } },
  roleCodes: [roleCode],
  hasRole: (codes) => (Array.isArray(codes) ? codes : [codes]).includes(roleCode),
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
  ['Кабинет студента закрыт приёмной комиссии', isRoleScopedRouteAllowed(auth('admission'), cabinet), false],

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
