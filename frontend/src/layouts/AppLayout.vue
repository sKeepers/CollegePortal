<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useQuasar } from 'quasar'
import { useRoute, useRouter } from 'vue-router'
import {
  BedDouble,
  BookOpen,
  BriefcaseBusiness,
  Building2,
  CalendarDays,
  ChevronDown,
  ChevronRight,
  ClipboardList,
  CreditCard,
  Database,
  DoorOpen,
  FileSpreadsheet,
  FileSearch,
  FileText,
  Gauge,
  GraduationCap,
  HeartHandshake,
  KeyRound,
  LogOut,
  Menu,
  QrCode,
  ScrollText,
  Settings,
  Tags,
  ShieldCheck,
  UserCog,
  UserRound,
  UsersRound,
  MessageSquareWarning,
  Bell,
  Trash2,
} from '@lucide/vue'
import { useAuthStore } from '../stores/auth'
import { useWorkspaceStore } from '../stores/workspace'
import { useSettingsStore } from '../stores/settings'
import { useLayoutService } from '../services/layoutService'
import { getEnvironmentCssVars } from '../services/environmentService'
import { isRoleScopedRouteAllowed } from '../services/roleNavigation'
import { canReceiveAdminInbox, loadAdminInbox } from '../services/adminInbox'
import { api } from '../services/api'
import GlobalSearch from '../components/search/GlobalSearch.vue'
import EnvironmentBadge from '../components/system/EnvironmentBadge.vue'
import SystemInfoPanel from '../components/system/SystemInfoPanel.vue'
import UatFeedbackDialog from '../components/uat/UatFeedbackDialog.vue'

const router = useRouter()
const route = useRoute()
const $q = useQuasar()
const auth = useAuthStore()
const workspace = useWorkspaceStore()
const settingsStore = useSettingsStore()
useLayoutService()
const drawerOpen = ref(true)
const feedbackOpen = ref(false)
const adminNotifications = ref([])
const notificationInitialized = ref(false)
let notificationTimer = null
const NAVIGATION_SECTIONS_KEY = 'collegePortal.navigation.sections.v1'

