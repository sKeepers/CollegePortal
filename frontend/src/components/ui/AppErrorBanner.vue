<script setup>
defineProps({
  message: {
    type: String,
    default: '',
  },
})
</script>

<template>
  <q-banner v-if="message" rounded class="app-error-banner">
    {{ message }}
  </q-banner>
</template>
