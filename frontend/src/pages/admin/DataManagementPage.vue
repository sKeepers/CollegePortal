<script setup>
import { computed, onMounted, ref } from 'vue'
import { useQuasar } from 'quasar'
import { Database, Download, RefreshCw, Trash2, Upload } from '@lucide/vue'
import AppPage from '../../components/ui/AppPage.vue'
import PageHeader from '../../components/ui/PageHeader.vue'
import AppToolbar from '../../components/ui/AppToolbar.vue'
import AppCard from '../../components/ui/AppCard.vue'
import AppErrorBanner from '../../components/ui/AppErrorBanner.vue'
import AppConfirmDialog from '../../components/ui/AppConfirmDialog.vue'
import AppLoading from '../../components/ui/AppLoading.vue'
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'
import { useDemoDataStore } from '../../stores/demoData'
import { usePermissions } from '../../composables/usePermissions'

const store = useDemoDataStore()
const permissions = usePermissions()
const canManage = computed(() => permissions.hasPermission('import.manage'))
const $q = useQuasar()
const importFile = ref(null)
const clearDialog = ref(false)
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
onMounted(store.load)
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

    <AppConfirmDialog v-model="clearDialog" title="Очистить рабочие данные DEV?" message="Будут удалены студенты, преподаватели, расписание, журналы, оценки, посещаемость, приемные и кадровые данные. Системные настройки, справочники, роли и учетные записи сохранятся. В production операция запрещена." confirm-label="Очистить" tone="negative" @confirm="clearDemoData" />
  </AppPage>
</template>