const navGroups = [
  {
    label: 'Главное',
    items: [
      { label: 'Панель', to: '/dashboard', icon: Gauge },
    ],
  },
  {
    label: 'Контингент',
    items: [
      { label: 'Люди', to: '/people', icon: UserRound, permission: 'people.view' },
      { label: 'Студенты', to: '/students', icon: GraduationCap, permission: 'students.view' },
      { label: 'Группы', to: '/groups', icon: UsersRound, permission: 'groups.view' },
    ],
  },
  {
    label: 'Учебный процесс',
    items: [
      { label: 'Расписание', to: '/schedule', icon: CalendarDays, permission: 'schedule.view' },
      { label: 'Успеваемость', to: '/student', icon: ClipboardList, roles: ['student'], permission: 'mobile.student.view' },
      { label: 'Журнал', to: '/journal', icon: ClipboardList, permission: 'journal.view' },
      // Раздел куратора: успеваемость и состав своей группы. Роль здесь не
      // спрашивается намеренно — куратором назначают карточку преподавателя, а
      // учётная запись при этом может быть с ролью `teacher`, и по роли пункт
      // до такого куратора не доходил. Кому групп не досталось, увидит
      // объяснение, а не пустую таблицу.
      { label: 'Моя группа', to: '/curator/group', icon: UsersRound, permission: 'journal.view' },
      { label: 'Посещаемость', to: '/attendance', icon: ClipboardList, permission: 'attendance.reports' },
      { label: 'Учебные планы', to: '/curricula', icon: BookOpen, permission: 'curricula.view' },
      // Своя нагрузка открывается отдельным правом, а не общим «видеть своё»:
      // то есть почти у всех, и раздел показывался охраннику с комендантом.
      { label: 'Нагрузка', to: '/teaching-load', icon: ClipboardList, permissionsAny: ['teachingload.view', 'teachingload.view_own'] },
      { label: 'Экзамены и ГИА', to: '/exams', icon: ClipboardList, permission: 'exams.view' },
      { label: 'Выпускники и дипломы', to: '/graduation', icon: GraduationCap, permission: 'graduation.view' },
      { label: 'ФРДО', to: '/frdo', icon: FileText, permission: 'frdo.view' },
      { label: 'ФИС', to: '/fis', icon: FileText, permission: 'fis.view' },
    ],
  },
  {
    label: 'Справочники',
    items: [
      // Те же права, что у маршрутов: раздел нужен и правящим справочники, и
      // учебным частям, которым важны срок обучения, форма и квалификация.
      { label: 'Специальности', to: '/specialties', icon: GraduationCap, permissionsAny: ['reference.manage', 'reference.programs.view'] },
      { label: 'Образовательные программы', to: '/education-programs', icon: BookOpen, permissionsAny: ['reference.manage', 'reference.programs.view'] },
      { label: 'Преподаватели', to: '/teachers', icon: UserRound, permission: 'teachers.view' },
      { label: 'Дисциплины', to: '/subjects', icon: BookOpen, permission: 'subjects.view' },
      { label: 'Аудитории', to: '/classrooms', icon: Building2, permission: 'classrooms.view' },
    ],
  },
  {
    label: 'Прием и отчеты',
    items: [
      { label: 'Отчеты', to: '/reports', icon: FileText, permission: 'journal.view' },
      { label: 'Приёмная комиссия', to: '/admissions/foundation', icon: FileSearch, permission: 'admissions.application.view' },
    ],
  },
  {
    label: 'Отдел кадров',
    items: [
      { label: 'Сотрудники', to: '/hr/employees', icon: BriefcaseBusiness, permission: 'hr.employees.view' },
      { label: 'Календарь', to: '/hr/calendar', icon: CalendarDays, permission: 'hr.calendar.view' },
      { label: 'Подразделения', to: '/hr/departments', icon: Building2, permission: 'hr.departments.manage' },
      { label: 'Должности', to: '/hr/positions', icon: UserCog, permission: 'hr.positions.manage' },
    ],
  },
  {
    label: 'Идентификация',
    items: [
      // Списка ролей здесь нет намеренно. Пропуск принадлежит человеку, а не
      // должности: через ту же проходную ходят и директор, и администратор, и
      // учебная часть. Пока роли были перечислены, свой пропуск видели четыре
      // роли из тринадцати, а у остальных раздел просто отсутствовал — при том
      // что пропуск им выдан и работает. Право `view_own_data` и есть нужная
      // проверка: «показывать человеку его собственное».
      { label: 'Мой QR-пропуск', to: '/identity/my-pass', icon: QrCode, permissionsAny: ['mobile.student.pass', 'view_own_data'] },
      { label: 'RFID-карты', to: '/identity/rfid-cards', icon: CreditCard, permission: 'rfid.cards.view' },
      // Общежитие стоит здесь, а не отдельной группой: для коменданта это
      // продолжение той же работы — кто где живёт и кто вошёл в дверь.
      { label: 'Общежитие', to: '/dorm', icon: BedDouble, permission: 'dorm.rooms.view' },
      // Второй контур общежития отдельным пунктом: у него своё право, и
      // коменданту он не показывается вовсе.
      { label: 'Воспитательная работа', to: '/dorm/upbringing', icon: HeartHandshake, permission: 'dorm.conduct.view' },
      { label: 'Проходная', to: '/access/gate', icon: DoorOpen, roles: ['admin', 'security'], permission: 'gate.scan' },
      { label: 'Мобильный сканер', to: '/access/mobile-scanner', icon: QrCode, roles: ['admin', 'security'], permission: 'gate.scan' },
      { label: 'Кто сейчас в здании', to: '/access/muster', icon: UsersRound, roles: ['admin', 'security', 'hr'], permission: 'gate.reports' },
      { label: 'Отчеты по проходам', to: '/access/reports', icon: FileText, roles: ['admin', 'security', 'hr'], permission: 'gate.reports' },
      { label: 'Корпуса и точки прохода', to: '/access/points', icon: Building2, roles: ['admin', 'security'], permission: 'gate.points.manage' },
      { label: 'Тест QR-сканера', to: '/access/scanner-test', icon: QrCode, adminOnly: true },
      { label: 'Цифровые пропуска', to: '/identity/digital-passes', icon: QrCode, roles: ['admin', 'security'], permission: 'digitalpasses.manage' },
    ],
  },
  {
    label: 'Система',
    items: [
      { label: 'Пользователи', to: '/admin/users', icon: UserCog, permission: 'users.manage' },
      { label: 'Роли', to: '/admin/roles', icon: ShieldCheck, permission: 'roles.manage' },
      { label: 'Разрешения', to: '/admin/permissions', icon: KeyRound, permission: 'permissions.manage' },
      { label: 'Аудит', to: '/admin/audit', icon: ScrollText, permission: 'audit.view' },
      { label: 'Настройки колледжа', to: '/admin/settings', icon: Settings, permission: 'settings.manage' },
      { label: 'Справочники', to: '/admin/reference', icon: Tags, permission: 'reference.manage' },
      { label: 'Импорт данных', to: '/admin/import', icon: FileSpreadsheet, permission: 'import.manage' },
      { label: 'Управление данными', to: '/admin/data-management', icon: Database, permission: 'demo_data.manage' },
      { label: 'Корзина', to: '/admin/trash', icon: Trash2, permission: 'trash.manage' },
      { label: 'UAT', to: '/admin/uat', icon: MessageSquareWarning, permission: 'uat.manage' },
      { label: 'Библиотека интерфейса', to: '/system/ui-foundation', icon: Settings, adminOnly: true },
    ],
  },
]

