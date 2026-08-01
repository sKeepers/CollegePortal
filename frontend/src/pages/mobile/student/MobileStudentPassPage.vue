<script setup>
import { onBeforeUnmount, onMounted } from 'vue'
import { AlertCircle, IdCard, RefreshCw } from '@lucide/vue'
import { useMobileStudentStore, formatMobileDate, statusLabel } from '../../../stores/mobileStudent'
import { useAccessPassStore } from '../../../stores/accessPass'

const store = useMobileStudentStore()
const passStore = useAccessPassStore()
let timer = null
let refreshTimer = null

onMounted(async () => {
  await store.load()
  await passStore.issue().catch(() => {})
  timer = window.setInterval(() => passStore.pulse(), 1000)
  refreshTimer = window.setInterval(() => {
    if (passStore.remainingSeconds <= 6) passStore.issue().catch(() => {})
  }, 1000)
})

onBeforeUnmount(() => {
  if (timer) window.clearInterval(timer)
  if (refreshTimer) window.clearInterval(refreshTimer)
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

      <section v-else-if="store.hasActivePass || passStore.qrSvg" class="mobile-student-qr-card">
        <div class="mobile-student-qr" v-html="passStore.qrSvg || store.qrSvg" />
        <dl>
          <div><dt>ФИО</dt><dd>{{ store.studentName }}</dd></div>
          <div><dt>Группа</dt><dd>{{ store.groupName }}</dd></div>
          <div><dt>Статус</dt><dd>{{ statusLabel(store.digitalIdentity?.status) }}</dd></div>
          <div><dt>Действует до</dt><dd>{{ passStore.expiresAt ? formatMobileDate(passStore.expiresAt) : formatMobileDate(store.digitalIdentity?.expires_at) }}</dd></div>
          <div><dt>Обновление</dt><dd>{{ passStore.remainingSeconds }} сек.</dd></div>
        </dl>
        <p>QR-код содержит только короткоживущий технический токен. Персональные данные в QR не записываются.</p>
        <q-btn outline color="primary" no-caps :loading="passStore.loading" @click="passStore.issue"><RefreshCw :size="16" class="q-mr-xs" /> Обновить QR</q-btn>
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
