<script setup>
import { computed } from 'vue'
import { useWorkspaceStore } from '../../stores/workspace'

defineProps({
  narrow: {
    type: Boolean,
    default: false,
  },
})

const workspace = useWorkspaceStore()
const appPageClasses = computed(() => [
  'app-page',
  `app-page--${workspace.breakpoint}`,
  `app-page--${workspace.settings.density}`,
  { 'app-page--ultrawide': workspace.isUltrawide },
])
</script>

<template>
  <div :class="[appPageClasses, { 'app-page--narrow': narrow }]">
    <slot />
  </div>
</template>