const visibleNavGroups = computed(() =>
  navGroups
    .map((group) => ({
      ...group,
      items: group.items.filter((item) => {
        if (item.adminOnly && !auth.isAdmin) {
          return false
        }

        if (!isRoleScopedRouteAllowed(auth, typeof item.to === 'string' ? item.to : item.to?.path)) {
          return false
        }

        if (item.roles && !auth.hasRole(item.roles)) {
          return false
        }

        if (item.permissionsAny?.length && !item.permissionsAny.some((permission) => auth.can(permission))) {
          return false
        }

        return !item.permission || auth.can(item.permission)
      }),
    }))
    .filter((group) => group.items.length > 0),
)

const collapsedSections = ref(loadNavigationSections())
const pageTitle = computed(() => route.meta.title || 'CollegePortal')
// Кому вообще показывать колокольчик — решает тот же модуль, что собирает
// список: иначе значок и его содержимое расходятся.
const canReceiveAdminNotifications = computed(() => canReceiveAdminInbox(auth))
const unreadNotificationCount = computed(() => adminNotifications.value.length)
const collegeShortName = computed(() => settingsStore.publicValue('general', 'college_short_name', 'Колледж искусств'))
const logoPath = computed(() => settingsStore.publicValue('branding', 'logo_path', '/brand/logo-skki-bw.jpg'))
const layoutStyle = computed(() => ({
  '--cp-sidebar-width': `${workspace.sidebarWidth}px`,
  '--cp-viewport-width': `${workspace.viewportWidth}px`,
  '--cp-viewport-height': `${workspace.viewportHeight}px`,
  ...getEnvironmentCssVars(),
}))

/**
 * Пункт меню горит и когда открыта карточка раздела: у неё свой адрес вида
 * `/students/955` (решение владельца 22.08.2026), а сверка точным равенством
 * гасила подсветку, стоило выбрать строку.
 *
 * Сверяем не по началу строки, а по идентификатору из маршрута: иначе
 * `/admissions/foundation` подсвечивал бы заодно и «Приёмную комиссию».
 */
function isSectionActive(to) {
  if (route.path === to) {
    return true
  }

  return Boolean(route.params.id) && route.path === `${to}/${route.params.id}`
}

function navGroupKey(group) {
  return String(group.label || '')
    .toLowerCase()
    .replaceAll('ё', 'е')
    .replace(/[^a-zа-я0-9]+/giu, '-')
    .replace(/^-|-$/g, '')
}

