<script setup>
import { computed, onMounted, ref } from 'vue'
import { Plus, RefreshCw } from '@lucide/vue'
import AppPage from '../../components/ui/AppPage.vue'
import PageHeader from '../../components/ui/PageHeader.vue'
import AppToolbar from '../../components/ui/AppToolbar.vue'
import AppTable from '../../components/ui/AppTable.vue'
import AppErrorBanner from '../../components/ui/AppErrorBanner.vue'
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'
import { useAccessBuildingsStore } from '../../stores/accessBuildings'

const store = useAccessBuildingsStore()

const buildingColumns = [
  { name: 'name', label: 'Корпус', field: 'name', align: 'left', sortable: true },
  { name: 'code', label: 'Код', field: 'code', align: 'left' },
  { name: 'address', label: 'Адрес', field: 'address', align: 'left' },
  { name: 'access_points_count', label: 'Точек прохода', field: 'access_points_count', align: 'right' },
  { name: 'is_active', label: 'Статус', field: 'is_active', align: 'left' },
  { name: 'actions', label: '', field: 'actions', align: 'right' },
]

const pointColumns = [
  { name: 'name', label: 'Точка прохода', field: 'name', align: 'left', sortable: true },
  { name: 'building_name', label: 'Корпус', field: 'building_name', align: 'left', sortable: true },
  { name: 'code', label: 'Код', field: 'code', align: 'left' },
  { name: 'description', label: 'Описание', field: 'description', align: 'left' },
  { name: 'is_active', label: 'Статус', field: 'is_active', align: 'left' },
  { name: 'actions', label: '', field: 'actions', align: 'right' },
]

const buildingDialog = ref(false)
const pointDialog = ref(false)
const buildingForm = ref(emptyBuilding())
const pointForm = ref(emptyPoint())

const hasBuildings = computed(() => store.buildings.length > 0)

function emptyBuilding() {
  return { id: null, name: '', code: '', address: '', is_active: true, sort_order: 0 }
}

function emptyPoint() {
  return { id: null, building_id: null, name: '', code: '', description: '', is_active: true, sort_order: 0 }
}

function openBuilding(row = null) {
  buildingForm.value = row ? { ...row } : emptyBuilding()
  buildingDialog.value = true
}

function openPoint(row = null) {
  pointForm.value = row ? { ...row } : emptyPoint()
  pointDialog.value = true
}

async function submitBuilding() {
  try {
    await store.saveBuilding(buildingForm.value)
    buildingDialog.value = false
  } catch {
    // Сообщение показывает баннер над таблицей, диалог остается открытым.
  }
}

async function submitPoint() {
  try {
    await store.savePoint(pointForm.value)
    pointDialog.value = false
  } catch {
    // Сообщение показывает баннер над таблицей, диалог остается открытым.
  }
}

onMounted(() => store.loadReference())
</script>

<template>
  <AppPage>
    <PageHeader
      title="Корпуса и точки прохода"
      subtitle="Справочник, по которому события проходной раскладываются по зданиям. Сканер присылает название точки строкой, оно и сопоставляется с этим справочником."
    />

    <AppToolbar>
      <span>Корпусов: {{ store.buildings.length }} · точек прохода: {{ store.points.length }}</span>
      <template #actions>
        <q-btn flat :disable="store.loading" @click="store.loadReference"><RefreshCw :size="16" class="q-mr-xs" /> Обновить</q-btn>
        <q-btn color="primary" @click="openBuilding()"><Plus :size="16" class="q-mr-xs" /> Корпус</q-btn>
        <q-btn color="primary" :disable="!hasBuildings" @click="openPoint()"><Plus :size="16" class="q-mr-xs" /> Точка прохода</q-btn>
      </template>
    </AppToolbar>

    <AppErrorBanner :message="store.error" />

    <AppTable :rows="store.buildings" :columns="buildingColumns" :loading="store.loading">
      <template #body-cell-is_active="props">
        <q-td :props="props">
          <AppStatusBadge :label="props.row.is_active ? 'Активен' : 'Отключен'" :tone="props.row.is_active ? 'success' : 'neutral'" />
        </q-td>
      </template>
      <template #body-cell-actions="props">
        <q-td :props="props">
          <q-btn flat dense @click="openBuilding(props.row)">Изменить</q-btn>
          <q-btn flat dense color="negative" :disable="store.saving" @click="store.removeBuilding(props.row.id)">Удалить</q-btn>
        </q-td>
      </template>
    </AppTable>

    <AppTable class="q-mt-md" :rows="store.points" :columns="pointColumns" :loading="store.loading">
      <template #body-cell-is_active="props">
        <q-td :props="props">
          <AppStatusBadge :label="props.row.is_active ? 'Активна' : 'Отключена'" :tone="props.row.is_active ? 'success' : 'neutral'" />
        </q-td>
      </template>
      <template #body-cell-actions="props">
        <q-td :props="props">
          <q-btn flat dense @click="openPoint(props.row)">Изменить</q-btn>
          <q-btn flat dense color="negative" :disable="store.saving" @click="store.removePoint(props.row.id)">Удалить</q-btn>
        </q-td>
      </template>
    </AppTable>

    <q-dialog v-model="buildingDialog">
      <q-card class="access-points-dialog">
        <q-card-section class="text-h6">{{ buildingForm.id ? 'Изменить корпус' : 'Новый корпус' }}</q-card-section>
        <q-card-section class="column q-gutter-sm">
          <q-input v-model="buildingForm.name" dense outlined label="Название" autofocus />
          <q-input v-model="buildingForm.code" dense outlined label="Код" hint="Необязательно. Сканер может присылать код вместо названия." />
          <q-input v-model="buildingForm.address" dense outlined label="Адрес" />
          <q-toggle v-model="buildingForm.is_active" label="Активен" />
        </q-card-section>
        <q-card-actions align="right">
          <q-btn flat v-close-popup>Отмена</q-btn>
          <q-btn color="primary" :loading="store.saving" @click="submitBuilding">Сохранить</q-btn>
        </q-card-actions>
      </q-card>
    </q-dialog>

    <q-dialog v-model="pointDialog">
      <q-card class="access-points-dialog">
        <q-card-section class="text-h6">{{ pointForm.id ? 'Изменить точку прохода' : 'Новая точка прохода' }}</q-card-section>
        <q-card-section class="column q-gutter-sm">
          <q-select v-model="pointForm.building_id" dense outlined emit-value map-options label="Корпус" :options="store.buildingOptions" />
          <q-input v-model="pointForm.name" dense outlined label="Название" hint="Должно совпадать с тем, что прописано в сканере." />
          <q-input v-model="pointForm.code" dense outlined label="Код" />
          <q-input v-model="pointForm.description" dense outlined label="Описание" />
          <q-toggle v-model="pointForm.is_active" label="Активна" />
        </q-card-section>
        <q-card-actions align="right">
          <q-btn flat v-close-popup>Отмена</q-btn>
          <q-btn color="primary" :loading="store.saving" @click="submitPoint">Сохранить</q-btn>
        </q-card-actions>
      </q-card>
    </q-dialog>
  </AppPage>
</template>

<style scoped>
.access-points-dialog {
  min-width: 380px;
}
</style>
