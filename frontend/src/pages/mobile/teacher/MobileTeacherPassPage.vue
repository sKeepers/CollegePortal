<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { AlertCircle, IdCard, RefreshCw } from '@lucide/vue'
import { useMobileTeacherStore } from '../../../stores/mobileTeacher'

const store = useMobileTeacherStore()
const now = ref(Date.now())
let refreshTimer = null
let clockTimer = null

const qrSecondsLeft = computed(() => {
  if (!store.qrExpiresAt) return 0
  const expires = new Date(store.qrExpiresAt).getTime()
  if (Number.isNaN(expires)) return 0
  return Math.max(0, Math.ceil((expires - now.value) / 1000))
})

async function refreshPass() {
  await store.load()
}

onMounted(async () => {
  await refreshPass()
  const refreshMs = Math.max(10, Number(store.qrRefreshSeconds || 30) - 3) * 1000
  refreshTimer = window.setInterval(refreshPass, refreshMs)
  clockTimer = window.setInterval(() => { now.value = Date.now() }, 1000)
})

onBeforeUnmount(() => {
  if (refreshTimer) window.clearInterval(refreshTimer)
  if (clockTimer) window.clearInterval(clockTimer)
})
</script>

<template>
  <q-page class="mobile-cabinet-page">
    <div v-if="store.loading" class="mobile-cabinet-loading"><q-spinner color="primary" size="32px" /><span>Загрузка QR-пропуска...</span></div>
    <q-banner v-else-if="store.error" class="mobile-cabinet-banner mobile-cabinet-banner--error">{{ store.error }}</q-banner>

    <template v-else>
      <section class="mobile-cabinet-hero">
        <div class="mobile-cabinet-avatar"><IdCard :size="28" /></div>
        <div>
          <p>Мой QR-пропуск</p>
          <h1>{{ store.teacherName }}</h1>
        </div>
      </section>

      <q-banner v-if="!store.hasTeacher" class="mobile-cabinet-banner">{{ store.message || 'Текущий пользователь не связан с карточкой преподавателя.' }}</q-banner>

      <section v-else-if="store.hasActivePass" class="mobile-cabinet-qr-card">
        <div class="mobile-cabinet-qr" v-html="store.qrSvg" />
        <dl>
          <div><dt>ФИО</dt><dd>{{ store.teacherName }}</dd></div>
          <div><dt>Код обновится</dt><dd>через {{ qrSecondsLeft }} сек.</dd></div>
        </dl>
        <p>QR-код обновляется каждые 30 секунд и содержит только короткоживущий технический токен. Персональные данные в QR не записываются.</p>
        <q-btn outline color="primary" no-caps @click="refreshPass"><RefreshCw :size="16" class="q-mr-xs" /> Обновить код</q-btn>
      </section>

      <section v-else class="mobile-cabinet-card mobile-cabinet-no-pass">
        <AlertCircle :size="32" />
        <h2>Активный QR-пропуск не найден</h2>
        <p>Обратитесь в отдел кадров или к администратору, чтобы выпустить цифровой пропуск.</p>
        <q-btn outline color="primary" no-caps @click="store.load"><RefreshCw :size="16" class="q-mr-xs" /> Обновить</q-btn>
      </section>
    </template>
  </q-page>
</template>