function canUseLocalStorage() {
  try {
    return typeof window !== 'undefined' && Boolean(window.localStorage)
  } catch {
    return false
  }
}

function loadNavigationSections() {
  if (!canUseLocalStorage()) {
    return {}
  }

  try {
    const parsed = JSON.parse(window.localStorage.getItem(NAVIGATION_SECTIONS_KEY) || '{}')
    return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {}
  } catch {
    return {}
  }
}

function saveNavigationSections() {
  if (!canUseLocalStorage()) {
    return
  }

  window.localStorage.setItem(NAVIGATION_SECTIONS_KEY, JSON.stringify(collapsedSections.value))
}

function isGroupCollapsed(group) {
  if (workspace.settings.menuCollapsed && !workspace.isMobile) {
    return false
  }

  return Boolean(collapsedSections.value[navGroupKey(group)])
}

function toggleNavGroup(group) {
  const key = navGroupKey(group)
  collapsedSections.value = {
    ...collapsedSections.value,
    [key]: !collapsedSections.value[key],
  }
  saveNavigationSections()
}

async function logout() {
  await auth.logout()
  router.push('/login')
}

async function loadAdminNotifications() {
  if (!canReceiveAdminNotifications.value) {
    adminNotifications.value = []
    return
  }

  // Сборка списка вынесена в services/adminInbox.js: тот же список показывает
  // мобильный кабинет администратора, и написанный дважды он бы разъехался.
  // Здесь остаётся то, что есть только на десктопе, — всплывающие уведомления
  // о новых записях.
  const next = await loadAdminInbox(auth)
  const known = new Set(adminNotifications.value.map((item) => item.id))
  adminNotifications.value = next
  next.filter((item) => !known.has(item.id)).forEach((item) => {
    $q.notify({ type: 'info', message: item.title, caption: item.description, position: 'top-right', actions: [{ label: 'Открыть', color: 'white', handler: () => router.push(item.to) }] })
  })
  notificationInitialized.value = true
}

onMounted(() => {
  settingsStore.loadPublic().catch(() => {})
  notificationTimer = window.setInterval(() => {
    if (!document.hidden) loadAdminNotifications().catch(() => {})
  }, 30000)
})

watch(
  () => auth.user?.id,
  () => {
    notificationInitialized.value = false
    loadAdminNotifications().catch(() => {})
  },
  { immediate: true },
)

onBeforeUnmount(() => {
  if (notificationTimer) window.clearInterval(notificationTimer)
})

watch(
  () => workspace.isMobile,
  (isMobile) => {
    drawerOpen.value = !isMobile
  },
  { immediate: true },
)
</script>

