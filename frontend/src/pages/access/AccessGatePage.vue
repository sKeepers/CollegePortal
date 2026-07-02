<script setup>
import { computed, nextTick, onMounted, ref } from 'vue'
import { CheckCircle2, LogIn, LogOut, RefreshCw, ScanLine, UserRound, XCircle } from '@lucide/vue'
import AppPage from '../../components/ui/AppPage.vue'
import PageHeader from '../../components/ui/PageHeader.vue'
import AppToolbar from '../../components/ui/AppToolbar.vue'
import AppCard from '../../components/ui/AppCard.vue'
import AppErrorBanner from '../../components/ui/AppErrorBanner.vue'
import AppLoading from '../../components/ui/AppLoading.vue'
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'
import { directionLabel, entityTypeLabel, formatEventTime, ownerName, resultLabel, resultTone, useAccessGateStore } from '../../stores/accessGate'

const store = useAccessGateStore()
const scanInputRef = ref(null)
const token = ref('')
const accessPoint = ref('Главный вход')
const deviceName = ref('HID QR Scanner')
const statusPanelClass = computed(() => store.lastEvent?.result === 'allowed' ? 'access-gate-result--allowed' : 'access-gate-result--denied')
const resultIcon = computed(() => store.lastEvent?.result === 'allowed' ? CheckCircle2 : XCircle)
const directionIcon = computed(() => store.lastEvent?.direction === 'out' ? LogOut : LogIn)

async function focusScanner() {
  await nextTick()
  scanInputRef.value?.focus?.()
}

async function submitScan() {
  const value = token.value
  token.value = ''
  await store.scan(value, { access_point: accessPoint.value, device_name: deviceName.value })
  await focusScanner()
}

onMounted(async () => {
  await store.loadEvents()
  await focusScanner()
})
</script>

<template>
  <AppPage>
    <PageHeader title="Проходная" subtitle="Режим сканирования QR-пропусков. USB-сканер работает как клавиатура: сканирование завершается Enter." />

    <AppToolbar>
      <span>Последних событий: {{ store.events.length }}</span>
      <template #actions>
        <AppLoading v-if="store.loading" label="Загрузка событий..." />
        <q-btn flat :disable="store.loading" @click="store.loadEvents"><RefreshCw :size="16" class="q-mr-xs" /> Обновить</q-btn>
      </template>
    </AppToolbar>

    <AppErrorBanner :message="store.error" />

    <div class="access-gate-layout">
      <section class="access-gate-main">
        <AppCard class="access-gate-scanner-card">
          <div class="access-gate-scanner-card__header">
            <div class="access-gate-scanner-card__icon"><ScanLine :size="30" /></div>
            <div>
              <h2>Сканирование QR</h2>
              <p>Поставьте курсор в поле и отсканируйте QR-пропуск.</p>
            </div>
          </div>

          <q-form class="access-gate-scan-form" @submit.prevent="submitScan">
            <q-input ref="scanInputRef" v-model="token" outlined autofocus input-class="access-gate-scan-input" label="Поле сканирования" placeholder="Ожидание QR-сканера..." :disable="store.scanning">
              <template #prepend><ScanLine :size="24" /></template>
            </q-input>
            <div class="access-gate-scan-form__meta">
              <q-input v-model="accessPoint" dense outlined label="Точка доступа" />
              <q-input v-model="deviceName" dense outlined label="Устройство" />
              <q-btn color="primary" type="submit" :loading="store.scanning" :disable="!token.trim()">Сканировать</q-btn>
            </div>
          </q-form>
        </AppCard>

        <AppCard :class="['access-gate-result', store.lastEvent ? statusPanelClass : 'access-gate-result--idle']">
          <template v-if="store.lastEvent">
            <div class="access-gate-result__status">
              <component :is="resultIcon" :size="54" />
              <div>
                <strong>{{ resultLabel(store.lastEvent.result) }}</strong>
                <span>{{ store.lastEvent.reason || 'Проход зарегистрирован.' }}</span>
              </div>
            </div>
            <div class="access-gate-person">
              <div class="access-gate-person__photo"><UserRound :size="58" /></div>
              <div class="access-gate-person__info">
                <h2>{{ ownerName(store.lastEvent) }}</h2>
                <p>{{ entityTypeLabel(store.lastEvent.entity_type) }}</p>
                <div class="access-gate-person__badges">
                  <AppStatusBadge :label="directionLabel(store.lastEvent.direction)" :tone="store.lastEvent.direction === 'in' ? 'success' : 'warning'" />
                  <AppStatusBadge :label="resultLabel(store.lastEvent.result)" :tone="resultTone(store.lastEvent.result)" />
                </div>
              </div>
            </div>
            <dl class="access-gate-result__details">
              <div><dt>Время</dt><dd>{{ formatEventTime(store.lastEvent.event_time) }}</dd></div>
              <div><dt>Направление</dt><dd><component :is="directionIcon" :size="15" /> {{ directionLabel(store.lastEvent.direction) }}</dd></div>
              <div><dt>Точка доступа</dt><dd>{{ store.lastEvent.access_point || '—' }}</dd></div>
              <div><dt>Устройство</dt><dd>{{ store.lastEvent.device_name || '—' }}</dd></div>
            </dl>
          </template>
          <div v-else class="access-gate-result__empty">
            <ScanLine :size="56" />
            <strong>Ожидание сканирования</strong>
            <span>После сканирования здесь появится результат прохода.</span>
          </div>
        </AppCard>
      </section>

      <aside class="access-gate-side">
        <AppCard class="access-gate-events-card">
          <div class="access-gate-events-card__header">
            <h2>Последние события</h2>
            <div><span class="access-gate-counter access-gate-counter--allowed">{{ store.allowedCount }}</span><span class="access-gate-counter access-gate-counter--denied">{{ store.deniedCount }}</span></div>
          </div>
          <div class="access-gate-events-list">
            <article v-for="event in store.events" :key="event.id" class="access-gate-event">
              <div class="access-gate-event__time">{{ formatEventTime(event.event_time) }}</div>
              <div class="access-gate-event__body">
                <strong>{{ ownerName(event) }}</strong>
                <span>{{ entityTypeLabel(event.entity_type) }} · {{ directionLabel(event.direction) }}</span>
              </div>
              <AppStatusBadge :label="resultLabel(event.result)" :tone="resultTone(event.result)" />
            </article>
            <div v-if="!store.events.length && !store.loading" class="access-gate-events-empty">Событий пока нет.</div>
          </div>
        </AppCard>
      </aside>
    </div>
  </AppPage>
</template>
