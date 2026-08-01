<script setup>
import { computed, nextTick } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { CalendarDays, Home, IdCard, ListChecks } from '@lucide/vue'
import EnvironmentBadge from '../components/system/EnvironmentBadge.vue'
import { getCurrentEnvironment } from '../services/environmentService'

const route = useRoute()
const router = useRouter()
const environment = getCurrentEnvironment()
const navItems = computed(() => [
  { label: 'Главная', to: '/m/student', icon: Home, active: route.path === '/m/student' && !route.hash },
  { label: 'Расписание', to: { path: '/m/student', hash: '#schedule' }, icon: CalendarDays, active: route.hash === '#schedule' },
  { label: 'Журнал', to: { path: '/m/student', hash: '#journal' }, icon: ListChecks, active: route.hash === '#journal' },
  { label: 'QR', to: '/m/student/pass', icon: IdCard, active: route.path === '/m/student/pass' },
])

async function navigate(item) {
  await router.push(item.to)
  const id = typeof item.to === 'object' ? item.to.hash?.slice(1) : ''
  await nextTick()
  if (id) document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  if (item.to === '/m/student') window.scrollTo({ top: 0, behavior: 'smooth' })
}
</script>

<template>
  <q-layout view="hHh lpR fFf" class="mobile-student-layout" :style="{ '--cp-environment-color': environment.color }">
    <div class="cp-environment-line" />
    <q-header class="mobile-student-header">
      <q-toolbar>
        <div class="mobile-student-brand">
          <img src="/icons/icon-192.png" alt="CollegePortal" />
          <div>
            <strong>CollegePortal</strong>
            <span>Кабинет студента</span>
          </div>
        </div>
        <q-space />
        <EnvironmentBadge />
      </q-toolbar>
    </q-header>

    <q-page-container>
      <router-view />
    </q-page-container>

    <q-footer class="mobile-student-bottom-nav">
      <nav>
        <button v-for="item in navItems" :key="item.label" type="button" :class="['mobile-student-nav-item', { 'mobile-student-nav-item--active': item.active }]" @click="navigate(item)">
          <component :is="item.icon" :size="20" />
          <span>{{ item.label }}</span>
        </button>
      </nav>
    </q-footer>
  </q-layout>
</template>
