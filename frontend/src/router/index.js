import { createRouter, createWebHistory } from 'vue-router'
import { routes } from './routes'
import { useAuthStore } from '../stores/auth'
import { isRoleScopedRouteAllowed, primaryRoleCode } from '../services/roleNavigation'
import { layoutService } from '../services/layoutService'

export const router = createRouter({
  history: createWebHistory(),
  routes,
})

// Кончившийся сеанс уводит на вход сразу, а не на следующем переходе: иначе
// человек остаётся на экране, где каждая кнопка молча отказывает.
window.addEventListener('cp:session-expired', () => {
  if (router.currentRoute.value.name !== 'login') {
    router.push({ name: 'login' })
  }
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

  if (primaryRoleCode(auth) === 'student' && layoutService.isMobile.value && to.path === '/dashboard') {
    return { path: '/m/student' }
  }

  if (primaryRoleCode(auth) === 'student' && to.path === '/journal') {
    return { path: '/m/student', hash: '#journal' }
  }

  if (primaryRoleCode(auth) === 'student' && layoutService.isMobile.value && to.path === '/schedule') {
    return { path: '/m/student', hash: '#schedule' }
  }

  // `?full=1` — просьба человека открыть обычный портал на телефоне. Ссылка
  // «Полная версия» есть в каждом мобильном кабинете, и без этой проверки она
  // возвращала бы туда, откуда нажата.
  const wantsFullVersion = to.query.full !== undefined

  // Преподаватель и куратор на телефоне попадают в свой кабинет, а не в
  // рабочий стол и не в журнал с таблицами: отметка посещаемости делается стоя
  // в аудитории, одной рукой. На большом экране обе страницы остаются прежними.
  //
  // У куратора кабинета два, и рабочий стол ведёт в тот, что про группу:
  // журнал занятия он откроет из кабинета преподавателя, а вот «кто сегодня не
  // пришёл» смотрят с телефона именно про группу.
  if (layoutService.isMobile.value && !wantsFullVersion && auth.hasRole('curator') && to.path === '/dashboard') {
    return { path: '/m/curator' }
  }

  if (layoutService.isMobile.value && !wantsFullVersion && auth.hasRole(['teacher', 'curator']) && ['/dashboard', '/journal'].includes(to.path)) {
    return { path: '/m/teacher' }
  }

  // Администратор с телефона чаще всего заходит не «поработать», а посмотреть
  // сводку и решить по заявке. Сканер проходной остаётся отдельным переходом
  // ниже — он про другой сценарий и другую роль.
  if (layoutService.isMobile.value && !wantsFullVersion && auth.hasRole('admin') && to.path === '/dashboard') {
    return { path: '/m/admin' }
  }

  if (layoutService.isMobile.value && auth.hasRole(['admin', 'security']) && to.path === '/access/gate') {
    return { path: '/access/mobile-scanner' }
  }

  // Передаём маршрут целиком, а не путь: по его `meta` видно, объявляет ли раздел
  // требование доступа. Без этого общий раздел вроде «Моей учётной записи» закрывался
  // таблицей префиксов, хотя права у него нет ни здесь, ни на сервере.
  if (!isRoleScopedRouteAllowed(auth, to)) {
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
