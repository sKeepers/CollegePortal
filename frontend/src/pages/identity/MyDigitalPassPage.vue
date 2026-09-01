<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { Maximize2, RefreshCw } from '@lucide/vue'
import AppPage from '../../components/ui/AppPage.vue'
import PageHeader from '../../components/ui/PageHeader.vue'
import AppToolbar from '../../components/ui/AppToolbar.vue'
import AppEmptyState from '../../components/ui/AppEmptyState.vue'
import AppErrorBanner from '../../components/ui/AppErrorBanner.vue'
import AppLoading from '../../components/ui/AppLoading.vue'
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'
import WorkspacePanel from '../../components/workspace/WorkspacePanel.vue'
import { useDigitalPassesStore, entityTypeLabel, formatDateTime, ownerName, statusLabel, statusTone } from '../../stores/digitalPasses'
import { api } from '../../services/api'

const store = useDigitalPassesStore()

/**
 * Свои карты — здесь же, а не отдельным экраном.
 *
 * Решение владельца 01.09.2026: сотруднику нужен только QR-пропуск и карта. Эта
 * страница и есть «моё удостоверение», и заводить ради одной строки второй
 * пункт меню роли, у которой пунктов всего два, значило бы отдать под него треть
 * меню.
 *
 * Карт может быть **несколько**: с 30.08.2026 у человека законно бывает не одна.
 * Поэтому список, а не одна строка.
 */
const cards = ref([])
const cardsFailed = ref(false)

async function loadCards() {
  try {
    const payload = await api.list('rfid-cards/mine')
    cards.value = Array.isArray(payload?.data) ? payload.data : []
    cardsFailed.value = false
  } catch {
    // Карта — не главное на этой странице: пропуск важнее, и падать из-за карт
    // страница не должна. Но и молчать нельзя — скажем, что не спросили.
    cards.value = []
    cardsFailed.value = true
  }
}
const qrDialogVisible = ref(false)
let refreshTimer = null
let clockTimer = null
const now = ref(Date.now())

const pass = computed(() => store.selectedIdentity || store.identities[0] || null)
const passMetrics = computed(() => [
  { label: 'Владелец', value: ownerName(pass.value) },
  { label: 'Тип', value: entityTypeLabel(pass.value?.entity_type) },
  { label: 'Статус', value: statusLabel(pass.value?.status) },
  { label: 'Выдан', value: formatDateTime(pass.value?.issued_at) },
  { label: 'Срок действия', value: formatDateTime(pass.value?.expires_at) },
])
const qrSecondsLeft = computed(() => {
  const expires = new Date(store.qrExpiresAt || '').getTime()
  return Number.isNaN(expires) ? 0 : Math.max(0, Math.ceil((expires - now.value) / 1000))
})

async function loadPass() {
  await loadCards()
  await store.load({ mine: true, includeOwners: false })
  if (store.identities[0]) {
    await store.select(store.identities[0])
  }
}

onMounted(async () => {
  await loadPass()
  refreshTimer = window.setInterval(loadPass, 27_000)
  clockTimer = window.setInterval(() => { now.value = Date.now() }, 1_000)
})

onBeforeUnmount(() => {
  if (refreshTimer) window.clearInterval(refreshTimer)
  if (clockTimer) window.clearInterval(clockTimer)
})
</script>

