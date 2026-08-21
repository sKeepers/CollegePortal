<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useQuasar } from 'quasar'
import { CreditCard, Plus, RefreshCw } from '@lucide/vue'
import AppPage from '../../components/ui/AppPage.vue'
import PageHeader from '../../components/ui/PageHeader.vue'
import AppToolbar from '../../components/ui/AppToolbar.vue'
import AppTable from '../../components/ui/AppTable.vue'
import AppEmptyState from '../../components/ui/AppEmptyState.vue'
import AppLoading from '../../components/ui/AppLoading.vue'
import AppErrorBanner from '../../components/ui/AppErrorBanner.vue'
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'
import { useRfidCardsStore } from '../../stores/rfidCards'
import { usePermissions } from '../../composables/usePermissions'

/**
 * Кабинет коменданта: учёт RFID-карт.
 *
 * Экран заменяет тетрадь. Выдача и приём сделаны отдельными кнопками, а не
 * правкой поля: портал записывает, кому и когда, — иначе учёт ничем не
 * отличается от списка.
 */
const store = useRfidCardsStore()
const permissions = usePermissions()
const canManage = computed(() => permissions.hasPermission('rfid.cards.manage'))
const $q = useQuasar()

const createVisible = ref(false)
const issueVisible = ref(false)
const createForm = reactive({ uid: '', label: '', note: '' })
const issueForm = reactive({ card: null, person_id: '', note: '' })

const columns = [
  { name: 'uid', label: 'Номер карты', field: 'uid', align: 'left', sortable: true },
  { name: 'label', label: 'Подпись', field: 'label', align: 'left' },
  { name: 'status', label: 'Состояние', field: 'status', align: 'left', sortable: true },
  { name: 'person', label: 'У кого', field: 'person', align: 'left' },
  { name: 'issued_at', label: 'Выдана', field: 'issued_at', align: 'left', sortable: true },
  { name: 'actions', label: '', field: 'actions', align: 'right' },
]

const tone = (status) => ({
  issued: 'success',
  stock: 'neutral',
  lost: 'danger',
  blocked: 'warning',
  written_off: 'neutral',
}[status] || 'neutral')

function notify(message, type = 'positive') {
  $q.notify({ type, message, position: 'top-right', timeout: 2500 })
}

function openCreate() {
  createForm.uid = ''
  createForm.label = ''
  createForm.note = ''
  createVisible.value = true
}

async function submitCreate() {
  if (!createForm.uid.trim()) return
  if (await store.create({ uid: createForm.uid.trim(), label: createForm.label || null, note: createForm.note || null })) {
    createVisible.value = false
    notify('Карта заведена')
  }
}

function openIssue(card) {
  issueForm.card = card
  issueForm.person_id = ''
  issueForm.note = ''
  issueVisible.value = true
}

async function submitIssue() {
  if (!issueForm.person_id) return
  if (await store.issue(issueForm.card, issueForm.person_id, issueForm.note)) {
    issueVisible.value = false
    notify('Карта выдана')
  }
}

async function accept(card) {
  if (await store.accept(card)) notify(`Карта ${card.uid} принята`)
}

async function changeStatus(card, status) {
  if (await store.changeStatus(card, status)) notify(`Карта ${card.uid}: ${store.statusLabels[status]}`)
}

onMounted(store.load)
</script>

