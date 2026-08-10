<script setup>
import { computed, onMounted, ref } from 'vue'
import { useQuasar } from 'quasar'
import { RotateCcw, Trash2 } from '@lucide/vue'
import AppPage from '../../components/ui/AppPage.vue'
import PageHeader from '../../components/ui/PageHeader.vue'
import AppCard from '../../components/ui/AppCard.vue'
import AppErrorBanner from '../../components/ui/AppErrorBanner.vue'
import AppConfirmDialog from '../../components/ui/AppConfirmDialog.vue'
import AppEmptyState from '../../components/ui/AppEmptyState.vue'
import AppLoading from '../../components/ui/AppLoading.vue'
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'
import { useTrashStore } from '../../stores/trash'

/**
 * Корзина и заявки на удаление.
 *
 * Три части, в порядке срочности: что ждёт решения, что лежит в корзине и что
 * уже решено. Очистка корзины стоит отдельно и подтверждается — после неё
 * возврата нет.
 */
const store = useTrashStore()
const $q = useQuasar()

const tab = ref('pending')
const purgeDialog = ref(false)
const purging = ref(null)
const rejecting = ref(null)
const rejectDialog = ref(false)
const rejectComment = ref('')

const decided = computed(() => store.history.filter((item) => item.status !== 'pending'))

const STATUS_TONES = {
  pending: { label: 'Ждёт решения', tone: 'warning' },
  approved: { label: 'Удалено', tone: 'danger' },
  rejected: { label: 'Отклонено', tone: 'neutral' },
}

function statusLabel(status) { return STATUS_TONES[status]?.label || status }
function statusTone(status) { return STATUS_TONES[status]?.tone || 'neutral' }

function formatDate(value) {
  if (!value) return '—'
  return new Intl.DateTimeFormat('ru-RU', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value))
}

function notify(message, type = 'positive') {
  $q.notify({ type, message, position: 'top-right', timeout: 2000 })
}

async function approve(request) {
  try {
    await store.approve(request)
    notify('Карточка удалена и перенесена в корзину')
  } catch { /* сообщение уже в store.error */ }
}

function askReject(request) {
  rejecting.value = request
  rejectComment.value = ''
  rejectDialog.value = true
}

async function confirmReject() {
  if (!rejecting.value) return
  try {
    await store.reject(rejecting.value, rejectComment.value)
    notify('Заявка отклонена')
  } catch { /* сообщение уже в store.error */ } finally {
    rejectDialog.value = false
    rejecting.value = null
  }
}

async function restore(item) {
  try {
    await store.restore(item)
    notify('Карточка возвращена')
  } catch { /* сообщение уже в store.error */ }
}

function askPurge(item) {
  purging.value = item
  purgeDialog.value = true
}

async function confirmPurge() {
  if (!purging.value) return
  try {
    await store.purge(purging.value)
    notify('Карточка удалена окончательно')
  } catch { /* сообщение уже в store.error */ } finally {
    purging.value = null
  }
}

onMounted(() => store.loadAll())
</script>

