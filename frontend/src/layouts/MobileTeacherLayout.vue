<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { CalendarDays, Home, IdCard, Users } from '@lucide/vue'
import MobileCabinetShell from '../components/mobile/MobileCabinetShell.vue'
import { useAuthStore } from '../stores/auth'

const route = useRoute()
const auth = useAuthStore()

const navItems = computed(() => {
  const items = [
    { label: 'Сегодня', to: '/m/teacher', icon: Home, active: route.path === '/m/teacher' && !route.hash },
    { label: 'Неделя', to: { path: '/m/teacher', hash: '#week' }, icon: CalendarDays, active: route.hash === '#week' },
    { label: 'QR', to: '/m/teacher/pass', icon: IdCard, active: route.path === '/m/teacher/pass' },
  ]

  // Переход в кабинет куратора показываем только тому, у кого он открыт:
  // пункт меню, ведущий на «нет прав», — это тот же неработающий элемент.
  if (auth.can('mobile.curator.view')) {
    items.splice(2, 0, { label: 'Группа', to: '/m/curator', icon: Users, active: false })
  }

  return items
})
</script>

<template>
  <MobileCabinetShell title="Кабинет преподавателя" :nav-items="navItems" />
</template>
