const ROLE_ROUTE_PREFIXES = {
  admission: ['/dashboard', '/admissions/foundation'],
  student: ['/dashboard', '/schedule', '/student', '/identity/my-pass', '/m/student'],
  teacher: ['/dashboard', '/schedule', '/journal', '/attendance', '/teaching-load', '/identity/my-pass', '/m/teacher'],
}

function matchesPrefix(path, prefix) {
  const pathname = String(path).split(/[?#]/, 1)[0]
  return pathname === prefix || pathname.startsWith(`${prefix}/`)
}

export function primaryRoleCode(auth) {
  return auth.user?.role?.code || auth.roleCodes?.[0] || ''
}

export function isRoleScopedRouteAllowed(auth, path) {
  if (!path || auth.hasRole?.('admin') || auth.hasRole?.('security')) return true

  const prefixes = ROLE_ROUTE_PREFIXES[primaryRoleCode(auth)]
  if (!prefixes) return true

  return prefixes.some((prefix) => matchesPrefix(path, prefix))
}
