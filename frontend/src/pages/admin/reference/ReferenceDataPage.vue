<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { usePermissions } from '../../../composables/usePermissions'
import { useQuasar } from 'quasar'
import { Edit3, ListTree, Plus, RefreshCw, Tags, Trash2 } from '@lucide/vue'
import AppPage from '../../../components/ui/AppPage.vue'
import PageHeader from '../../../components/ui/PageHeader.vue'
import AppToolbar from '../../../components/ui/AppToolbar.vue'
import AppCard from '../../../components/ui/AppCard.vue'
import AppErrorBanner from '../../../components/ui/AppErrorBanner.vue'
import AppEmptyState from '../../../components/ui/AppEmptyState.vue'
import AppLoading from '../../../components/ui/AppLoading.vue'
import AppConfirmDialog from '../../../components/ui/AppConfirmDialog.vue'
import AppStatusBadge from '../../../components/ui/AppStatusBadge.vue'
import { useReferenceDataStore } from '../../../stores/referenceData'

const store = useReferenceDataStore()
const permissions = usePermissions()
const canManage = computed(() => permissions.hasPermission('reference.manage'))
const $q = useQuasar()
const catalogDialog = ref(false)
const itemDialog = ref(false)
const deleteCatalogDialog = ref(false)
const deleteItemDialog = ref(false)
const editingCatalog = ref(null)
const editingItem = ref(null)
const catalogForm = reactive({ code: '', name: '', description: '', is_system: false })
const itemForm = reactive({ catalog_id: null, code: '', name: '', sort_order: 0, is_active: true, metadataText: '' })
const catalogColumns = [
  { name: 'name', label: 'Справочник', field: 'name', align: 'left' },
  { name: 'items', label: 'Элементы', field: 'items_count', align: 'right' },
  { name: 'actions', label: '', field: 'actions', align: 'right' },
]
const itemColumns = [
  { name: 'sort_order', label: 'Порядок', field: 'sort_order', align: 'left' },
  { name: 'name', label: 'Элемент', field: 'name', align: 'left' },
  { name: 'status', label: 'Статус', field: 'is_active', align: 'left' },
  { name: 'actions', label: '', field: 'actions', align: 'right' },
]

const selectedCatalog = computed(() => store.selectedCatalog)

function notify(message, type = 'positive') {
  $q.notify({ type, message, position: 'top-right', timeout: 1800 })
}

function resetCatalogForm(catalog = null) {
  editingCatalog.value = catalog
  catalogForm.code = catalog?.code || ''
  catalogForm.name = catalog?.name || ''
  catalogForm.description = catalog?.description || ''
  catalogForm.is_system = Boolean(catalog?.is_system)
}

function resetItemForm(item = null) {
  editingItem.value = item
  itemForm.catalog_id = item?.catalog_id || store.selectedCatalogId
  itemForm.code = item?.code || ''
  itemForm.name = item?.name || ''
  itemForm.sort_order = item?.sort_order ?? 0
  itemForm.is_active = item?.is_active ?? true
  itemForm.metadataText = item?.metadata ? JSON.stringify(item.metadata, null, 2) : ''
}

function openCatalogDialog(catalog = null) {
  resetCatalogForm(catalog)
  catalogDialog.value = true
}

function openItemDialog(item = null) {
  resetItemForm(item)
  itemDialog.value = true
}

function catalogRowClass(row) {
  return Number(row.id) === Number(store.selectedCatalogId) ? 'cp-selected-row' : ''
}

function parseMetadata() {
  if (!itemForm.metadataText.trim()) return null
  return JSON.parse(itemForm.metadataText)
}

async function saveCatalog() {
  await store.saveCatalog({ ...catalogForm }, editingCatalog.value?.id || null)
  catalogDialog.value = false
  notify(editingCatalog.value ? 'Справочник обновлен' : 'Справочник создан')
}

async function saveItem() {
  let metadata = null
  try {
    metadata = parseMetadata()
  } catch (err) {
    notify('Metadata должна быть корректным JSON', 'negative')
    return
  }
  await store.saveItem({
    catalog_id: itemForm.catalog_id,
    code: itemForm.code,
    name: itemForm.name,
    sort_order: Number(itemForm.sort_order) || 0,
    is_active: Boolean(itemForm.is_active),
    metadata,
  }, editingItem.value?.id || null)
  itemDialog.value = false
  notify(editingItem.value ? 'Элемент обновлен' : 'Элемент создан')
}

