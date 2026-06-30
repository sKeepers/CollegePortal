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
  <AppCard title="Уведомления" subtitle="Системные и учебные сообщения">
    <div class="dashboard-list">
      <div v-for="item in items" :key="item.id" class="dashboard-list__item dashboard-list__item--stacked">
        <div>
          <strong>{{ item.title }}</strong>
          <small>{{ item.description }}</small>
        </div>
        <AppStatusBadge :label="item.status" :tone="item.tone" />
      </div>
    </div>
  </AppCard>
</template>
