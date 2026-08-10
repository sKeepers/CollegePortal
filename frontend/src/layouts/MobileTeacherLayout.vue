<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { CalendarDays, Home, IdCard } from '@lucide/vue'
import MobileCabinetShell from '../components/mobile/MobileCabinetShell.vue'

const route = useRoute()

const navItems = computed(() => [
  { label: 'Сегодня', to: '/m/teacher', icon: Home, active: route.path === '/m/teacher' && !route.hash },
  { label: 'Неделя', to: { path: '/m/teacher', hash: '#week' }, icon: CalendarDays, active: route.hash === '#week' },
  { label: 'QR', to: '/m/teacher/pass', icon: IdCard, active: route.path === '/m/teacher/pass' },
])
</script>

<template>
  <MobileCabinetShell title="Кабинет преподавателя" :nav-items="navItems" />
</template>
