<script setup>
import { Inbox } from '@lucide/vue'

defineProps({
  title: {
    type: String,
    default: 'Нет данных',
  },
  description: {
    type: String,
    default: 'Измените фильтры или создайте новую запись.',
  },
})
</script>

<template>
  <q-card flat bordered class="app-empty-state">
    <q-card-section>
      <Inbox :size="30" />
      <strong>{{ title }}</strong>
      <p>{{ description }}</p>
      <slot />
    </q-card-section>
  </q-card>
</template>
