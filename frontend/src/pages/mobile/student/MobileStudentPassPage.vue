<script setup>
import { onMounted } from 'vue'
import { AlertCircle, IdCard, RefreshCw } from '@lucide/vue'
import { useMobileStudentStore, formatMobileDate, statusLabel } from '../../../stores/mobileStudent'

const store = useMobileStudentStore()
onMounted(() => store.load())
</script>

<template>
  <q-page class="mobile-student-page mobile-student-pass-page">
    <div v-if="store.loading" class="mobile-student-loading"><q-spinner color="primary" size="32px" /><span>Загрузка QR-пропуска...</span></div>
    <q-banner v-else-if="store.error" class="mobile-student-banner mobile-student-banner--error">{{ store.error }}</q-banner>

    <template v-else>
      <section class="mobile-student-pass-hero">
        <IdCard :size="30" />
        <div><h1>Мой QR-пропуск</h1><p>{{ store.studentName }} · {{ store.groupName }}</p></div>
      </section>

      <q-banner v-if="!store.hasStudent" class="mobile-student-banner">{{ store.message || 'Текущий пользователь не связан с карточкой студента.' }}</q-banner>

      <section v-else-if="store.hasActivePass" class="mobile-student-qr-card">
        <div class="mobile-student-qr" v-html="store.qrSvg" />
        <dl>
          <div><dt>ФИО</dt><dd>{{ store.studentName }}</dd></div>
          <div><dt>Группа</dt><dd>{{ store.groupName }}</dd></div>
          <div><dt>Статус</dt><dd>{{ statusLabel(store.digitalIdentity?.status) }}</dd></div>
          <div><dt>Действует до</dt><dd>{{ formatMobileDate(store.digitalIdentity?.expires_at) }}</dd></div>
        </dl>
        <p>QR-код содержит только технический токен пропуска. Персональные данные в QR не записываются.</p>
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
