<script setup>
defineProps({
  title: {
    type: String,
    required: true,
  },
  description: {
    type: String,
    default: 'Раздел будет перенесен из старого интерфейса на следующих этапах.',
  },
})
</script>

<template>
  <q-card flat bordered class="cp-stub-card">
    <q-card-section>
      <div class="text-h6">{{ title }}</div>
      <p class="cp-muted">{{ description }}</p>
    </q-card-section>
  </q-card>
</template>
