const ROLE_ROUTE_PREFIXES = {
  admission: ['/dashboard', '/admissions/foundation'],
  student: ['/dashboard', '/schedule', '/student', '/identity/my-pass', '/m/student'],
  // `/curator/group` открыт преподавателю намеренно: куратором назначают
  // карточку преподавателя, а учётная запись при этом чаще всего с ролью
  // `teacher`. Данные раздел не открывает — их разграничивает сервер по
  // `groups.curator_id`, и человеку без групп он покажет объяснение.
  // `/semester-grades` добавлен 30.08.2026: право `journal.semester_grades` роли
  // выдано сидером, пункт меню под ним же, а пути в этом списке не было — и
  // список решает **раньше** права, в стороже маршрута и в фильтре меню.
  // Замерено: преподаватель с этим правом получал «Доступ запрещён», а `can()`
  // при этом возвращал истину. Расхождений такого рода в портале ещё девять
  // (четыре у преподавателя, шесть у приёмной комиссии); какой из двух
  // источников главный — вопрос владельцу, поэтому правится только этот путь:
  // он единственный нужен к 1 сентября и смысл его однозначен.
  teacher: ['/dashboard', '/schedule', '/journal', '/semester-grades', '/attendance', '/teaching-load', '/curator/group', '/identity/my-pass', '/m/teacher'],
}

function matchesPrefix(path, prefix) {
  const pathname = String(path).split(/[?#]/, 1)[0]
  return pathname === prefix || pathname.startsWith(`${prefix}/`)
}

export function primaryRoleCode(auth) {
  return auth.user?.role?.code || auth.roleCodes?.[0] || ''
}

/**
 * Объявляет ли маршрут хоть какое-то требование доступа.
 *
 * Роль-зависимые разделы объявляют право, роль или признак «только администратору» —
 * их закрывает проверка ниже по цепочке и, главное, сервер. Раздел, не объявивший
 * ничего, открыт любому вошедшему по замыслу.
 */
function declaresAccessRequirement(route) {
  const meta = route?.meta
  if (!meta) return false

  return Boolean(
    meta.permission
    || meta.adminOnly
    || meta.roles
    || (meta.permissionsAny || meta.permissions)?.length
    || meta.permissionsAll?.length,
  )
}

/**
 * @param {object|string} target маршрут (предпочтительно) или его путь
 */
export function isRoleScopedRouteAllowed(auth, target) {
  const path = typeof target === 'string' ? target : target?.path

  if (!path || auth.hasRole?.('admin') || auth.hasRole?.('security')) return true

  // Раздел без объявленного требования доступа открыт любому вошедшему — ровно как
  // на сервере после `ARCH-001`. Таблица префиксов не вправе его закрывать.
  //
  // Именно здесь «Моя учётная запись» отвечала `403` студенту, преподавателю и
  // приёмной комиссии: права у неё нет ни на сервере, ни у маршрута, но `/account`
  // не попал ни в один список префиксов. Дописать его четвёртой строкой значило бы
  // ждать того же от каждого следующего общего раздела — поэтому правило, а не строка.
  if (typeof target === 'object' && !declaresAccessRequirement(target)) return true

  const prefixes = ROLE_ROUTE_PREFIXES[primaryRoleCode(auth)]
  if (!prefixes) return true

  return prefixes.some((prefix) => matchesPrefix(path, prefix))
}
