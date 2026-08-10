<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { Bell, Home, Search } from '@lucide/vue'
import MobileCabinetShell from '../components/mobile/MobileCabinetShell.vue'

const route = useRoute()

const navItems = computed(() => [
  { label: 'Сводка', to: '/m/admin', icon: Home, active: route.path === '/m/admin' && !route.hash },
  { label: 'Входящие', to: { path: '/m/admin', hash: '#inbox' }, icon: Bell, active: route.hash === '#inbox' },
  { label: 'Поиск', to: { path: '/m/admin', hash: '#search' }, icon: Search, active: route.hash === '#search' },
])
</script>

<template>
  <MobileCabinetShell title="Кабинет администратора" :nav-items="navItems" />
</template>
