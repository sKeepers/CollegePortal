<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { CalendarDays, DoorOpen, IdCard, Users } from '@lucide/vue'
import MobileCabinetShell from '../components/mobile/MobileCabinetShell.vue'
import { useAuthStore } from '../stores/auth'

const route = useRoute()
const auth = useAuthStore()

const navItems = computed(() => {
  const items = [
    { label: 'Группа', to: '/m/curator', icon: Users, active: route.path.startsWith('/m/curator') && !route.hash },
    { label: 'Проходная', to: { path: route.path, hash: '#access' }, icon: DoorOpen, active: route.hash === '#access' },
  ]

  // Куратор — это преподаватель с закреплённой группой, и занятия у него свои
  // есть. Кабинет преподавателя показываем только тому, у кого он открыт.
  if (auth.can('mobile.teacher.view')) {
    items.push({ label: 'Занятия', to: '/m/teacher', icon: CalendarDays, active: false })
    items.push({ label: 'QR', to: '/m/teacher/pass', icon: IdCard, active: false })
  }

  return items
})
</script>

<template>
  <MobileCabinetShell title="Кабинет куратора" :nav-items="navItems" />
</template>
