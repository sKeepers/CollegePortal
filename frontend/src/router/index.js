import { createRouter, createWebHistory } from 'vue-router'
import { routes } from './routes'
import { useAuthStore } from '../stores/auth'

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

  if (to.meta.adminOnly && !auth.isAdmin) {
    return { name: 'dashboard' }
  }

  const permission = to.meta.permission

  if (permission && !auth.can(permission)) {
    return { name: 'dashboard' }
  }

  return true
})
