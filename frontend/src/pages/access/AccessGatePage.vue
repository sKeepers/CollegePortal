<script setup>
import { computed, nextTick, onMounted, ref } from 'vue'
import { LogIn, LogOut, RefreshCw, ScanLine, UserRound, XCircle } from '@lucide/vue'
import AppPage from '../../components/ui/AppPage.vue'
import PageHeader from '../../components/ui/PageHeader.vue'
import AppToolbar from '../../components/ui/AppToolbar.vue'
import AppCard from '../../components/ui/AppCard.vue'
import AppErrorBanner from '../../components/ui/AppErrorBanner.vue'
import AppLoading from '../../components/ui/AppLoading.vue'
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'
import { directionLabel, entityTypeLabel, formatEventTime, normalizeQrToken, outcomeDetail, outcomeHeadline, ownerName, resultLabel, resultTone, useAccessGateStore } from '../../stores/accessGate'

const store = useAccessGateStore()
const scanInputRef = ref(null)
const token = ref('')
const accessPoint = ref('Главный вход')
const deviceName = ref('HID QR Scanner')
const diagnosticsMode = ref(false)
const submitOnTab = ref(false)
const keyTimings = ref([])
const lastKeyAt = ref(null)
const diagnostic = ref({ raw: '', normalized: '', length: 0, first: '—', last: '—', hasCr: false, hasLf: false, hasTab: false, hadEnter: false, intervals: [] })
const statusPanelClass = computed(() => store.lastEvent?.result === 'allowed' ? 'access-gate-result--allowed' : 'access-gate-result--denied')
const directionIcon = computed(() => store.lastEvent?.direction === 'out' ? LogOut : LogIn)
// У разрешённого прохода на видном месте стрелка направления, а не галочка:
// оператор читает «зашёл» или «вышел», а не «событие сохранилось».
const resultIcon = computed(() => store.lastEvent?.result === 'allowed' ? directionIcon.value : XCircle)

async function focusScanner() {
  await nextTick()
  scanInputRef.value?.focus?.()
}

function describeChar(char) {
  if (!char) return '—'
  const code = char.charCodeAt(0)
  if (char === '\r') return 'CR (13)'
  if (char === '\n') return 'LF (10)'
  if (char === '\t') return 'Tab (9)'
  return `${char} (${code})`
}

function updateDiagnostic(raw, hadEnter = false) {
  const value = String(raw || '')
  diagnostic.value = {
    raw: value,
    normalized: normalizeQrToken(value),
    length: value.length,
    first: describeChar(value[0]),
    last: describeChar(value[value.length - 1]),
    hasCr: value.includes('\r'),
    hasLf: value.includes('\n'),
    hasTab: value.includes('\t'),
    hadEnter,
    intervals: keyTimings.value.slice(-12),
  }
}

function recordScanKey(event) {
  if (!diagnosticsMode.value) return
  const now = performance.now()
  if (lastKeyAt.value !== null) keyTimings.value.push(Math.round(now - lastKeyAt.value))
  lastKeyAt.value = now
  if (event.key === 'Enter' || event.key === 'NumpadEnter') updateDiagnostic(token.value, true)
  if (event.key === 'Tab') {
    updateDiagnostic(token.value + '\t', false)
    if (submitOnTab.value) {
      event.preventDefault()
      submitScan(true)
    }
  }
}

async function submitScan(fromTab = false) {
  const value = token.value
  updateDiagnostic(value, !fromTab)
  token.value = ''
  keyTimings.value = []
  lastKeyAt.value = null
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
    <q-banner v-if="store.warning" rounded class="access-gate-warning">{{ store.warning }}</q-banner>

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
            <q-input ref="scanInputRef" v-model="token" outlined autofocus input-class="access-gate-scan-input" label="Поле сканирования" placeholder="Ожидание QR-сканера..." :disable="store.scanning" @keydown="recordScanKey">
              <template #prepend><ScanLine :size="24" /></template>
            </q-input>
            <div class="access-gate-scan-form__meta">
              <q-input v-model="accessPoint" dense outlined label="Точка доступа" />
              <q-input v-model="deviceName" dense outlined label="Устройство" />
              <q-btn color="primary" type="submit" :loading="store.scanning" :disable="!token.trim()">Сканировать</q-btn>
            </div>
            <div class="access-gate-diagnostics-toggle">
              <q-toggle v-model="diagnosticsMode" label="Диагностика сканера" />
              <q-checkbox v-model="submitOnTab" label="Tab завершает скан" :disable="!diagnosticsMode" />
            </div>
          </q-form>
        </AppCard>

        <AppCard v-if="diagnosticsMode" class="access-gate-diagnostics-card">
          <template #default>
            <h3>Диагностика сканера</h3>
            <dl class="access-gate-diagnostics-grid">
              <div><dt>Сырое значение</dt><dd>{{ diagnostic.raw || '—' }}</dd></div>
              <div><dt>После нормализации</dt><dd>{{ diagnostic.normalized || '—' }}</dd></div>
              <div><dt>Длина строки</dt><dd>{{ diagnostic.length }}</dd></div>
              <div><dt>Первый символ</dt><dd>{{ diagnostic.first }}</dd></div>
              <div><dt>Последний символ</dt><dd>{{ diagnostic.last }}</dd></div>
              <div><dt>CR</dt><dd>{{ diagnostic.hasCr ? 'Да' : 'Нет' }}</dd></div>
              <div><dt>LF</dt><dd>{{ diagnostic.hasLf ? 'Да' : 'Нет' }}</dd></div>
              <div><dt>Tab</dt><dd>{{ diagnostic.hasTab ? 'Да' : 'Нет' }}</dd></div>
              <div><dt>Enter</dt><dd>{{ diagnostic.hadEnter ? 'Да' : 'Нет' }}</dd></div>
              <div><dt>Интервалы, мс</dt><dd>{{ diagnostic.intervals.length ? diagnostic.intervals.join(', ') : '—' }}</dd></div>
            </dl>
          </template>
        </AppCard>

        <AppCard :class="['access-gate-result', store.lastEvent ? statusPanelClass : 'access-gate-result--idle']">
          <template v-if="store.lastEvent">
            <div class="access-gate-result__status">
              <component :is="resultIcon" :size="54" />
              <div>
                <strong>{{ outcomeHeadline(store.lastEvent) }}</strong>
                <span>{{ outcomeDetail(store.lastEvent) }}</span>
              </div>
            </div>
            <div class="access-gate-person">
              <div class="access-gate-person__photo"><img v-if="store.lastEvent.owner?.photo_url" :src="store.lastEvent.owner.photo_url" alt="Фото" /><UserRound v-else :size="58" /></div>
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
