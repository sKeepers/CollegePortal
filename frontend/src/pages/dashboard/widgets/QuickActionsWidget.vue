<script setup>
import { useRouter } from 'vue-router'
import AppCard from '../../../components/ui/AppCard.vue'

defineProps({
  actions: {
    type: Array,
    default: () => [],
  },
})

const router = useRouter()

function openAction(action) {
  if (action?.to) {
    router.push(action.to)
  }
}
</script>

<template>
  <AppCard title="Быстрые действия" subtitle="Частые переходы для рабочего дня">
    <div class="dashboard-actions">
      <button
        v-for="action in actions"
        :key="action.label"
        type="button"
        class="dashboard-action"
        @click="openAction(action)"
      >
        <span class="dashboard-action__icon">
          <component :is="action.icon" :size="18" />
        </span>
        <span>
          <strong>{{ action.label }}</strong>
          <small>{{ action.description }}</small>
        </span>
      </button>
    </div>
  </AppCard>
</template>
