<script setup>
import { computed, onMounted, ref } from 'vue'
import { usePermissions } from '../../../composables/usePermissions'
import { useQuasar } from 'quasar'
import { RefreshCw, RotateCcw, Save, Settings as SettingsIcon } from '@lucide/vue'
import AppPage from '../../../components/ui/AppPage.vue'
import PageHeader from '../../../components/ui/PageHeader.vue'
import AppToolbar from '../../../components/ui/AppToolbar.vue'
import AppCard from '../../../components/ui/AppCard.vue'
import AppErrorBanner from '../../../components/ui/AppErrorBanner.vue'
import AppLoading from '../../../components/ui/AppLoading.vue'
import AppConfirmDialog from '../../../components/ui/AppConfirmDialog.vue'
import AppTimeField from '../../../components/ui/AppTimeField.vue'
import { groupLabels, useSettingsStore } from '../../../stores/settings'

const store = useSettingsStore()
const permissions = usePermissions()
const canManage = computed(() => permissions.hasPermission('settings.manage'))
const $q = useQuasar()
const activeTab = ref('general')
const resetDialog = ref(false)

const currentGroup = computed(() => store.orderedGroups.find((group) => group.key === activeTab.value) || store.orderedGroups[0] || null)

function fieldKey(setting) {
  return `${setting.group}.${setting.key}`
}

function inputType(setting) {
  if (setting.type === 'integer') return 'number'
  if (setting.type === 'email') return 'email'
  if (setting.type === 'url') return 'url'
  if (setting.type === 'color') return 'color'
  if (setting.type === 'time') return 'time'
  return 'text'
}

function formatDefault(setting) {
  if (setting.default_value === null || setting.default_value === undefined || setting.default_value === '') return 'Не задано'
  return String(setting.default_value)
}

async function save() {
  const payload = await store.save()
  $q.notify({ type: 'positive', message: payload?.message || 'Настройки сохранены', position: 'top-right' })
}

async function resetToDefaults() {
  const payload = await store.resetToDefaults()
  $q.notify({ type: 'warning', message: payload?.message || 'Настройки сброшены', position: 'top-right' })
}

onMounted(async () => {
  await store.load()
  if (!store.orderedGroups.some((group) => group.key === activeTab.value) && store.orderedGroups[0]) {
    activeTab.value = store.orderedGroups[0].key
  }
})
</script>

<template>
  <AppPage>
    <PageHeader title="Настройки колледжа" subtitle="Единый центр системных параметров CollegePortal: название, учебный год, прием, выпуск, идентификация, интеграции и брендинг." />
    <AppToolbar>
      <span>Production-настройки меняются только после отдельного подтверждения. Секреты здесь не хранятся.</span>
      <template #actions>
        <AppLoading v-if="store.loading || store.saving" :label="store.saving ? 'Сохранение...' : 'Загрузка настроек...'" />
        <q-btn flat :disable="store.loading || store.saving" @click="store.load"><RefreshCw :size="16" class="q-mr-xs" /> Обновить</q-btn>
        <q-btn v-if="canManage" outline color="warning" :disable="store.loading || store.saving" @click="resetDialog = true"><RotateCcw :size="16" class="q-mr-xs" /> Сбросить</q-btn>
        <q-btn v-if="canManage" color="primary" :loading="store.saving" @click="save"><Save :size="16" class="q-mr-xs" /> Сохранить</q-btn>
      </template>
    </AppToolbar>
    <AppErrorBanner :message="store.error" />

    <div class="settings-layout">
      <aside class="settings-tabs">
        <AppCard title="Разделы" subtitle="Группы настроек">
          <q-list dense class="settings-tab-list">
            <q-item
              v-for="group in store.orderedGroups"
              :key="group.key"
              clickable
              :active="activeTab === group.key"
              active-class="settings-tab-active"
              @click="activeTab = group.key"
            >
              <q-item-section avatar><SettingsIcon :size="18" /></q-item-section>
              <q-item-section>{{ group.label }}</q-item-section>
            </q-item>
          </q-list>
        </AppCard>
      </aside>

      <section class="settings-main">
        <AppCard v-if="currentGroup" :title="currentGroup.label" :subtitle="`Настройки группы ${currentGroup.key}`">
          <div class="settings-grid">
            <div v-for="setting in currentGroup.items" :key="fieldKey(setting)" class="settings-field">
              <div class="settings-field__head">
                <div>
                  <strong>{{ setting.label }}</strong>
                  <small>{{ setting.group }}.{{ setting.key }}</small>
                </div>
                <q-chip v-if="setting.is_public" dense color="green-1" text-color="positive">Публичная</q-chip>
                <q-chip v-else dense color="grey-2" text-color="grey-8">Админ</q-chip>
              </div>

              <AppTimeField
                v-if="setting.type === 'time'"
                v-model="store.editable[fieldKey(setting)]"
                :label="setting.label"
              />
              <q-input
                v-else
                v-model="store.editable[fieldKey(setting)]"
                dense
                outlined
                :type="inputType(setting)"
                :min="setting.type === 'integer' ? 0 : undefined"
                :label="setting.label"
              />

              <p>{{ setting.description }}</p>
              <span>По умолчанию: {{ formatDefault(setting) }}</span>
            </div>
          </div>
        </AppCard>
      </section>
    </div>

    <AppConfirmDialog
      v-model="resetDialog"
      title="Сбросить настройки?"
      message="Все настройки будут возвращены к значениям по умолчанию для DEV. Это не удаляет данные колледжа и не затрагивает production."
      confirm-label="Сбросить"
      tone="warning"
      @confirm="resetToDefaults"
    />
  </AppPage>
</template>

<style scoped>
.settings-layout {
  display: grid;
  grid-template-columns: 300px minmax(0, 1fr);
  gap: 16px;
  align-items: start;
}

.settings-tabs {
  position: sticky;
  top: 84px;
}

.settings-tab-list :deep(.q-item) {
  border-radius: 8px;
  margin-bottom: 4px;
}

.settings-tab-active {
  background: #eff6ff;
  color: #1d4ed8;
}

.settings-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 14px;
}

.settings-field {
  display: grid;
  gap: 8px;
  padding: 14px;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  background: #ffffff;
}

.settings-field__head {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  align-items: start;
}

.settings-field strong {
  display: block;
  font-size: 14px;
}

.settings-field small,
.settings-field span,
.settings-field p {
  color: #64748b;
  font-size: 12px;
}

.settings-field p {
  margin: 0;
  min-height: 32px;
}

@media (max-width: 1439px) {
  .settings-layout {
    grid-template-columns: minmax(0, 1fr);
  }

  .settings-tabs {
    position: static;
  }
}

@media (max-width: 900px) {
  .settings-grid {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
