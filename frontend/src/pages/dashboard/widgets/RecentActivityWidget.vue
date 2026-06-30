<script setup>
import AppCard from '../../../components/ui/AppCard.vue'

defineProps({
  items: {
    type: Array,
    default: () => [],
  },
})
</script>

<template>
  <AppCard title="Последние действия" subtitle="Недавние события в рабочем контуре">
    <div class="dashboard-list">
      <div v-for="item in items" :key="item.id" class="dashboard-list__item">
        <span class="dashboard-list__marker" />
        <div>
          <strong>{{ item.title }}</strong>
          <small>{{ item.description }}</small>
        </div>
        <time>{{ item.time }}</time>
      </div>
    </div>
  </AppCard>
</template>
