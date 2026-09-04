<script setup>
import { computed, onMounted, ref } from 'vue'
import { useQuasar } from 'quasar'
import { Database, Download, RefreshCw, RotateCcw, Upload } from '@lucide/vue'
import AppPage from '../../components/ui/AppPage.vue'
import PageHeader from '../../components/ui/PageHeader.vue'
import AppToolbar from '../../components/ui/AppToolbar.vue'
import AppCard from '../../components/ui/AppCard.vue'
import AppErrorBanner from '../../components/ui/AppErrorBanner.vue'
import AppLoading from '../../components/ui/AppLoading.vue'
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'
import { useDemoDataStore } from '../../stores/demoData'
import { useDatabaseBackupsStore } from '../../stores/databaseBackups'
import { usePermissions } from '../../composables/usePermissions'
import { formatDateTime as formatCollegeDateTime } from '../../utils/datetime'

const store = useDemoDataStore()
const backups = useDatabaseBackupsStore()
const permissions = usePermissions()
const canManage = computed(() => permissions.hasPermission('demo_data.manage'))
const canManageBackups = computed(() => permissions.hasPermission('settings.manage'))
const $q = useQuasar()
const importFile = ref(null)
const restoreDialog = ref(false)
const restoreConfirmation = ref('')
const selectedSnapshot = ref(null)
const summaryLabels = {
  students: 'Студенты',
  groups: 'Группы',
  teachers: 'Преподаватели',
  subjects: 'Дисциплины',
  classrooms: 'Аудитории',
  applicant_applications: 'Заявления',
}

function notify(message, type = 'positive') { $q.notify({ type, message, position: 'top-right', timeout: 1800 }) }
async function importData(file) { if (!canManage.value || !file) return; const payload = await store.importData(file); importFile.value = null; notify(payload?.message || 'Файл принят') }
async function exportData() { if (!canManage.value) return; await store.exportData(); notify('Экспорт данных подготовлен') }
async function createBackup() { if (!canManageBackups.value) return; const payload = await backups.create(); notify(payload?.message || 'Архив создан') }
function openRestore(snapshot) { selectedSnapshot.value = snapshot; restoreConfirmation.value = ''; restoreDialog.value = true }
async function restoreBackup() { if (!canManageBackups.value || restoreConfirmation.value !== 'RESTORE' || !selectedSnapshot.value) return; const payload = await backups.restore(selectedSnapshot.value.id, restoreConfirmation.value); restoreDialog.value = false; notify(payload?.message || 'База данных восстановлена') }
function formatSize(size) { if (size < 1024 * 1024) return `${Math.max(1, Math.round(size / 1024))} КБ`; return `${(size / 1024 / 1024).toFixed(1)} МБ` }
function formatDate(value) { return formatCollegeDateTime(value, { second: '2-digit' }) }
onMounted(() => { store.load(); if (canManageBackups.value) backups.load() })
</script>

<template>
  <AppPage>
    <PageHeader title="Управление данными" subtitle="Импорт и экспорт рабочих данных, резервные копии базы." />
    <AppToolbar>
      <span>Состояние рабочих данных</span>
      <template #actions>
        <AppLoading v-if="store.loading" label="Операция выполняется..." />
        <q-btn flat :disable="store.loading" @click="store.load"><RefreshCw :size="16" class="q-mr-xs" /> Обновить</q-btn>
      </template>
    </AppToolbar>
    <AppErrorBanner :message="store.error" />
    <AppErrorBanner :message="backups.error" />

    <div class="data-management-layout">
      <section class="data-management-main">
        <AppCard title="Действия" subtitle="Загрузка и выгрузка рабочих данных.">
          <div class="data-management-actions">
            <q-file v-if="canManage" v-model="importFile" dense outlined accept=".csv,text/csv" label="Импорт данных" style="max-width: 260px" @update:model-value="importData"><template #prepend><Upload :size="16" /></template></q-file>
            <q-btn v-if="canManage" color="primary" outline :disable="store.loading" @click="exportData"><Download :size="16" class="q-mr-xs" /> Экспорт данных</q-btn>
          </div>
          <q-banner v-if="store.lastMessage" rounded class="data-management-info">{{ store.lastMessage }}</q-banner>
        </AppCard>
      </section>

      <aside class="data-management-side">
        <AppCard title="Текущие данные" subtitle="Количество записей в ключевых MVP-разделах.">
          <div class="data-management-summary">
            <div v-for="(label, key) in summaryLabels" :key="key" class="data-management-summary__item">
              <span>{{ label }}</span>
              <strong>{{ store.summary[key] ?? 0 }}</strong>
            </div>
          </div>
        </AppCard>
      </aside>
    </div>

    <AppCard v-if="canManageBackups" class="q-mt-md" title="Полные архивы PostgreSQL" subtitle="Архивы хранятся только в защищенном хранилище backend. Восстановление заменяет содержимое базы и сначала создает аварийный архив.">
      <div class="data-management-actions q-mb-md">
        <q-btn color="primary" :loading="backups.loading" @click="createBackup"><Database :size="16" class="q-mr-xs" /> Создать полный архив</q-btn>
        <q-btn flat :disable="backups.loading" @click="backups.load"><RefreshCw :size="16" class="q-mr-xs" /> Обновить список</q-btn>
      </div>
      <q-markup-table flat bordered dense>
        <thead><tr><th class="text-left">Имя</th><th class="text-left">Создан</th><th class="text-right">Размер</th><th class="text-right">Действие</th></tr></thead>
        <tbody>
          <tr v-for="snapshot in backups.snapshots" :key="snapshot.id">
            <td>{{ snapshot.name }} <AppStatusBadge v-if="snapshot.type === 'emergency'" label="аварийный" tone="warning" class="q-ml-sm" /></td>
            <td>{{ formatDate(snapshot.created_at) }}</td><td class="text-right">{{ formatSize(snapshot.size) }}</td>
            <td class="text-right"><q-btn color="negative" flat dense :disable="backups.loading" @click="openRestore(snapshot)"><RotateCcw :size="16" class="q-mr-xs" /> Восстановить</q-btn></td>
          </tr>
          <tr v-if="!backups.loading && !backups.snapshots.length"><td colspan="4" class="text-center text-grey-7">Архивов пока нет.</td></tr>
        </tbody>
      </q-markup-table>
    </AppCard>

    <q-dialog v-model="restoreDialog" persistent>
      <q-card style="width: 520px; max-width: 95vw">
        <q-card-section><div class="text-h6">Восстановить базу данных?</div><p class="q-mb-none text-negative">Текущая база будет заменена архивом {{ selectedSnapshot?.name }}. Перед запуском будет создан аварийный полный архив.</p></q-card-section>
        <q-card-section><q-input v-model="restoreConfirmation" outlined label="Введите RESTORE для подтверждения" autocomplete="off" /></q-card-section>
        <q-card-actions align="right"><q-btn flat label="Отмена" :disable="backups.loading" @click="restoreDialog = false" /><q-btn color="negative" :loading="backups.loading" :disable="restoreConfirmation !== 'RESTORE'" @click="restoreBackup">Восстановить</q-btn></q-card-actions>
      </q-card>
    </q-dialog>
  </AppPage>
</template>
