<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useQuasar } from 'quasar'
import { BadgeCheck, Download, Eye, ExternalLink, Maximize2, Plus, RefreshCw, ShieldX } from '@lucide/vue'
import AppPage from '../../components/ui/AppPage.vue'
import PageHeader from '../../components/ui/PageHeader.vue'
import AppToolbar from '../../components/ui/AppToolbar.vue'
import AppTable from '../../components/ui/AppTable.vue'
import AppEmptyState from '../../components/ui/AppEmptyState.vue'
import AppLoading from '../../components/ui/AppLoading.vue'
import AppErrorBanner from '../../components/ui/AppErrorBanner.vue'
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'
import AppConfirmDialog from '../../components/ui/AppConfirmDialog.vue'
import WorkspaceBackBar from '../../components/workspace/WorkspaceBackBar.vue'
import WorkspacePanel from '../../components/workspace/WorkspacePanel.vue'
import { useDigitalPassesStore, ENTITY_OPTIONS, entityTypeLabel, formatDateTime, ownerName, statusLabel, statusTone } from '../../stores/digitalPasses'
import { useAuthStore } from '../../stores/auth'
import { usePermissions } from '../../composables/usePermissions'
import { TABLE_ROWS_PER_PAGE_OPTIONS, createTablePagination, persistTablePagination } from '../../services/tableSettings'

const store = useDigitalPassesStore()
const auth = useAuthStore()
const permissions = usePermissions()
const canManage = computed(() => permissions.hasPermission('digitalpasses.manage'))
const $q = useQuasar()
const rowsPerPageKey = 'collegePortal.digitalPasses.rowsPerPage'
const issueDialogVisible = ref(false)
const revokeDialogVisible = ref(false)
const qrDialogVisible = ref(false)
const revokingIdentity = ref(null)
const tablePagination = ref(createTablePagination(rowsPerPageKey, { sortBy: 'issued_at', descending: true, rowsPerPage: 20 }))
const issueForm = reactive({ entity_type: 'student', entity_id: '', expires_at: '' })
const columns = [
  { name: 'owner', label: 'Владелец', field: 'owner', align: 'left', sortable: true },
  { name: 'entity_type', label: 'Тип', field: 'entity_type', align: 'left', sortable: true },
  { name: 'status', label: 'Статус', field: 'status', align: 'left', sortable: true },
  { name: 'issued_at', label: 'Выдан', field: 'issued_at', align: 'left', sortable: true },
  { name: 'expires_at', label: 'Срок действия', field: 'expires_at', align: 'left', sortable: true },
  { name: 'actions', label: '', field: 'actions', align: 'right' },
]
const tableSubtitle = computed(() => `Найдено пропусков: ${store.identities.length}`)
const currentOwnerOptions = computed(() => store.ownerOptions[issueForm.entity_type] || [])

const passMetrics = computed(() => [
  { label: 'Владелец', value: ownerName(store.selectedIdentity) },
  { label: 'Статус', value: statusLabel(store.selectedIdentity?.status) },
  { label: 'Выдан', value: formatDateTime(store.selectedIdentity?.issued_at) },
  { label: 'Срок действия', value: formatDateTime(store.selectedIdentity?.expires_at) },
])
const accessHistoryRoute = computed(() => ({
  path: '/access/reports',
  query: {
    type: store.selectedIdentity?.entity_type,
    q: ownerName(store.selectedIdentity),
  },
}))
const ownerRoute = computed(() => {
  const identity = store.selectedIdentity
  if (!identity) return null
  if (identity.entity_type === 'student') return { path: `/students/${identity.entity_id}`,}
  if (identity.entity_type === 'teacher') return { path: `/teachers/${identity.entity_id}`,}
  return { path: `/hr/employees/${identity.entity_id}`,}
})
function notifySuccess(message) { $q.notify({ type: 'positive', message, position: 'top-right', timeout: 1800 }) }
function rowClass(row) { return Number(row.id) === Number(store.selectedId) ? 'digital-passes-row--selected' : '' }
function updateTablePagination(pagination) { tablePagination.value = pagination; persistTablePagination(rowsPerPageKey, pagination) }
async function selectIdentity(identity) { await store.select(identity) }
function openIssueDialog(entityType = 'student') { if (!canManage.value) return; Object.assign(issueForm, { entity_type: entityType, entity_id: '', expires_at: '' }); issueDialogVisible.value = true }
async function issuePass() { if (!canManage.value) return; await store.issue(issueForm); issueDialogVisible.value = false; notifySuccess('Цифровой пропуск выпущен') }
function requestRevoke(identity) { if (!canManage.value) return; revokingIdentity.value = identity; revokeDialogVisible.value = true }
async function confirmRevoke() { if (!canManage.value) return; await store.revoke(revokingIdentity.value); notifySuccess('Цифровой пропуск отозван'); revokingIdentity.value = null }
function tokenPreview(token) { return token ? `${token.slice(0, 8)}...${token.slice(-6)}` : '—' }
async function downloadQrPng() {
  const blob = await store.downloadQrPng()
  if (!blob || !store.selectedIdentity) return
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = `collegeportal-qr-${store.selectedIdentity.id}.png`
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
  notifySuccess('PNG QR-код скачан')
}
onMounted(async () => { await store.load(); if (store.identities[0]) await store.select(store.identities[0]) })
</script>

