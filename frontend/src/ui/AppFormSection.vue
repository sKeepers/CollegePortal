<script setup>
defineProps({
  title: {
    type: String,
    default: '',
  },
  description: {
    type: String,
    default: '',
  },
})
</script>

<template>
  <section class="app-form-section">
    <header v-if="title || description">
      <h2 v-if="title">{{ title }}</h2>
      <p v-if="description">{{ description }}</p>
    </header>
    <div class="app-form-section__body">
      <slot />
    </div>
  </section>
</template>
