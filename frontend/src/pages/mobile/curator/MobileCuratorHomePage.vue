<script setup>
import { onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { ChevronRight, Users } from '@lucide/vue'
import { useMobileCuratorStore } from '../../../stores/mobileCurator'

const store = useMobileCuratorStore()
const router = useRouter()

onMounted(async () => {
  await store.load()
  // У куратора почти всегда одна группа: лишний экран со списком из одного
  // пункта на телефоне только мешает.
  if (store.groups.length === 1) {
    await router.replace(`/m/curator/groups/${store.groups[0].id}`)
  }
})
</script>

<template>
  <q-page class="mobile-cabinet-page">
    <div v-if="store.loading" class="mobile-cabinet-loading"><q-spinner color="primary" size="32px" /><span>Загрузка кабинета...</span></div>
    <q-banner v-else-if="store.error" class="mobile-cabinet-banner mobile-cabinet-banner--error">{{ store.error }}</q-banner>

    <template v-else>
      <section class="mobile-cabinet-hero">
        <div class="mobile-cabinet-avatar"><Users :size="30" /></div>
        <div>
          <p>Кабинет куратора</p>
          <h1>{{ store.curatorName }}</h1>
          <span v-if="store.hasGroups">{{ store.groups.length }} закреплённых групп</span>
        </div>
      </section>

      <q-banner v-if="store.message" class="mobile-cabinet-banner">{{ store.message }}</q-banner>

      <section v-if="store.hasGroups" class="mobile-cabinet-card">
        <header><Users :size="20" /><h2>Мои группы</h2></header>
        <div class="mobile-cabinet-lessons">
          <RouterLink
            v-for="group in store.groups"
            :key="group.id"
            :to="`/m/curator/groups/${group.id}`"
            class="mobile-cabinet-lesson"
          >
            <div class="mobile-cabinet-lesson-body">
              <strong>{{ group.name }}</strong>
              <span>{{ group.course }} курс · {{ group.specialty || 'Специальность не указана' }}</span>
              <em>{{ group.students_count }} студентов</em>
            </div>
            <ChevronRight :size="18" />
          </RouterLink>
        </div>
      </section>
    </template>
  </q-page>
</template>