<template>
  <AppPage>
    <PageHeader title="Цифровые пропуска" subtitle="Цифровая идентификация студентов, преподавателей и сотрудников. QR-код содержит только технический токен.">
      <template #actions>
        <q-btn v-if="canManage" color="primary" @click="openIssueDialog()"><Plus :size="16" class="q-mr-xs" /><span>Выпустить пропуск</span></q-btn>
      </template>
    </PageHeader>
    <AppToolbar>
      <span>{{ tableSubtitle }}</span>
      <template #actions>
        <AppLoading v-if="store.loading" label="Загрузка пропусков..." />
        <q-btn flat @click="resetSplitter">Сбросить размер</q-btn>
        <q-btn flat :disable="store.loading" @click="store.load"><RefreshCw :size="16" class="q-mr-xs" /><span>Обновить</span></q-btn>
      </template>
    </AppToolbar>
    <AppErrorBanner :message="store.error" />
    <div class="digital-passes-layout workspace-page" :class="{ 'workspace-page--card': Boolean(store.selectedIdentity) }">
      <div class="digital-passes-main workspace-page__list">
        <AppTable v-if="store.identities.length || store.loading" :rows="store.identities" :columns="columns" :loading="store.loading" :pagination="tablePagination" :rows-per-page-options="TABLE_ROWS_PER_PAGE_OPTIONS" :table-row-class-fn="rowClass" @update:pagination="updateTablePagination" @row-click="(_, row) => selectIdentity(row)">
          <template #body-cell-owner="props"><q-td :props="props"><button class="digital-passes-row-link" type="button" @click.stop="selectIdentity(props.row)">{{ ownerName(props.row) }}</button><div class="digital-passes-secondary-cell"><small>{{ tokenPreview(props.row.token) }}</small></div></q-td></template>
          <template #body-cell-entity_type="props"><q-td :props="props">{{ entityTypeLabel(props.row.entity_type) }}</q-td></template>
          <template #body-cell-status="props"><q-td :props="props"><AppStatusBadge :label="statusLabel(props.row.status)" :tone="statusTone(props.row.status)" /></q-td></template>
          <template #body-cell-issued_at="props"><q-td :props="props">{{ formatDateTime(props.row.issued_at) }}</q-td></template>
          <template #body-cell-expires_at="props"><q-td :props="props">{{ formatDateTime(props.row.expires_at) }}</q-td></template>
          <template #body-cell-actions="props"><q-td :props="props"><div class="digital-passes-row-actions"><q-btn v-if="canManage" flat round dense color="negative" title="Отозвать" :disable="props.row.status === 'revoked' || store.saving" @click.stop="requestRevoke(props.row)"><ShieldX :size="16" /></q-btn></div></q-td></template>
        </AppTable>
        <AppEmptyState v-else title="Цифровые пропуска не найдены" description="Выпустите первый QR-пропуск для студента, преподавателя или сотрудника."><q-btn v-if="canManage" color="primary" label="Выпустить пропуск" @click="openIssueDialog()" /></AppEmptyState>
      </div>
      <aside class="digital-passes-side workspace-page__card">
        <WorkspaceBackBar @back="store.selectedId = null" />
        <AppEmptyState v-if="!store.selectedIdentity" title="Пропуск не выбран" description="Выберите строку в таблице, чтобы открыть QR-код и сведения о владельце." />
        <WorkspacePanel
          v-else
          class="digital-pass-card"
          :title="ownerName(store.selectedIdentity)"
          :subtitle="entityTypeLabel(store.selectedIdentity.entity_type)"
          :metrics="passMetrics"
        >
          <template #status>
            <AppStatusBadge :label="statusLabel(store.selectedIdentity.status)" :tone="statusTone(store.selectedIdentity.status)" />
          </template>
          <template #actions>
            <div class="workspace-panel__actions">
              <q-btn v-if="ownerRoute" no-caps unelevated class="workspace-panel__action" :to="ownerRoute"><ExternalLink :size="15" class="q-mr-xs" /> Открыть человека</q-btn>
              <q-btn v-if="canManage" no-caps unelevated class="workspace-panel__action" :disable="store.selectedIdentity.status === 'revoked' || store.saving" @click="requestRevoke(store.selectedIdentity)"><ShieldX :size="16" class="q-mr-xs" /> Отозвать</q-btn>
              <q-btn v-if="canManage" no-caps unelevated class="workspace-panel__action" @click="openIssueDialog(store.selectedIdentity.entity_type)">Выпустить заново</q-btn>
              <q-btn no-caps unelevated class="workspace-panel__action" :to="accessHistoryRoute">История проходов</q-btn>
            </div>
          </template>
          <div class="digital-pass-details">
            <div class="digital-pass-qr-shell">
              <div class="digital-pass-qr" v-html="store.qrSvg" />
            </div>
            <div class="digital-pass-qr-actions">
              <q-btn outline no-caps @click="qrDialogVisible = true"><Maximize2 :size="16" class="q-mr-xs" /> Открыть QR крупно</q-btn>
              <q-btn outline no-caps @click="downloadQrPng"><Download :size="16" class="q-mr-xs" /> Скачать PNG</q-btn>
              <q-btn v-if="auth.isAdmin" outline no-caps @click="store.qrValueVisible = !store.qrValueVisible"><Eye :size="16" class="q-mr-xs" /> Показать значение QR</q-btn>
            </div>
            <dl class="digital-pass-details__list"><div v-if="store.qrValueVisible"><dt>Значение QR</dt><dd class="digital-pass-token-value">{{ store.selectedIdentity.token }}</dd></div><div><dt>Токен</dt><dd>{{ tokenPreview(store.selectedIdentity.token) }}</dd></div><div><dt>Выдан</dt><dd>{{ formatDateTime(store.selectedIdentity.issued_at) }}</dd></div><div><dt>Действует до</dt><dd>{{ formatDateTime(store.selectedIdentity.expires_at) }}</dd></div><div v-if="store.selectedIdentity.revoked_at"><dt>Отозван</dt><dd>{{ formatDateTime(store.selectedIdentity.revoked_at) }}</dd></div></dl>
            <div class="digital-pass-details__notice">QR-код содержит только чистый ASCII token. ФИО, телефон, email и другие персональные данные в QR не записываются.</div>
          </div>
        </WorkspacePanel>
      </aside>
    </div>
    <q-dialog v-model="issueDialogVisible" persistent><q-card class="digital-pass-issue-dialog"><q-card-section><div class="text-h6">Выпустить цифровой пропуск</div><p class="digital-pass-dialog-text">Новый выпуск отзовет активный пропуск этого владельца и создаст новый токен.</p></q-card-section><q-card-section class="digital-pass-issue-dialog__body"><q-select v-model="issueForm.entity_type" dense outlined emit-value map-options label="Тип владельца" :options="ENTITY_OPTIONS" @update:model-value="issueForm.entity_id = ''" /><q-select v-model="issueForm.entity_id" dense outlined emit-value map-options use-input input-debounce="0" label="Владелец" :options="currentOwnerOptions" /><q-input v-model="issueForm.expires_at" dense outlined type="datetime-local" label="Срок действия" clearable /></q-card-section><q-card-actions align="right"><q-btn flat label="Отмена" :disable="store.saving" @click="issueDialogVisible = false" /><q-btn color="primary" :loading="store.saving" :disable="!issueForm.entity_id" @click="issuePass"><BadgeCheck :size="16" class="q-mr-xs" /><span>Выпустить</span></q-btn></q-card-actions></q-card></q-dialog>
    <q-dialog v-model="qrDialogVisible">
      <q-card class="digital-pass-large-dialog">
        <q-card-section>
          <div class="text-h6">QR-пропуск</div>
          <p class="digital-pass-dialog-text">Покажите этот QR физическому сканеру. Содержимое: чистый token.</p>
        </q-card-section>
        <q-card-section>
          <div class="digital-pass-large-qr" v-html="store.qrSvg" />
        </q-card-section>
        <q-card-actions align="right"><q-btn flat label="Закрыть" @click="qrDialogVisible = false" /></q-card-actions>
      </q-card>
    </q-dialog>
    <AppConfirmDialog v-model="revokeDialogVisible" title="Отозвать цифровой пропуск?" :message="revokingIdentity ? `Будет отозван пропуск: ${ownerName(revokingIdentity)}.` : 'Будет отозван выбранный пропуск.'" confirm-label="Отозвать" tone="negative" @confirm="confirmRevoke" />
  </AppPage>
</template>