<template>
  <q-layout
    view="hHh Lpr lFf"
    :class="['cp-app-layout', workspace.workspaceClass, { 'cp-app-layout--scanner': route.path === '/access/mobile-scanner' }]"
    :style="layoutStyle"
  >
    <q-header bordered class="bg-white text-dark">
      <q-toolbar class="cp-topbar">
        <q-btn
          flat
          round
          dense
          aria-label="Открыть меню"
          @click="workspace.isMobile ? drawerOpen = !drawerOpen : workspace.toggleMenuCollapsed()"
        >
          <Menu :size="20" />
        </q-btn>

        <q-toolbar-title>
          <div class="cp-page-title">{{ pageTitle }}</div>
          <div class="cp-page-subtitle">{{ collegeShortName }}</div>
        </q-toolbar-title>

        <div class="cp-topbar-tools">
          <q-btn flat no-caps color="primary" @click="feedbackOpen = true">
            <MessageSquareWarning :size="16" />
            <span>Сообщить о проблеме</span>
          </q-btn>
          <q-btn v-if="canReceiveAdminNotifications" flat round dense aria-label="Уведомления администратора">
            <q-badge v-if="unreadNotificationCount" floating color="negative">{{ unreadNotificationCount }}</q-badge>
            <Bell :size="18" />
            <q-menu anchor="bottom middle" self="top middle" style="min-width: 320px">
              <q-list separator>
                <q-item-label header>Уведомления администратора</q-item-label>
                <q-item v-for="item in adminNotifications" :key="item.id" clickable v-close-popup @click="router.push(item.to)">
                  <q-item-section><q-item-label>{{ item.title }}</q-item-label><q-item-label caption>{{ item.description }}</q-item-label></q-item-section>
                </q-item>
                <q-item v-if="!adminNotifications.length"><q-item-section><q-item-label caption>Новых сообщений нет</q-item-label></q-item-section></q-item>
              </q-list>
            </q-menu>
          </q-btn>
          <EnvironmentBadge />
          <GlobalSearch />
        </div>

        <q-chip v-if="auth.loading" color="blue-1" text-color="primary" dense>
          Загрузка данных...
        </q-chip>

        <q-btn-dropdown flat no-caps class="cp-user-menu">
          <template #label>
            <div class="cp-user-label">
              <span>{{ auth.user?.name || 'Пользователь' }}</span>
              <small>{{ auth.user?.role?.name || 'Роль не указана' }}</small>
            </div>
          </template>
          <q-list dense>
            <!-- Своя учётная запись доступна любой роли, поэтому живёт здесь,
                 а не в боковом меню под правом. -->
            <q-item clickable v-close-popup to="/account">
              <q-item-section avatar>
                <UserRound :size="18" />
              </q-item-section>
              <q-item-section>Моя учётная запись</q-item-section>
            </q-item>
            <q-separator />
            <q-item clickable v-close-popup @click="logout">
              <q-item-section avatar>
                <LogOut :size="18" />
              </q-item-section>
              <q-item-section>Выйти</q-item-section>
            </q-item>
          </q-list>
        </q-btn-dropdown>
      </q-toolbar>
    </q-header>

    <q-drawer
      v-model="drawerOpen"
      show-if-above
      bordered
      :width="workspace.sidebarWidth || workspace.settings.sidebarWidth"
      :mini="workspace.settings.menuCollapsed && !workspace.isMobile"
      :mini-width="78"
      class="cp-sidebar"
    >
      <div class="cp-brand">
        <div class="cp-brand-mark">
          <img :src="logoPath" alt="" aria-hidden="true" />
        </div>
        <div>
          <strong>CollegePortal</strong>
          <span>{{ collegeShortName }}</span>
        </div>
      </div>

      <div class="cp-sidebar-scroll">
        <q-list class="cp-nav-list">
          <template v-for="group in visibleNavGroups" :key="group.label">
            <q-item-label header class="cp-nav-section-label">
              <button
                type="button"
                class="cp-nav-section-toggle"
                :aria-expanded="!isGroupCollapsed(group)"
                :aria-controls="`cp-nav-section-${navGroupKey(group)}`"
                @click="toggleNavGroup(group)"
                @keydown.enter.prevent="toggleNavGroup(group)"
                @keydown.space.prevent="toggleNavGroup(group)"
              >
                <span>{{ group.label }}</span>
                <ChevronRight v-if="isGroupCollapsed(group)" :size="15" aria-hidden="true" />
                <ChevronDown v-else :size="15" aria-hidden="true" />
              </button>
            </q-item-label>
            <div
              v-show="!isGroupCollapsed(group)"
              :id="`cp-nav-section-${navGroupKey(group)}`"
              class="cp-nav-section-items"
            >
              <q-item
                v-for="item in group.items"
                :key="item.to"
                clickable
                :active="isSectionActive(item.to)"
                active-class="cp-nav-active"
                :to="item.to"
              >
                <q-item-section avatar>
                  <component :is="item.icon" :size="19" />
                </q-item-section>
                <q-item-section>{{ item.label }}</q-item-section>
                <q-item-section v-if="item.badge && !workspace.settings.menuCollapsed" side>
                  <q-badge color="blue-1" text-color="primary">{{ item.badge }}</q-badge>
                </q-item-section>
              </q-item>
            </div>
          </template>
        </q-list>
      </div>

      <SystemInfoPanel />
    </q-drawer>

    <UatFeedbackDialog v-model="feedbackOpen" />

    <q-page-container>
      <q-page class="cp-page">
        <RouterView />
      </q-page>
    </q-page-container>
  </q-layout>
</template>
