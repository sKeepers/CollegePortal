<script setup>
import { computed, onBeforeUnmount, onMounted } from 'vue'
import { AlertTriangle, RefreshCw, ShieldCheck, Wifi, WifiOff } from '@lucide/vue'
import AppPage from '../../components/ui/AppPage.vue'
import PageHeader from '../../components/ui/PageHeader.vue'
import AppCard from '../../components/ui/AppCard.vue'
import AppErrorBanner from '../../components/ui/AppErrorBanner.vue'
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'
import { useAccessPassStore } from '../../stores/accessPass'

const store = useAccessPassStore()
let timer = null
let refreshTimer = null

const statusTone = computed(() => store.online ? 'success' : 'danger')
const statusLabel = computed(() => store.online ? 'Подключено' : 'Нет связи')

async function refreshToken() {
  await store.issue().catch(() => {})
}

onMounted(async () => {
  await refreshToken()
  timer = window.setInterval(() => store.pulse(), 1000)
  refreshTimer = window.setInterval(() => {
    if (store.remainingSeconds <= 6) refreshToken()
  }, 1000)
})

onBeforeUnmount(() => {
  if (timer) window.clearInterval(timer)
  if (refreshTimer) window.clearInterval(refreshTimer)
})
</script>

<template>
  <AppPage class="access-pass-page">
    <PageHeader title="Мой динамический QR-пропуск" subtitle="QR обновляется автоматически и действует 30 секунд. В QR нет ФИО, телефона, группы или других персональных данных." />

    <AppErrorBanner :message="store.error" />

    <div class="access-pass-layout">
      <AppCard class="access-pass-card">
        <div class="access-pass-owner">
          <div class="access-pass-owner__icon"><ShieldCheck :size="30" /></div>
          <div>
            <h2>{{ store.ownerName }}</h2>
            <p>{{ store.ownerMeta }}</p>
          </div>
          <AppStatusBadge :label="statusLabel" :tone="statusTone" />
        </div>

        <div class="access-pass-qr-shell">
          <div v-if="store.qrSvg" class="access-pass-qr" v-html="store.qrSvg" />
          <div v-else class="access-pass-qr access-pass-qr--empty">QR</div>
        </div>

        <div class="access-pass-countdown">
          <div class="access-pass-countdown__top">
            <span>Обновление через</span>
            <strong>{{ store.remainingSeconds }} сек.</strong>
          </div>
          <q-linear-progress rounded size="10px" :value="store.progress / 100" color="primary" track-color="grey-3" />
        </div>

        <div class="access-pass-actions">
          <q-btn color="primary" no-caps :loading="store.loading" @click="refreshToken"><RefreshCw :size="16" class="q-mr-xs" /> Обновить сейчас</q-btn>
          <div class="access-pass-network"><component :is="store.online ? Wifi : WifiOff" :size="17" /> {{ statusLabel }}</div>
        </div>
      </AppCard>

      <AppCard class="access-pass-rules">
        <h3><AlertTriangle :size="18" /> Безопасность QR</h3>
        <ul>
          <li>Не передавайте QR другим людям.</li>
          <li>Скриншот перестает работать после истечения TTL.</li>
          <li>Повторное использование уже считанного QR отклоняется.</li>
          <li>При потере телефона сообщите администратору проходной.</li>
        </ul>
      </AppCard>
    </div>
  </AppPage>
</template>

<style scoped>
.access-pass-layout { display: grid; grid-template-columns: minmax(320px, 520px) minmax(260px, 1fr); gap: 20px; align-items: start; }
.access-pass-card { padding: 22px; }
.access-pass-owner { display: grid; grid-template-columns: 48px 1fr auto; gap: 12px; align-items: center; margin-bottom: 18px; }
.access-pass-owner h2 { margin: 0; font-size: 20px; font-weight: 700; }
.access-pass-owner p { margin: 2px 0 0; color: #64748b; }
.access-pass-owner__icon { width: 48px; height: 48px; display: grid; place-items: center; border-radius: 8px; background: #e0f2fe; color: #0369a1; }
.access-pass-qr-shell { background: #fff; border: 1px solid #e5e7eb; display: grid; place-items: center; padding: 18px; min-height: 320px; }
.access-pass-qr { width: min(360px, 80vw); aspect-ratio: 1; display: grid; place-items: center; }
.access-pass-qr :deep(svg) { width: 100%; height: 100%; display: block; background: #ffffff; shape-rendering: crispEdges; }
.access-pass-qr--empty { border: 1px dashed #cbd5e1; color: #94a3b8; font-weight: 700; }
.access-pass-countdown { margin-top: 16px; }
.access-pass-countdown__top { display: flex; justify-content: space-between; margin-bottom: 8px; color: #475569; }
.access-pass-actions { display: flex; justify-content: space-between; gap: 12px; align-items: center; margin-top: 16px; flex-wrap: wrap; }
.access-pass-network { display: inline-flex; gap: 6px; align-items: center; color: #475569; }
.access-pass-rules { padding: 20px; }
.access-pass-rules h3 { display: flex; gap: 8px; align-items: center; margin: 0 0 12px; font-size: 17px; }
.access-pass-rules ul { margin: 0; padding-left: 18px; color: #475569; line-height: 1.7; }
@media (max-width: 860px) { .access-pass-layout { grid-template-columns: 1fr; } .access-pass-owner { grid-template-columns: 44px 1fr; } .access-pass-owner :deep(.app-status-badge) { grid-column: 1 / -1; width: fit-content; } }
</style>