<template>
  <AppPage>
    <PageHeader title="RFID-карты" subtitle="Учёт карт: выдача, приём, блокировка">
      <template #icon><CreditCard :size="22" /></template>
    </PageHeader>

    <AppToolbar>
      <q-input
        v-model="store.filters.search"
        dense
        outlined
        clearable
        debounce="400"
        label="Номер карты или фамилия"
        style="min-width: 260px"
        @update:model-value="store.load"
      />
      <q-select
        v-model="store.filters.status"
        dense
        outlined
        clearable
        emit-value
        map-options
        label="Состояние"
        style="min-width: 200px"
        :options="store.statusOptions"
        @update:model-value="store.load"
      />
      <q-space />
      <q-btn flat :disable="store.loading" @click="store.load">
        <RefreshCw :size="16" class="q-mr-xs" /> Обновить
      </q-btn>
      <q-btn v-if="canManage" color="primary" unelevated no-caps @click="openCreate">
        <Plus :size="16" class="q-mr-xs" /> Завести карту
      </q-btn>
    </AppToolbar>

    <AppErrorBanner v-if="store.error" :message="store.error" />

    <div class="rfid-counters">
      <div v-for="option in store.statusOptions" :key="option.value" class="rfid-counter">
        <span class="rfid-counter__value">{{ store.counts[option.value] || 0 }}</span>
        <span class="rfid-counter__label">{{ option.label }}</span>
      </div>
    </div>

    <AppLoading v-if="store.loading" />

    <AppEmptyState
      v-else-if="!store.cards.length"
      title="Карт нет"
      description="Заведите первую карту — дальше её можно выдать человеку, принять обратно или заблокировать."
    />

    <AppTable v-else :rows="store.cards" :columns="columns" row-key="id" :pagination="{ rowsPerPage: 25 }">
      <template #body-cell-status="props">
        <q-td :props="props">
          <AppStatusBadge :label="props.row.status_label" :tone="tone(props.row.status)" />
        </q-td>
      </template>
      <template #body-cell-person="props">
        <q-td :props="props">{{ props.row.person?.full_name || '—' }}</q-td>
      </template>
      <template #body-cell-actions="props">
        <q-td :props="props" class="rfid-actions">
          <template v-if="canManage">
            <q-btn v-if="props.row.status !== 'issued'" flat dense no-caps color="primary" :disable="store.saving" @click="openIssue(props.row)">Выдать</q-btn>
            <q-btn v-else flat dense no-caps color="primary" :disable="store.saving" @click="accept(props.row)">Принять</q-btn>
            <q-btn flat dense no-caps color="negative" :disable="store.saving" @click="changeStatus(props.row, 'lost')">Утеряна</q-btn>
            <q-btn flat dense no-caps :disable="store.saving" @click="changeStatus(props.row, 'blocked')">Заблокировать</q-btn>
            <q-btn v-if="props.row.status !== 'stock'" flat dense no-caps :disable="store.saving" @click="changeStatus(props.row, 'stock')">В оборот</q-btn>
          </template>
        </q-td>
      </template>
    </AppTable>

    <q-dialog v-model="createVisible">
      <q-card class="rfid-dialog">
        <q-card-section class="text-h6">Завести карту</q-card-section>
        <q-card-section class="column q-gutter-sm">
          <q-input v-model="createForm.uid" dense outlined autofocus label="Номер карты" hint="То, что читает считыватель" />
          <q-input v-model="createForm.label" dense outlined label="Подпись" hint="Что написано на самой карте" />
          <q-input v-model="createForm.note" dense outlined type="textarea" autogrow label="Примечание" />
        </q-card-section>
        <q-card-actions align="right">
          <q-btn flat no-caps label="Отмена" v-close-popup />
          <q-btn color="primary" no-caps label="Завести" :loading="store.saving" :disable="!createForm.uid.trim()" @click="submitCreate" />
        </q-card-actions>
      </q-card>
    </q-dialog>

    <q-dialog v-model="issueVisible">
      <q-card class="rfid-dialog">
        <q-card-section class="text-h6">Выдать карту {{ issueForm.card?.uid }}</q-card-section>
        <q-card-section class="column q-gutter-sm">
          <q-select
            v-model="issueForm.person_id"
            dense
            outlined
            use-input
            emit-value
            map-options
            input-debounce="200"
            label="Кому"
            :options="store.peopleOptions"
          />
          <q-input v-model="issueForm.note" dense outlined type="textarea" autogrow label="Примечание" />
        </q-card-section>
        <q-card-actions align="right">
          <q-btn flat no-caps label="Отмена" v-close-popup />
          <q-btn color="primary" no-caps label="Выдать" :loading="store.saving" :disable="!issueForm.person_id" @click="submitIssue" />
        </q-card-actions>
      </q-card>
    </q-dialog>
  </AppPage>
</template>

<style scoped>
.rfid-counters { display: flex; gap: 12px; flex-wrap: wrap; margin: 12px 0; }
.rfid-counter { display: grid; gap: 2px; padding: 8px 14px; border: 1px solid #e2e8f0; border-radius: 10px; min-width: 120px; }
.rfid-counter__value { font-size: 20px; font-weight: 600; color: #0f172a; }
.rfid-counter__label { font-size: 12px; color: #64748b; }
.rfid-actions { white-space: nowrap; }
.rfid-dialog { min-width: min(520px, 92vw); }
</style>
