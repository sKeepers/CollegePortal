<script setup>
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  BookOpen,
  Building2,
  CalendarDays,
  ClipboardList,
  DoorOpen,
  FileText,
  Gauge,
  GraduationCap,
  LogOut,
  Menu,
  QrCode,
  Settings,
  School,
  UserRound,
  UsersRound,
} from '@lucide/vue'
import { useAuthStore } from '../stores/auth'
import { useWorkspaceStore } from '../stores/workspace'
import { useLayoutService } from '../services/layoutService'
import { getEnvironmentCssVars } from '../services/environmentService'
import GlobalSearch from '../components/search/GlobalSearch.vue'
import EnvironmentBadge from '../components/system/EnvironmentBadge.vue'

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()
const workspace = useWorkspaceStore()
useLayoutService()
const drawerOpen = ref(true)

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
      { label: 'Студенты', to: '/students', icon: GraduationCap, permission: 'manage_dictionaries' },
      { label: 'Группы', to: '/groups', icon: UsersRound, permission: 'manage_dictionaries' },
    ],
  },
  {
    label: 'Учебный процесс',
    items: [
      { label: 'Расписание', to: '/schedule', icon: CalendarDays, permission: 'manage_schedule' },
      { label: 'Журнал', to: '/journal', icon: ClipboardList, permission: 'manage_journal' },
    ],
  },
  {
    label: 'Справочники',
    items: [
      { label: 'Преподаватели', to: '/teachers', icon: UserRound, permission: 'manage_dictionaries' },
      { label: 'Дисциплины', to: '/subjects', icon: BookOpen, permission: 'manage_dictionaries' },
      { label: 'Аудитории', to: '/classrooms', icon: Building2, permission: 'manage_dictionaries' },
    ],
  },
  {
    label: 'Прием и отчеты',
    items: [
      { label: 'Отчеты', to: '/reports', icon: FileText, permission: 'manage_journal' },
      { label: 'Приемная комиссия', to: '/admissions', icon: School, permission: 'manage_dictionaries' },
    ],
  },
  {
    label: 'Identity',
    items: [
      { label: 'Цифровые пропуска', to: '/identity/digital-passes', icon: QrCode, permission: 'manage_dictionaries' },
    ],
  },
  {
    label: 'Система',
    items: [
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

        return !item.permission || auth.can(item.permission)
      }),
    }))
    .filter((group) => group.items.length > 0),
)

const pageTitle = computed(() => route.meta.title || 'CollegePortal')
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
          <div class="cp-page-subtitle">Рабочее место колледжа</div>
        </q-toolbar-title>

        <div class="cp-topbar-tools">
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
          <img src="/brand/logo-skki-bw.jpg" alt="" aria-hidden="true" />
        </div>
        <div>
          <strong>CollegePortal</strong>
          <span>Колледж искусств</span>
        </div>
      </div>

      <q-scroll-area class="fit">
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
            </q-item>
          </template>
        </q-list>
      </q-scroll-area>
    </q-drawer>

    <q-page-container>
      <q-page class="cp-page">
        <RouterView />
      </q-page>
    </q-page-container>
  </q-layout>
</template>
