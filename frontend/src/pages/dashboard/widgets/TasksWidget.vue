<script setup>
import AppCard from '../../../components/ui/AppCard.vue'
import AppStatusBadge from '../../../components/ui/AppStatusBadge.vue'

defineProps({
  items: {
    type: Array,
    default: () => [],
  },
})
</script>

<template>
  <AppCard title="Мои задачи" subtitle="То, что требует внимания">
    <div class="dashboard-tasks">
      <div v-for="item in items" :key="item.id" class="dashboard-task">
        <q-checkbox :model-value="item.done" dense disable />
        <div>
          <strong>{{ item.title }}</strong>
          <small>{{ item.due }}</small>
        </div>
        <AppStatusBadge :label="item.status" :tone="item.tone" />
      </div>
    </div>
  </AppCard>
</template>
