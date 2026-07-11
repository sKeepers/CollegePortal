<script setup>
import { computed } from 'vue'
import { usePermissions } from '../composables/usePermissions'

const props = defineProps({
  permission: { type: String, default: '' },
  any: { type: Array, default: () => [] },
  all: { type: Array, default: () => [] },
})

const permissions = usePermissions()
const allowed = computed(() => {
  if (props.permission) return permissions.hasPermission(props.permission)
  if (props.any.length) return permissions.hasAnyPermission(props.any)
  if (props.all.length) return permissions.hasAllPermissions(props.all)
  return true
})
</script>

<template>
  <slot v-if="allowed" />
</template>
