<script setup>
// Общая оболочка мобильного кабинета: шапка, нижняя навигация, полоса
// окружения. Кабинеты преподавателя, куратора и администратора отличаются
// только заголовком и составом навигации, поэтому различия вынесены в props,
// а не в три копии разметки.
import { nextTick } from 'vue'
import { useRouter } from 'vue-router'
import EnvironmentBadge from '../system/EnvironmentBadge.vue'
import { getCurrentEnvironment } from '../../services/environmentService'

defineProps({
  title: { type: String, required: true },
  navItems: { type: Array, default: () => [] },
})

const router = useRouter()
const environment = getCurrentEnvironment()

async function navigate(item) {
  await router.push(item.to)
  await nextTick()
  const hash = typeof item.to === 'object' ? item.to.hash?.slice(1) : ''
  if (hash) {
    document.getElementById(hash)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
    return
  }
  window.scrollTo({ top: 0, behavior: 'smooth' })
}
</script>

<template>
  <q-layout view="hHh lpR fFf" class="mobile-cabinet-layout" :style="{ '--cp-environment-color': environment.color }">
    <div class="cp-environment-line" />
    <q-header class="mobile-cabinet-header">
      <q-toolbar>
        <div class="mobile-cabinet-brand">
          <img src="/icons/icon-192.png" alt="CollegePortal" />
          <div>
            <strong>CollegePortal</strong>
            <span>{{ title }}</span>
          </div>
        </div>
        <q-space />
        <EnvironmentBadge />
      </q-toolbar>
    </q-header>

    <q-page-container>
      <router-view />
    </q-page-container>

    <q-footer v-if="navItems.length" class="mobile-cabinet-bottom-nav">
      <nav>
        <button
          v-for="item in navItems"
          :key="item.label"
          type="button"
          :class="['mobile-cabinet-nav-item', { 'mobile-cabinet-nav-item--active': item.active }]"
          @click="navigate(item)"
        >
          <component :is="item.icon" :size="20" />
          <span>{{ item.label }}</span>
        </button>
      </nav>
    </q-footer>
  </q-layout>
</template>
