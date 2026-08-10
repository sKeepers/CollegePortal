<script setup>
import { computed, onMounted, ref } from 'vue'
import { useQuasar } from 'quasar'
import { Database, Download, RefreshCw, RotateCcw, Trash2, Upload } from '@lucide/vue'
import AppPage from '../../components/ui/AppPage.vue'
import PageHeader from '../../components/ui/PageHeader.vue'
import AppToolbar from '../../components/ui/AppToolbar.vue'
import AppCard from '../../components/ui/AppCard.vue'
import AppErrorBanner from '../../components/ui/AppErrorBanner.vue'
import AppConfirmDialog from '../../components/ui/AppConfirmDialog.vue'
import AppLoading from '../../components/ui/AppLoading.vue'
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'
import { useDemoDataStore } from '../../stores/demoData'
import { useDatabaseBackupsStore } from '../../stores/databaseBackups'
import { usePermissions } from '../../composables/usePermissions'

const store = useDemoDataStore()
const backups = useDatabaseBackupsStore()
const permissions = usePermissions()
const canManage = computed(() => permissions.hasPermission('demo_data.manage'))
const canManageBackups = computed(() => permissions.hasPermission('settings.manage'))
const $q = useQuasar()
const importFile = ref(null)
const clearDialog = ref(false)
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
async function createDemoData() { if (!canManage.value) return; const payload = await store.createDemoData(); notify(payload?.message || 'Демо-данные созданы') }
async function clearDemoData() { if (!canManage.value) return; const payload = await store.clearDemoData(); notify(payload?.message || 'Демо-данные очищены') }
async function importData(file) { if (!canManage.value || !file) return; const payload = await store.importData(file); importFile.value = null; notify(payload?.message || 'Файл принят') }
async function exportData() { if (!canManage.value) return; await store.exportData(); notify('Экспорт данных подготовлен') }
async function createBackup() { if (!canManageBackups.value) return; const payload = await backups.create(); notify(payload?.message || 'Архив создан') }
function openRestore(snapshot) { selectedSnapshot.value = snapshot; restoreConfirmation.value = ''; restoreDialog.value = true }
async function restoreBackup() { if (!canManageBackups.value || restoreConfirmation.value !== 'RESTORE' || !selectedSnapshot.value) return; const payload = await backups.restore(selectedSnapshot.value.id, restoreConfirmation.value); restoreDialog.value = false; notify(payload?.message || 'База данных восстановлена') }
function formatSize(size) { if (size < 1024 * 1024) return `${Math.max(1, Math.round(size / 1024))} КБ`; return `${(size / 1024 / 1024).toFixed(1)} МБ` }
function formatDate(value) { return new Intl.DateTimeFormat('ru-RU', { dateStyle: 'short', timeStyle: 'medium' }).format(new Date(value)) }
onMounted(() => { store.load(); if (canManageBackups.value) backups.load() })
</script>

<template>
  <AppPage>
    <PageHeader title="Управление данными" subtitle="Рабочие данные, импорт и экспорт для подготовки UAT. Очистка запрещена в production." />
    <AppToolbar>
      <span>Состояние тестового набора данных</span>
      <template #actions>
        <AppLoading v-if="store.loading" label="Операция выполняется..." />
        <q-btn flat :disable="store.loading" @click="store.load"><RefreshCw :size="16" class="q-mr-xs" /> Обновить</q-btn>
      </template>
    </AppToolbar>
    <AppErrorBanner :message="store.error" />
    <AppErrorBanner :message="backups.error" />

    <div class="data-management-layout">
      <section class="data-management-main">
        <AppCard title="Действия" subtitle="Создание и очистка демо-данных используются только в DEV/TEST перед пользовательской проверкой.">
          <div class="data-management-actions">
            <q-btn v-if="canManage" color="primary" :loading="store.loading" @click="createDemoData"><Database :size="16" class="q-mr-xs" /> Создать демо-данные</q-btn>
            <q-btn v-if="canManage" color="negative" outline :disable="store.isProduction || store.loading" @click="clearDialog = true"><Trash2 :size="16" class="q-mr-xs" /> Очистить рабочие данные DEV</q-btn>
            <q-file v-if="canManage" v-model="importFile" dense outlined accept=".csv,text/csv" label="Импорт данных" style="max-width: 260px" @update:model-value="importData"><template #prepend><Upload :size="16" /></template></q-file>
            <q-btn v-if="canManage" color="primary" outline :disable="store.loading" @click="exportData"><Download :size="16" class="q-mr-xs" /> Экспорт данных</q-btn>
          </div>
          <q-banner v-if="store.isProduction" rounded class="data-management-warning">Очистка демо-данных недоступна в production.</q-banner>
          <q-banner v-if="store.lastMessage" rounded class="data-management-info">{{ store.lastMessage }}</q-banner>
          <q-banner v-if="store.lastClearResult?.skipped" rounded class="data-management-warning">Часть записей оставлена, потому что уже используется в учебных планах, нагрузке или экзаменах. Пропущено: студентов {{ store.lastClearResult.skipped.students || 0 }}, групп {{ store.lastClearResult.skipped.groups || 0 }}, преподавателей {{ store.lastClearResult.skipped.teachers || 0 }}, дисциплин {{ store.lastClearResult.skipped.subjects || 0 }}.</q-banner>
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
          <div class="data-management-status"><AppStatusBadge label="DEV / TEST only" tone="warning" /></div>
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

    <AppConfirmDialog v-model="clearDialog" title="Очистить рабочие данные DEV?" message="Будут удалены студенты, преподаватели, расписание, журналы, оценки, посещаемость, приемные и кадровые данные. Системные настройки, справочники, роли и учетные записи сохранятся. В production операция запрещена." confirm-label="Очистить" tone="negative" @confirm="clearDemoData" />
    <q-dialog v-model="restoreDialog" persistent>
      <q-card style="width: 520px; max-width: 95vw">
        <q-card-section><div class="text-h6">Восстановить базу данных?</div><p class="q-mb-none text-negative">Текущая база будет заменена архивом {{ selectedSnapshot?.name }}. Перед запуском будет создан аварийный полный архив.</p></q-card-section>
        <q-card-section><q-input v-model="restoreConfirmation" outlined label="Введите RESTORE для подтверждения" autocomplete="off" /></q-card-section>
        <q-card-actions align="right"><q-btn flat label="Отмена" :disable="backups.loading" @click="restoreDialog = false" /><q-btn color="negative" :loading="backups.loading" :disable="restoreConfirmation !== 'RESTORE'" @click="restoreBackup">Восстановить</q-btn></q-card-actions>
      </q-card>
    </q-dialog>
  </AppPage>
</template>
