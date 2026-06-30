<script setup>
defineProps({
  label: {
    type: String,
    default: 'Загрузка данных...',
  },
})
</script>

<template>
  <div class="app-loading" role="status">
    <q-spinner size="18px" color="primary" />
    <span>{{ label }}</span>
  </div>
</template>
