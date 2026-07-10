<script setup>
import { computed, onMounted, ref } from 'vue'
import { CheckCircle2, RefreshCw, ScanLine, XCircle } from '@lucide/vue'
import AppPage from '../../components/ui/AppPage.vue'
import PageHeader from '../../components/ui/PageHeader.vue'
import AppCard from '../../components/ui/AppCard.vue'
import AppErrorBanner from '../../components/ui/AppErrorBanner.vue'
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'
import { useDigitalPassesStore, ownerName } from '../../stores/digitalPasses'
import { normalizeQrToken } from '../../stores/accessGate'

const store = useDigitalPassesStore()
const rawValue = ref('')
const diagnostics = ref({ raw: '', normalized: '', length: 0, first: '—', last: '—', hasCr: false, hasLf: false, hasTab: false, hadEnter: false })
const selectedIdentity = computed(() => store.selectedIdentity || store.identities[0] || null)
const expectedValue = computed(() => selectedIdentity.value?.token || '')
const normalizedValue = computed(() => normalizeQrToken(rawValue.value))
const matches = computed(() => Boolean(expectedValue.value) && normalizedValue.value === expectedValue.value)
const resultIcon = computed(() => matches.value ? CheckCircle2 : XCircle)

function describeChar(char) {
  if (!char) return '—'
  const code = char.charCodeAt(0)
  if (char === '\r') return 'CR (13)'
  if (char === '\n') return 'LF (10)'
  if (char === '\t') return 'Tab (9)'
  return `${char} (${code})`
}

function updateDiagnostics(hadEnter = false) {
  const value = String(rawValue.value || '')
  diagnostics.value = {
    raw: value,
    normalized: normalizeQrToken(value),
    length: value.length,
    first: describeChar(value[0]),
    last: describeChar(value[value.length - 1]),
    hasCr: value.includes('\r'),
    hasLf: value.includes('\n'),
    hasTab: value.includes('\t'),
    hadEnter,
  }
}

async function reload() {
  await store.load()
  if (store.identities[0]) await store.select(store.identities[0])
}

onMounted(reload)
</script>

<template>
  <AppPage>
    <PageHeader title="Тест QR-сканера" subtitle="DEV-страница для проверки физического USB HID-сканера.">
      <template #actions>
        <q-btn flat :loading="store.loading" @click="reload"><RefreshCw :size="16" class="q-mr-xs" /> Обновить</q-btn>
      </template>
    </PageHeader>

    <AppErrorBanner :message="store.error" />

    <div class="scanner-test-layout">
      <AppCard title="Эталонный QR" subtitle="Отсканируйте этот код физическим сканером">
        <div v-if="selectedIdentity" class="scanner-test-qr-shell">
          <div class="scanner-test-qr" v-html="store.qrSvg" />
          <dl class="scanner-test-list">
            <div><dt>Владелец</dt><dd>{{ ownerName(selectedIdentity) }}</dd></div>
            <div><dt>Ожидаемое значение</dt><dd>{{ expectedValue }}</dd></div>
            <div><dt>Допустимый формат</dt><dd>token или CP1:&lt;token&gt;</dd></div>
          </dl>
        </div>
        <div v-else class="scanner-test-empty">Сначала выпустите хотя бы один цифровой пропуск.</div>
      </AppCard>

      <AppCard title="Проверка считанного значения" subtitle="Поле принимает ввод от HID-сканера как от клавиатуры">
        <q-input
          v-model="rawValue"
          outlined
          autofocus
          label="Считанное значение"
          placeholder="Отсканируйте QR или вставьте строку"
          @keydown.enter="updateDiagnostics(true)"
          @keydown.tab="updateDiagnostics(false)"
          @update:model-value="updateDiagnostics(false)"
        >
          <template #prepend><ScanLine :size="22" /></template>
        </q-input>

        <div class="scanner-test-result" :class="matches ? 'scanner-test-result--ok' : 'scanner-test-result--bad'">
          <component :is="resultIcon" :size="28" />
          <strong>{{ matches ? 'Совпадает' : 'Не совпадает' }}</strong>
          <AppStatusBadge :label="matches ? 'OK' : 'Проверить'" :tone="matches ? 'success' : 'warning'" />
        </div>

        <dl class="scanner-test-list scanner-test-list--compact">
          <div><dt>Сырое значение</dt><dd>{{ diagnostics.raw || '—' }}</dd></div>
          <div><dt>После нормализации</dt><dd>{{ diagnostics.normalized || '—' }}</dd></div>
          <div><dt>Длина</dt><dd>{{ diagnostics.length }}</dd></div>
          <div><dt>Первый символ</dt><dd>{{ diagnostics.first }}</dd></div>
          <div><dt>Последний символ</dt><dd>{{ diagnostics.last }}</dd></div>
          <div><dt>CR/LF/Tab</dt><dd>CR: {{ diagnostics.hasCr ? 'да' : 'нет' }}, LF: {{ diagnostics.hasLf ? 'да' : 'нет' }}, Tab: {{ diagnostics.hasTab ? 'да' : 'нет' }}</dd></div>
          <div><dt>Enter</dt><dd>{{ diagnostics.hadEnter ? 'да' : 'нет' }}</dd></div>
        </dl>
      </AppCard>
    </div>
  </AppPage>
</template>
