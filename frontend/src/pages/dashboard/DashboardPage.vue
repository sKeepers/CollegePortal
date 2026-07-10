<script setup>
import { computed } from 'vue'
import { useAuthStore } from '../../stores/auth'
import AdminDashboard from './role/AdminDashboard.vue'
import TeacherDashboard from './role/TeacherDashboard.vue'
import GeneralDashboard from './role/GeneralDashboard.vue'

const auth = useAuthStore()
const primaryRole = computed(() => auth.user?.role?.code || auth.user?.roles?.[0]?.code || 'guest')
const dashboardComponent = computed(() => {
  if (['admin', 'director'].includes(primaryRole.value)) {
    return AdminDashboard
  }

  if (primaryRole.value === 'teacher') {
    return TeacherDashboard
  }

  return GeneralDashboard
})
</script>

<template>
  <component :is="dashboardComponent" :primary-role="primaryRole" />
</template>
