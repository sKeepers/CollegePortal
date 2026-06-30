<script setup>
defineProps({
  title: {
    type: String,
    default: '',
  },
  subtitle: {
    type: String,
    default: '',
  },
})
</script>

<template>
  <q-card flat bordered class="app-card">
    <q-card-section v-if="title || subtitle || $slots.actions" class="app-card__header">
      <div>
        <div v-if="title" class="app-card__title">{{ title }}</div>
        <div v-if="subtitle" class="app-card__subtitle">{{ subtitle }}</div>
      </div>
      <div v-if="$slots.actions" class="app-card__actions">
        <slot name="actions" />
      </div>
    </q-card-section>
    <q-separator v-if="title || subtitle || $slots.actions" />
    <q-card-section>
      <slot />
    </q-card-section>
  </q-card>
</template>
