<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  BookOpen,
  BriefcaseBusiness,
  Building2,
  CalendarDays,
  ClipboardList,
  Database,
  DoorOpen,
  FileSpreadsheet,
  FileSearch,
  FileText,
  Gauge,
  GraduationCap,
  KeyRound,
  LogOut,
  Menu,
  QrCode,
  ScrollText,
  Settings,
  Tags,
  School,
  ShieldCheck,
  UserCog,
  UserRound,
  UsersRound,
  MessageSquareWarning,
} from '@lucide/vue'
import { useAuthStore } from '../stores/auth'
import { useWorkspaceStore } from '../stores/workspace'
import { useSettingsStore } from '../stores/settings'
import { useLayoutService } from '../services/layoutService'
import { getEnvironmentCssVars } from '../services/environmentService'
import GlobalSearch from '../components/search/GlobalSearch.vue'
import EnvironmentBadge from '../components/system/EnvironmentBadge.vue'
import SystemInfoPanel from '../components/system/SystemInfoPanel.vue'
import UatFeedbackDialog from '../components/uat/UatFeedbackDialog.vue'

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()
const workspace = useWorkspaceStore()
const settingsStore = useSettingsStore()
useLayoutService()
const drawerOpen = ref(true)
const feedbackOpen = ref(false)

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
      { label: 'Люди / Person', to: '/people', icon: UserRound, permission: 'people.view' },
      { label: 'Студенты', to: '/students', icon: GraduationCap, permission: 'students.view' },
      { label: 'Группы', to: '/groups', icon: UsersRound, permission: 'groups.view' },
    ],
  },
  {
    label: 'Учебный процесс',
    items: [
      { label: 'Расписание', to: '/schedule', icon: CalendarDays, permission: 'schedule.view' },
      { label: 'Журнал', to: '/journal', icon: ClipboardList, permission: 'journal.view' },
      { label: 'Посещаемость', to: '/attendance', icon: ClipboardList, permission: 'attendance.reports' },
      { label: 'Учебные планы', to: '/curricula', icon: BookOpen, permission: 'curricula.view' },
      { label: 'Нагрузка', to: '/teaching-load', icon: ClipboardList, permission: 'teachingload.view' },
      { label: 'Экзамены и ГИА', to: '/exams', icon: ClipboardList, permission: 'exams.view' },
      { label: 'Выпускники и дипломы', to: '/graduation', icon: GraduationCap, permission: 'graduation.view' },
      { label: 'ФРДО', to: '/frdo', icon: FileText, permission: 'frdo.view' },
      { label: 'ФИС', to: '/fis', icon: FileText, permission: 'fis.view' },
    ],
  },
  {
    label: 'Справочники',
    items: [
      { label: 'Преподаватели', to: '/teachers', icon: UserRound, permission: 'teachers.view' },
      { label: 'Дисциплины', to: '/subjects', icon: BookOpen, permission: 'subjects.view' },
      { label: 'Аудитории', to: '/classrooms', icon: Building2, permission: 'classrooms.view' },
    ],
  },
  {
    label: 'Прием и отчеты',
    items: [
      { label: 'Отчеты', to: '/reports', icon: FileText, permission: 'journal.view' },
      { label: 'Приемная комиссия', to: '/admissions', icon: School, permission: 'admissions.view' },
      { label: 'Приёмная комиссия', to: '/admissions/foundation', icon: FileSearch, permission: 'admissions.application.view', badge: 'Foundation' },
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
      { label: 'Проходная', to: '/access/gate', icon: DoorOpen, permission: 'gate.scan' },
      { label: 'Мобильный сканер', to: '/access/mobile-scanner', icon: QrCode, permission: 'gate.scan' },
      { label: 'Отчеты по проходам', to: '/access/reports', icon: FileText, permission: 'gate.reports' },
      { label: 'Тест QR-сканера', to: '/access/scanner-test', icon: QrCode, adminOnly: true },
      { label: 'Цифровые пропуска', to: '/identity/digital-passes', icon: QrCode, permission: 'digitalpasses.manage' },
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
      { label: 'Управление данными', to: '/admin/data-management', icon: Database, permission: 'import.manage' },
      { label: 'UAT', to: '/admin/uat', icon: MessageSquareWarning, permission: 'uat.manage' },
      { label: 'UI Foundation', to: '/system/ui-foundation', icon: Settings, adminOnly: true },
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

        if (item.roles && !auth.hasRole(item.roles)) {
          return false
        }

        return !item.permission || auth.can(item.permission)
      }),
    }))
    .filter((group) => group.items.length > 0),
)

const pageTitle = computed(() => route.meta.title || 'CollegePortal')
const collegeShortName = computed(() => settingsStore.publicValue('general', 'college_short_name', 'Колледж искусств'))
const collegeFullName = computed(() => settingsStore.publicValue('general', 'college_full_name', 'Рабочее место колледжа'))
const logoPath = computed(() => settingsStore.publicValue('branding', 'logo_path', '/brand/logo-skki-bw.jpg'))
const layoutStyle = computed(() => ({
  '--cp-sidebar-width': `${workspace.sidebarWidth}px`,
  '--cp-viewport-width': `${workspace.viewportWidth}px`,
  '--cp-viewport-height': `${workspace.viewportHeight}px`,
  ...getEnvironmentCssVars(),
}))

async function logout() {
  await auth.logout()
  router.push('/login')
}

onMounted(() => {
  settingsStore.loadPublic().catch(() => {})
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
    :class="['cp-app-layout', workspace.workspaceClass]"
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
          <div class="cp-page-subtitle">{{ collegeFullName }}</div>
        </q-toolbar-title>

        <div class="cp-topbar-tools">
          <q-btn flat no-caps color="primary" @click="feedbackOpen = true">
            <MessageSquareWarning :size="16" />
            <span>Сообщить о проблеме</span>
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

      <q-scroll-area class="cp-sidebar-scroll">
        <q-list class="cp-nav-list">
          <template v-for="group in visibleNavGroups" :key="group.label">
            <q-item-label header>{{ group.label }}</q-item-label>
            <q-item
              v-for="item in group.items"
              :key="item.to"
              clickable
              :active="route.path === item.to"
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
          </template>
        </q-list>
      </q-scroll-area>

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