async function deleteCatalog() {
  await store.deleteCatalog(editingCatalog.value.id)
  deleteCatalogDialog.value = false
  notify('Справочник удален')
}

async function deleteItem() {
  await store.deleteItem(editingItem.value.id)
  deleteItemDialog.value = false
  notify('Элемент удален')
}

async function toggleItem(item) {
  await store.toggleItem(item)
  notify(item.is_active ? 'Элемент деактивирован' : 'Элемент активирован', 'info')
}

watch(() => store.error, (message) => {
  if (message) notify(message, 'negative')
})

onMounted(store.loadCatalogs)
</script>

<template>
  <AppPage>
    <PageHeader title="Справочники" subtitle="Единая нормативно-справочная информация для подсистем CollegePortal." />
    <AppToolbar>
      <span>Системные справочники защищены от удаления. Их элементы можно временно деактивировать.</span>
      <template #actions>
        <AppLoading v-if="store.loading || store.itemsLoading || store.saving" label="Загрузка справочников..." />
        <q-btn flat :disable="store.loading" @click="store.loadCatalogs"><RefreshCw :size="16" class="q-mr-xs" /> Обновить</q-btn>
        <q-btn v-if="canManage" color="primary" @click="openCatalogDialog()"><Plus :size="16" class="q-mr-xs" /> Справочник</q-btn>
        <q-btn v-if="canManage" color="primary" outline :disable="!store.selectedCatalogId" @click="openItemDialog()"><Plus :size="16" class="q-mr-xs" /> Элемент</q-btn>
      </template>
    </AppToolbar>
    <AppErrorBanner :message="store.error" />

    <div class="reference-layout">
      <section class="reference-catalogs">
        <AppCard title="Список справочников" subtitle="Выберите справочник для просмотра элементов.">
          <q-table
            v-if="store.catalogs.length"
            dense
            flat
            :rows="store.catalogs"
            :columns="catalogColumns"
            row-key="id"
            hide-pagination
            :pagination="{ rowsPerPage: 0 }"
            :table-row-class-fn="catalogRowClass"
            @row-click="(_, row) => store.loadItems(row.id)"
          >
            <template #body-cell-name="props">
              <q-td :props="props">
                <div class="reference-name">
                  <strong>{{ props.row.name }}</strong>
                  <span>{{ props.row.code }}</span>
                </div>
                <q-chip v-if="props.row.is_system" dense color="blue-1" text-color="primary">Системный</q-chip>
              </q-td>
            </template>
            <template #body-cell-items="props"><q-td :props="props">{{ props.row.items_count ?? 0 }}</q-td></template>
            <template #body-cell-actions="props">
              <q-td :props="props">
                <q-btn v-if="canManage" flat dense round title="Редактировать" @click.stop="openCatalogDialog(props.row)"><Edit3 :size="15" /></q-btn>
                <q-btn v-if="canManage" flat dense round color="negative" title="Удалить" :disable="props.row.is_system || Number(props.row.items_count || 0) > 0" @click.stop="editingCatalog = props.row; deleteCatalogDialog = true"><Trash2 :size="15" /></q-btn>
              </q-td>
            </template>
          </q-table>
          <AppEmptyState v-else title="Справочников нет" description="Создайте первый справочник или выполните seeder системных справочников." />
        </AppCard>
      </section>

      <section class="reference-items">
        <AppCard v-if="selectedCatalog" :title="selectedCatalog.name" :subtitle="selectedCatalog.description || 'Элементы выбранного справочника'">
          <template #actions>
            <AppStatusBadge :label="selectedCatalog.is_system ? 'Системный' : 'Пользовательский'" :tone="selectedCatalog.is_system ? 'info' : 'neutral'" />
          </template>
          <div class="reference-summary">
            <div><ListTree :size="18" /> Всего: <strong>{{ store.items.length }}</strong></div>
            <div><Tags :size="18" /> Активных: <strong>{{ store.activeItemsCount }}</strong></div>
          </div>

          <q-table
            v-if="store.items.length"
            dense
            flat
            :rows="store.items"
            :columns="itemColumns"
            row-key="id"
            hide-pagination
            :pagination="{ rowsPerPage: 0 }"
          >
            <template #body-cell-name="props">
              <q-td :props="props">
                <div class="reference-name">
                  <strong>{{ props.row.name }}</strong>
                  <span>{{ props.row.code }}</span>
                </div>
                <q-chip v-if="props.row.is_system" dense color="blue-1" text-color="primary">Системный</q-chip>
              </q-td>
            </template>
            <template #body-cell-status="props">
              <q-td :props="props"><AppStatusBadge :label="props.row.is_active ? 'Активен' : 'Неактивен'" :tone="props.row.is_active ? 'success' : 'neutral'" /></q-td>
            </template>
            <template #body-cell-actions="props">
              <q-td :props="props">
                <q-btn v-if="canManage" flat dense no-caps @click="toggleItem(props.row)">{{ props.row.is_active ? 'Деактивировать' : 'Активировать' }}</q-btn>
                <q-btn v-if="canManage" flat dense round title="Редактировать" @click="openItemDialog(props.row)"><Edit3 :size="15" /></q-btn>
                <q-btn v-if="canManage" flat dense round color="negative" title="Удалить" :disable="props.row.is_system || selectedCatalog.is_system" @click="editingItem = props.row; deleteItemDialog = true"><Trash2 :size="15" /></q-btn>
              </q-td>
            </template>
          </q-table>
          <AppEmptyState v-else title="Элементов нет" description="Добавьте элементы справочника через кнопку Элемент." />
        </AppCard>
        <AppEmptyState v-else title="Справочник не выбран" description="Выберите справочник слева." />
      </section>
    </div>

    <q-dialog v-model="catalogDialog">
      <q-card class="reference-dialog">
        <q-card-section><div class="text-h6">{{ editingCatalog ? 'Редактировать справочник' : 'Создать справочник' }}</div></q-card-section>
        <q-card-section class="q-gutter-md">
          <q-input v-model="catalogForm.code" dense outlined label="Код" :readonly="editingCatalog?.is_system" hint="Латиница, цифры, дефис или подчеркивание" />
          <q-input v-model="catalogForm.name" dense outlined label="Название" />
          <q-input v-model="catalogForm.description" dense outlined type="textarea" label="Описание" />
          <q-checkbox v-model="catalogForm.is_system" label="Системный справочник" :disable="Boolean(editingCatalog)" />
        </q-card-section>
        <q-card-actions align="right">
          <q-btn flat v-close-popup>Отмена</q-btn>
          <q-btn color="primary" :loading="store.saving" @click="saveCatalog">Сохранить</q-btn>
        </q-card-actions>
      </q-card>
    </q-dialog>

    <q-dialog v-model="itemDialog">
      <q-card class="reference-dialog">
        <q-card-section><div class="text-h6">{{ editingItem ? 'Редактировать элемент' : 'Создать элемент' }}</div></q-card-section>
        <q-card-section class="q-gutter-md">
          <q-input v-model="itemForm.code" dense outlined label="Код" :readonly="editingItem?.is_system" />
          <q-input v-model="itemForm.name" dense outlined label="Название" />
          <q-input v-model.number="itemForm.sort_order" dense outlined type="number" min="0" label="Порядок" />
          <q-toggle v-model="itemForm.is_active" label="Активен" />
          <q-input v-model="itemForm.metadataText" dense outlined type="textarea" label="Metadata JSON" hint="Необязательно. Например: {&quot;color&quot;:&quot;green&quot;}" />
        </q-card-section>
        <q-card-actions align="right">
          <q-btn flat v-close-popup>Отмена</q-btn>
          <q-btn color="primary" :loading="store.saving" @click="saveItem">Сохранить</q-btn>
        </q-card-actions>
      </q-card>
    </q-dialog>

    <AppConfirmDialog v-model="deleteCatalogDialog" title="Удалить справочник?" :message="`Справочник '${editingCatalog?.name || ''}' будет удален.`" confirm-label="Удалить" tone="negative" @confirm="deleteCatalog" />
    <AppConfirmDialog v-model="deleteItemDialog" title="Удалить элемент?" :message="`Элемент '${editingItem?.name || ''}' будет удален.`" confirm-label="Удалить" tone="negative" @confirm="deleteItem" />
  </AppPage>
</template>

<style scoped>
.reference-layout {
  display: grid;
  grid-template-columns: minmax(360px, 42%) minmax(0, 1fr);
  gap: 16px;
  align-items: start;
}

.reference-name {
  display: grid;
  gap: 2px;
}

.reference-name span {
  color: #64748b;
  font-size: 12px;
}

.reference-summary {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  margin-bottom: 12px;
  color: #475569;
}

.reference-summary div {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.reference-dialog {
  width: min(620px, 92vw);
}

:deep(.cp-selected-row) {
  background: #eff6ff;
}

@media (max-width: 1439px) {
  .reference-layout {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
