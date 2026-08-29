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
    <!--
      Пустой список рисовал заголовок над пустотой, и карточка читалась как
      недоделка. Событий может не быть законно — так и скажем.
    -->
    <p v-if="!items.length" class="dashboard-list__empty">Событий пока нет</p>

    <div v-else class="dashboard-list">
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

<style scoped>
.dashboard-list__empty {
  margin: 0;
  color: #6b7280;
  font-size: 13px;
}
</style>