<template>
  <AppPage class="my-digital-pass-page">
    <PageHeader
      title="Мой QR-пропуск"
      subtitle="Личный цифровой пропуск. Код обновляется каждые 30 секунд и принимается проходной один раз."
    />

    <AppToolbar>
      <span>{{ pass ? 'Активный пропуск найден' : 'Активный пропуск не найден' }}</span>
      <template #actions>
        <AppLoading v-if="store.loading" label="Загрузка пропуска..." />
        <q-btn flat :disable="store.loading" @click="loadPass">
          <RefreshCw :size="16" class="q-mr-xs" />
          <span>Обновить</span>
        </q-btn>
      </template>
    </AppToolbar>

    <AppErrorBanner :message="store.error" />

    <WorkspacePanel
      v-if="pass"
      class="my-digital-pass-card"
      :title="ownerName(pass)"
      :subtitle="entityTypeLabel(pass.entity_type)"
      :metrics="passMetrics"
    >
      <template #status>
        <AppStatusBadge :label="statusLabel(pass.status)" :tone="statusTone(pass.status)" />
      </template>

      <div class="my-digital-pass">
        <div class="my-digital-pass__qr-shell">
          <div class="my-digital-pass__qr" v-html="store.qrSvg" />
          <strong class="my-digital-pass__countdown">Код обновится через {{ qrSecondsLeft }} сек.</strong>
        </div>
        <div class="my-digital-pass__actions">
          <q-btn outline no-caps :disable="store.loading" @click="loadPass">
            <RefreshCw :size="16" class="q-mr-xs" />
            <span>Обновить код</span>
          </q-btn>
          <q-btn outline no-caps @click="qrDialogVisible = true">
            <Maximize2 :size="16" class="q-mr-xs" />
            <span>Открыть крупно</span>
          </q-btn>
        </div>
        <p class="my-digital-pass__notice">
          Код обновляется автоматически. Скриншот или ранее использованный код проходная отклонит.
        </p>
      </div>
    </WorkspacePanel>

    <AppEmptyState
      v-else-if="!store.loading"
      title="Активный QR-пропуск не найден"
      description="Обратитесь к администратору или сотруднику проходной для выпуска цифрового пропуска."
    />

    <!--
      Карта показывается **всегда**, а не только при живом пропуске: у человека
      может быть карта и не быть пропуска, и наоборот. Связывать их показ значило
      бы прятать одно за отсутствием другого.
    -->
    <WorkspacePanel class="my-digital-pass-card" title="Моя карта" subtitle="Номер, по которому вас узнаёт проходная">
      <div v-if="cards.length" class="my-card-list">
        <div v-for="card in cards" :key="card.uid" class="my-card-row">
          <strong class="my-card-row__uid">{{ card.uid }}</strong>
          <AppStatusBadge :label="card.status_label" :tone="card.status === 'issued' ? 'success' : 'warning'" />
          <span v-if="card.issued_at" class="my-card-row__issued">выдана {{ formatDateTime(card.issued_at) }}</span>
        </div>
      </div>
      <p v-else-if="cardsFailed" class="my-card-empty">
        Не удалось спросить о карте. Обновите страницу; если повторится — скажите администратору.
      </p>
      <p v-else class="my-card-empty">
        Карта вам не выдана. За картой обращаются к коменданту — на проходной она нужна вместо QR-кода,
        когда телефона нет под рукой.
      </p>
    </WorkspacePanel>

    <q-dialog v-model="qrDialogVisible">
      <q-card class="my-digital-pass-dialog">
        <q-card-section>
          <div class="text-h6">Мой QR-пропуск</div>
        </q-card-section>
        <q-card-section>
          <div class="my-digital-pass__large-qr" v-html="store.qrSvg" />
        </q-card-section>
        <q-card-actions align="right">
          <q-btn flat label="Закрыть" @click="qrDialogVisible = false" />
        </q-card-actions>
      </q-card>
    </q-dialog>
  </AppPage>
</template>

<style scoped>
.my-digital-pass-card {
  max-width: 760px;
}

.my-card-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

/* Номер и признак в строку, но на узком экране — столбиком: сотрудник придёт к
   турникету с телефоном, и номер не должен уезжать за край. */
.my-card-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}

.my-card-row__uid {
  font-size: 20px;
  letter-spacing: 1px;
}

.my-card-row__issued,
.my-card-empty {
  color: var(--text-secondary, #6b7280);
}

.my-digital-pass {
  display: grid;
  gap: 18px;
}

.my-digital-pass__qr-shell {
  display: grid;
  place-items: center;
  padding: 20px;
  border: 1px solid var(--cp-border);
  border-radius: 8px;
  background: #fff;
}

.my-digital-pass__qr,
.my-digital-pass__large-qr {
  display: grid;
  place-items: center;
}

.my-digital-pass__qr :deep(svg) {
  width: min(100%, 360px);
  height: auto;
}

.my-digital-pass__large-qr :deep(svg) {
  width: min(76vw, 560px);
  height: auto;
}

.my-digital-pass__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

.my-digital-pass__notice {
  margin: 0;
  color: var(--cp-muted);
  line-height: 1.55;
}

.my-digital-pass__countdown {
  color: #1d4ed8;
  font-size: 14px;
}

.my-digital-pass-dialog {
  width: min(680px, 94vw);
}

@media (max-width: 520px) {
  .my-digital-pass-page :deep(.page-header),
  .my-digital-pass-page :deep(.app-toolbar),
  .my-digital-pass-card :deep(.workspace-panel__metrics) {
    display: none;
  }

  .my-digital-pass-card :deep(.workspace-panel__hero) {
    margin-bottom: 10px;
  }

  .my-digital-pass__qr-shell {
    padding: 10px;
  }

  .my-digital-pass__qr :deep(svg) {
    width: min(100%, 290px);
  }
}
</style>