<template>
  <AppPage>
    <PageHeader
      title="Корзина"
      subtitle="Удаление в два шага: карточку помечает тот, кто её ведёт, удаляет администратор. Удалённое лежит здесь, пока корзину не очистят."
    >
      <template #actions>
        <q-btn flat no-caps :disable="store.loading" label="Обновить" @click="store.loadAll()" />
      </template>
    </PageHeader>

    <AppErrorBanner v-if="store.error" :message="store.error" />
    <AppLoading v-if="store.loading" />

    <q-tabs v-model="tab" align="left" no-caps class="trash-page__tabs">
      <q-tab name="pending" :label="`Ждут решения (${store.pending.length})`" />
      <q-tab name="trash" :label="`В корзине (${store.items.length})`" />
      <q-tab name="decided" :label="`Решённые (${decided.length})`" />
    </q-tabs>

    <q-tab-panels v-model="tab" animated class="trash-page__panels">
      <q-tab-panel name="pending" class="trash-page__panel">
        <AppEmptyState
          v-if="!store.pending.length"
          title="Заявок нет"
          description="Никто не просил удалить карточку."
        />
        <AppCard v-for="request in store.pending" :key="request.id" class="trash-page__request">
          <div class="trash-page__row">
            <div>
              <div class="trash-page__subject">
                {{ store.subjectLabel(request.subject_type) }} · {{ request.subject_label || `#${request.subject_id}` }}
              </div>
              <p class="trash-page__reason">{{ request.reason }}</p>
              <div class="trash-page__meta">
                Заявку оставил: {{ request.requested_by || 'неизвестно' }} · {{ formatDate(request.created_at) }}
                <template v-if="!request.subject_exists"> · карточки уже нет</template>
              </div>
            </div>
            <div class="trash-page__actions">
              <q-btn outline no-caps color="dark" label="Отклонить" @click="askReject(request)" />
              <q-btn no-caps color="negative" label="Удалить карточку" @click="approve(request)" />
            </div>
          </div>
        </AppCard>
      </q-tab-panel>

      <q-tab-panel name="trash" class="trash-page__panel">
        <AppEmptyState
          v-if="!store.items.length"
          title="Корзина пуста"
          description="Удалённых карточек нет."
        />
        <AppCard v-for="item in store.items" :key="`${item.type}-${item.id}`" class="trash-page__request">
          <div class="trash-page__row">
            <div>
              <div class="trash-page__subject">
                {{ store.subjectLabel(item.type) }} · {{ item.label }}
              </div>
              <p v-if="item.reason" class="trash-page__reason">{{ item.reason }}</p>
              <div class="trash-page__meta">
                Удалено: {{ formatDate(item.deleted_at) }}
                <template v-if="item.reviewed_by"> · решение: {{ item.reviewed_by }}</template>
                <template v-if="item.requested_by"> · заявка: {{ item.requested_by }}</template>
              </div>
            </div>
            <div class="trash-page__actions">
              <q-btn outline no-caps color="primary" @click="restore(item)">
                <RotateCcw :size="16" /><span class="q-ml-xs">Вернуть</span>
              </q-btn>
              <q-btn outline no-caps color="negative" @click="askPurge(item)">
                <Trash2 :size="16" /><span class="q-ml-xs">Очистить</span>
              </q-btn>
            </div>
          </div>
        </AppCard>
      </q-tab-panel>

      <q-tab-panel name="decided" class="trash-page__panel">
        <AppEmptyState
          v-if="!decided.length"
          title="Решений пока нет"
          description="Здесь появятся рассмотренные заявки."
        />
        <AppCard v-for="request in decided" :key="request.id" class="trash-page__request">
          <div class="trash-page__row">
            <div>
              <div class="trash-page__subject">
                {{ store.subjectLabel(request.subject_type) }} · {{ request.subject_label || `#${request.subject_id}` }}
                <AppStatusBadge :label="statusLabel(request.status)" :tone="statusTone(request.status)" />
              </div>
              <p class="trash-page__reason">{{ request.reason }}</p>
              <div class="trash-page__meta">
                {{ request.requested_by || 'неизвестно' }} → {{ request.reviewed_by || '—' }} · {{ formatDate(request.reviewed_at) }}
                <template v-if="request.review_comment"> · {{ request.review_comment }}</template>
              </div>
            </div>
          </div>
        </AppCard>
      </q-tab-panel>
    </q-tab-panels>

    <q-dialog v-model="rejectDialog">
      <q-card class="trash-page__dialog">
        <q-card-section>
          <div class="text-h6">Отклонить заявку</div>
          <p v-if="rejecting" class="trash-page__subject">{{ rejecting.subject_label }}</p>
        </q-card-section>
        <q-card-section>
          <q-input
            v-model="rejectComment"
            type="textarea"
            autogrow
            outlined
            autofocus
            label="Комментарий"
            hint="Необязателен, но объясняет автору заявки, почему карточка осталась"
            maxlength="1000"
          />
        </q-card-section>
        <q-card-actions align="right">
          <q-btn flat no-caps label="Отмена" v-close-popup />
          <q-btn color="dark" no-caps label="Отклонить" @click="confirmReject" />
        </q-card-actions>
      </q-card>
    </q-dialog>

    <AppConfirmDialog
      v-model="purgeDialog"
      title="Очистить карточку окончательно?"
      :message="purging ? `${store.subjectLabel(purging.type)} · ${purging.label}. После очистки вернуть карточку будет нельзя.` : ''"
      confirm-label="Очистить"
      @confirm="confirmPurge"
    />
  </AppPage>
</template>

<style scoped>
.trash-page__tabs {
  margin-top: 0.5rem;
}

.trash-page__panels {
  background: transparent;
}

.trash-page__panel {
  padding: 0.75rem 0 0;
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.trash-page__row {
  display: flex;
  gap: 1rem;
  justify-content: space-between;
  align-items: flex-start;
  flex-wrap: wrap;
}

.trash-page__subject {
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 0.5rem;
  flex-wrap: wrap;
}

.trash-page__reason {
  margin: 0.35rem 0 0;
}

.trash-page__meta {
  margin-top: 0.35rem;
  font-size: 0.8125rem;
  color: var(--cp-text-muted, #6b7280);
}

.trash-page__actions {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
}

.trash-page__dialog {
  min-width: min(520px, 92vw);
}
</style>
