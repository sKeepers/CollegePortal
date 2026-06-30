<script setup>
defineProps({
  dense: {
    type: Boolean,
    default: true,
  },
})
</script>

<template>
  <q-card flat bordered class="app-filter-bar">
    <q-card-section :class="['app-filter-bar__content', { 'app-filter-bar__content--dense': dense }]">
      <slot />
      <div v-if="$slots.actions" class="app-filter-bar__actions">
        <slot name="actions" />
      </div>
    </q-card-section>
  </q-card>
</template>
