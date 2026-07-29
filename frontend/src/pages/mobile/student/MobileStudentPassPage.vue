<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { AlertCircle, IdCard, RefreshCw } from '@lucide/vue'
import { useMobileStudentStore, formatMobileDate, statusLabel } from '../../../stores/mobileStudent'

const store = useMobileStudentStore()
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
  <q-page class="mobile-student-page mobile-student-pass-page">
    <div v-if="store.loading" class="mobile-student-loading"><q-spinner color="primary" size="32px" /><span>Загрузка QR-пропуска...</span></div>
    <q-banner v-else-if="store.error" class="mobile-student-banner mobile-student-banner--error">{{ store.error }}</q-banner>

    <template v-else>
      <section class="mobile-student-pass-hero">
        <IdCard :size="30" />
        <q-avatar size="48px" class="mobile-student-pass-photo"><img v-if="store.student?.photo_url" :src="store.student.photo_url" alt="Фото" /><IdCard v-else :size="26" /></q-avatar><div><h1>Мой QR-пропуск</h1><p>{{ store.studentName }} · {{ store.groupName }}</p></div>
      </section>

      <q-banner v-if="!store.hasStudent" class="mobile-student-banner">{{ store.message || 'Текущий пользователь не связан с карточкой студента.' }}</q-banner>

      <section v-else-if="store.hasActivePass" class="mobile-student-qr-card">
        <div class="mobile-student-qr" v-html="store.qrSvg" />
        <dl>
          <div><dt>ФИО</dt><dd>{{ store.studentName }}</dd></div>
          <div><dt>Группа</dt><dd>{{ store.groupName }}</dd></div>
          <div><dt>Статус</dt><dd>{{ statusLabel(store.digitalIdentity?.status) }}</dd></div>
          <div><dt>Код обновится</dt><dd>через {{ qrSecondsLeft }} сек.</dd></div>
          <div><dt>Пропуск действует до</dt><dd>{{ formatMobileDate(store.digitalIdentity?.expires_at) }}</dd></div>
        </dl>
        <p>QR-код обновляется каждые 30 секунд и содержит только короткоживущий технический токен. Персональные данные в QR не записываются.</p>
        <q-btn outline color="primary" no-caps @click="refreshPass"><RefreshCw :size="16" class="q-mr-xs" /> Обновить код</q-btn>
      </section>

      <section v-else class="mobile-student-card mobile-student-no-pass">
        <AlertCircle :size="32" />
        <h2>Активный QR-пропуск не найден</h2>
        <p>Обратитесь в учебную часть или к администратору, чтобы выпустить цифровой пропуск.</p>
        <q-btn outline color="primary" no-caps @click="store.load"><RefreshCw :size="16" class="q-mr-xs" /> Обновить</q-btn>
      </section>
    </template>
  </q-page>
</template>
