<script setup>
defineProps({
  narrow: {
    type: Boolean,
    default: false,
  },
})
</script>

<template>
  <div :class="['app-page', { 'app-page--narrow': narrow }]">
    <slot />
  </div>
</template>
