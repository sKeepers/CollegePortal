import { createRouter, createWebHistory } from 'vue-router'
import { routes } from './routes'
import { useAuthStore } from '../stores/auth'
import { isRoleScopedRouteAllowed, primaryRoleCode } from '../services/roleNavigation'

export const router = createRouter({
  history: createWebHistory(),
  routes,
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()

  if (!auth.initialized) {
    await auth.restore()
  }

  if (to.meta.public) {
    if (to.name === 'login' && auth.isAuthenticated) {
      return { name: 'dashboard' }
    }

    return true
  }

  if (!auth.isAuthenticated) {
    return { name: 'login' }
  }

  if (to.name === 'forbidden') {
    return true
  }

  if (primaryRoleCode(auth) === 'student' && to.path === '/schedule') {
    return { path: '/m/student', hash: '#schedule' }
  }

  if (!isRoleScopedRouteAllowed(auth, to.path)) {
    return { name: 'forbidden' }
  }

  if (to.meta.adminOnly && !auth.isAdmin) {
    return { name: 'forbidden' }
  }

  if (to.meta.roles && !auth.hasRole(to.meta.roles)) {
    return { name: 'forbidden' }
  }

  const permission = to.meta.permission
  const permissionsAny = to.meta.permissionsAny || to.meta.permissions
  const permissionsAll = to.meta.permissionsAll

  if (permission && !auth.can(permission)) {
    return { name: 'forbidden' }
  }

  if (Array.isArray(permissionsAny) && permissionsAny.length && !permissionsAny.some((code) => auth.can(code))) {
    return { name: 'forbidden' }
  }

  if (Array.isArray(permissionsAll) && permissionsAll.length && !permissionsAll.every((code) => auth.can(code))) {
    return { name: 'forbidden' }
  }

  return true
})
