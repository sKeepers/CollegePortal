<script setup>
import AppCard from '../../../components/ui/AppCard.vue'

defineProps({
  items: {
    type: Array,
    default: () => [],
  },
  loading: {
    type: Boolean,
    default: false,
  },
})
</script>

<template>
  <AppCard title="Ключевые показатели" subtitle="Оперативная сводка по основным разделам">
    <div class="dashboard-stats">
      <div v-for="item in items" :key="item.label" class="dashboard-stat">
        <span class="dashboard-stat__icon">
          <component :is="item.icon" :size="20" />
        </span>
        <div>
          <strong>{{ loading ? '...' : item.value }}</strong>
          <small>{{ item.label }}</small>
        </div>
      </div>
    </div>
  </AppCard>
</template>
