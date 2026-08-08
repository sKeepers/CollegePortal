<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { RefreshCw, Users } from '@lucide/vue'
import AppPage from '../../components/ui/AppPage.vue'
import PageHeader from '../../components/ui/PageHeader.vue'
import AppCard from '../../components/ui/AppCard.vue'
import AppEmptyState from '../../components/ui/AppEmptyState.vue'
import AppErrorBanner from '../../components/ui/AppErrorBanner.vue'
import AppLoading from '../../components/ui/AppLoading.vue'
import { useAccessBuildingsStore } from '../../stores/accessBuildings'

const store = useAccessBuildingsStore()
const REFRESH_MS = 30000
let refreshTimer = null

const updatedAt = ref('')

const buildings = computed(() => store.muster.buildings || [])
const hasAnybody = computed(() => store.insideNow > 0)

async function refresh() {
  await store.loadMuster()
  updatedAt.value = store.formatEnteredAt(store.muster.generated_at)
}

onMounted(() => {
  refresh()
  // Список сам держится свежим: на эвакуации никто не будет жать «Обновить».
  refreshTimer = setInterval(refresh, REFRESH_MS)
})

onBeforeUnmount(() => {
  if (refreshTimer) clearInterval(refreshTimer)
})
</script>

<template>
  <AppPage>
    <PageHeader
      title="Кто сейчас в здании"
      subtitle="Поименный список для эвакуации. Открывается без фильтров и обновляется сам."
    />

    <AppErrorBanner :message="store.error" />

    <div class="muster-total">
      <div class="muster-total__count">
        <Users :size="28" />
        <span>{{ store.insideNow }}</span>
      </div>
      <div class="muster-total__meta">
        <div>всего в зданиях</div>
        <div v-if="updatedAt" class="muster-total__time">данные на {{ updatedAt }}</div>
      </div>
      <q-btn flat class="muster-total__refresh" :disable="store.loading" @click="refresh">
        <RefreshCw :size="18" />
      </q-btn>
    </div>

    <AppLoading v-if="store.loading && !buildings.length" label="Загрузка списка..." />

    <AppEmptyState
      v-else-if="!buildings.length"
      title="Корпуса не заведены"
      description="Добавьте корпуса и точки прохода в справочнике, иначе проходы не с чем связать."
    />

    <AppEmptyState
      v-else-if="!hasAnybody"
      title="В зданиях никого нет"
      description="Ни одного входа без последующего выхода за сегодня."
    />

    <AppCard v-for="building in buildings" v-else :key="building.building_id ?? 'unassigned'" class="muster-building">
      <div class="muster-building__header">
        <div class="muster-building__name">{{ building.building_name }}</div>
        <div class="muster-building__count">{{ building.people_count }}</div>
      </div>
      <div v-if="building.address" class="muster-building__address">{{ building.address }}</div>

      <div v-if="!building.people.length" class="muster-building__empty">Пусто</div>

      <ul v-else class="muster-people">
        <li v-for="person in building.people" :key="person.access_event_id" class="muster-person">
          <div class="muster-person__name">{{ person.full_name }}</div>
          <div class="muster-person__meta">
            <span>{{ person.entity_label }}</span>
            <span v-if="person.group">· {{ person.group }}</span>
            <span v-if="person.access_point">· {{ person.access_point }}</span>
            <span class="muster-person__time">{{ store.formatEnteredAt(person.entered_at) }}</span>
          </div>
        </li>
      </ul>
    </AppCard>
  </AppPage>
</template>

<style scoped>
.muster-total {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 16px;
  margin-bottom: 16px;
  border-radius: 12px;
  background: var(--cp-surface-2, rgba(0, 0, 0, 0.04));
}

.muster-total__count {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 32px;
  font-weight: 600;
  line-height: 1;
}

.muster-total__meta {
  flex: 1;
  font-size: 14px;
  opacity: 0.75;
}

.muster-total__time {
  font-size: 12px;
}

.muster-total__refresh {
  min-height: 44px;
  min-width: 44px;
}

.muster-building {
  margin-bottom: 12px;
}

.muster-building__header {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 12px;
}

.muster-building__name {
  font-size: 18px;
  font-weight: 600;
}

.muster-building__count {
  font-size: 20px;
  font-weight: 600;
}

.muster-building__address,
.muster-building__empty {
  font-size: 13px;
  opacity: 0.7;
  margin-top: 4px;
}

.muster-people {
  list-style: none;
  margin: 12px 0 0;
  padding: 0;
}

.muster-person {
  padding: 10px 0;
  border-top: 1px solid var(--cp-border, rgba(0, 0, 0, 0.08));
}

.muster-person__name {
  font-size: 16px;
  font-weight: 500;
}

.muster-person__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  font-size: 13px;
  opacity: 0.7;
  margin-top: 2px;
}

.muster-person__time {
  margin-left: auto;
}
</style>
