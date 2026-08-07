<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { usePermissions } from '../../../composables/usePermissions'
import { useQuasar } from 'quasar'
import { KeyRound, RefreshCw, Save } from '@lucide/vue'
import AppPage from '../../../components/ui/AppPage.vue'
import PageHeader from '../../../components/ui/PageHeader.vue'
import AppToolbar from '../../../components/ui/AppToolbar.vue'
import AppFilterBar from '../../../components/ui/AppFilterBar.vue'
import AppTable from '../../../components/ui/AppTable.vue'
import AppCard from '../../../components/ui/AppCard.vue'
import AppStatusBadge from '../../../components/ui/AppStatusBadge.vue'
import AppErrorBanner from '../../../components/ui/AppErrorBanner.vue'
import AppEmptyState from '../../../components/ui/AppEmptyState.vue'
import AppLoading from '../../../components/ui/AppLoading.vue'
import WorkspaceSplitter from '../../../components/workspace/WorkspaceSplitter.vue'
import { useResizableWorkspace } from '../../../composables/useResizableWorkspace'
import { usePermissionsStore } from '../../../stores/permissions'

const store = usePermissionsStore()
const permissions = usePermissions()
const canManage = computed(() => permissions.hasPermission('permissions.manage'))
const $q = useQuasar()
const roleIds = ref([])
const pagination = ref({ rowsPerPage: 30 })
const selected = computed(() => store.selectedPermission)
const { resetSplitter, startResize, workspaceRef, workspaceStyle } = useResizableWorkspace({
  storageKey: 'collegePortal.permissions.splitter.v1',
  resizeBodyClass: 'permissions-splitter-resizing',
})

function permissionRowClass(row) {
  return Number(row.id) === Number(selected.value?.id) ? 'workspace-row--selected' : ''
}
const columns = [
  { name: 'code', label: 'Код', field: 'code', align: 'left', sortable: true },
  { name: 'name', label: 'Название', field: 'name', align: 'left', sortable: true },
  { name: 'module', label: 'Модуль', field: 'module', align: 'left', sortable: true },
  { name: 'roles_count', label: 'Ролей', field: 'roles_count', align: 'right', sortable: true },
  { name: 'active', label: 'Статус', field: 'active', align: 'left', sortable: true },
]
const assignedRoles = computed(() => selected.value?.roles || [])

watch(selected, (value) => {
  roleIds.value = value?.roles?.map((role) => role.id) || []
}, { immediate: true })

async function saveRoles() {
  await store.assignRoles(selected.value, roleIds.value)
  $q.notify({ type: 'positive', message: 'Матрица ролей обновлена', position: 'top-right' })
}

function select(row) {
  store.selectedId = row.id
}

onMounted(store.load)
</script>

<template>
  <AppPage>
    <PageHeader title="Разрешения" subtitle="Матрица permission-based RBAC для API, меню и рабочих разделов." />

    <AppToolbar>
      <span>Разрешения сгруппированы по модулям. Роли получают доступ через назначенные permissions.</span>
      <template #actions>
        <AppLoading v-if="store.loading || store.saving" label="Обработка..." />
        <q-btn flat :disable="store.loading" @click="store.load"><RefreshCw :size="16" class="q-mr-xs" /> Обновить</q-btn>
      </template>
    </AppToolbar>

    <AppErrorBanner :message="store.error" />

    <AppFilterBar>
      <q-input v-model="store.search" dense outlined clearable label="Поиск по коду, названию или описанию" @keyup.enter="store.load" />
      <q-select v-model="store.module" dense outlined emit-value map-options label="Модуль" :options="store.moduleOptions" />
      <template #actions>
        <q-btn color="primary" @click="store.load">Применить</q-btn>
        <q-btn flat @click="store.search = ''; store.module = ''; store.load()">Сбросить</q-btn>
      </template>
    </AppFilterBar>

    <div
      ref="workspaceRef"
      class="permissions-layout"
      :class="{ 'resizable-workspace': Boolean(selected) }"
      :style="selected ? workspaceStyle : null"
    >
      <section class="permissions-main">
        <AppTable v-if="store.permissions.length" v-model:pagination="pagination" :rows="store.permissions" :columns="columns" :loading="store.loading" row-key="id" :table-row-class-fn="permissionRowClass" @row-click="(_, row) => select(row)">
          <template #body-cell-code="props">
            <q-td :props="props">
              <button type="button" class="permissions-link" @click.stop="select(props.row)">
                <strong>{{ props.row.code }}</strong>
                <span>{{ props.row.description || 'Описание не указано' }}</span>
              </button>
            </q-td>
          </template>
          <template #body-cell-module="props"><q-td :props="props"><q-chip dense color="blue-1" text-color="blue-9">{{ props.row.module }}</q-chip></q-td></template>
          <template #body-cell-active="props"><q-td :props="props"><AppStatusBadge :label="props.row.active ? 'Активно' : 'Отключено'" :tone="props.row.active ? 'success' : 'neutral'" /></q-td></template>
        </AppTable>
        <AppEmptyState v-else title="Разрешения не найдены" description="Запустите сидеры или измените фильтры поиска." />
      </section>

      <WorkspaceSplitter
        v-if="selected"
        label="Изменить ширину карточки разрешения"
        @resize-start="startResize"
        @reset="resetSplitter"
      />

      <aside class="permissions-side">
        <AppCard v-if="selected" title="Карточка разрешения" subtitle="Назначение permission ролям">
          <div class="permissions-card-head">
            <div class="permissions-icon"><KeyRound :size="28" /></div>
            <div>
              <h3>{{ selected.name }}</h3>
              <p>{{ selected.code }}</p>
            </div>
          </div>
          <dl class="permissions-details">
            <div><dt>Модуль</dt><dd>{{ selected.module }}</dd></div>
            <div><dt>Описание</dt><dd>{{ selected.description || 'Описание не указано' }}</dd></div>
            <div><dt>Системное</dt><dd>{{ selected.system ? 'Да' : 'Нет' }}</dd></div>
          </dl>
          <q-select v-model="roleIds" outlined dense emit-value map-options multiple use-chips label="Роли с этим разрешением" :options="store.roleOptions" />
          <q-btn v-if="canManage" class="q-mt-md" color="primary" :loading="store.saving" @click="saveRoles"><Save :size="16" class="q-mr-xs" /> Сохранить роли</q-btn>
          <div class="permissions-assigned">
            <strong>Сейчас имеют доступ</strong>
            <q-list v-if="assignedRoles.length" dense bordered separator>
              <q-item v-for="role in assignedRoles" :key="role.id">
                <q-item-section>
                  <q-item-label>{{ role.name }}</q-item-label>
                  <q-item-label caption>{{ role.code }}</q-item-label>
                </q-item-section>
              </q-item>
            </q-list>
            <p v-else>Пока не назначено ни одной роли.</p>
          </div>
        </AppCard>
        <AppEmptyState v-else title="Разрешение не выбрано" description="Выберите permission в таблице." />
      </aside>
    </div>
  </AppPage>
</template>

<style scoped>
/* Пока разрешение не выбрано, список во всю ширину; вторую колонку задает
   разделитель через inline-стиль. */
.permissions-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 16px;
  align-items: start;
}
.permissions-main,
.permissions-side {
  min-width: 0;
}
.permissions-link {
  background: transparent;
  border: 0;
  color: #0f172a;
  cursor: pointer;
  display: grid;
  gap: 2px;
  padding: 0;
  text-align: left;
}
.permissions-link span,
.permissions-card-head p,
.permissions-details dt,
.permissions-assigned p {
  color: #64748b;
  font-size: 12px;
}
.permissions-card-head {
  align-items: center;
  display: flex;
  gap: 12px;
  margin-bottom: 14px;
}
.permissions-card-head h3 {
  font-size: 18px;
  font-weight: 700;
  margin: 0;
}
.permissions-card-head p {
  margin: 2px 0 0;
}
.permissions-icon {
  align-items: center;
  background: #eef6ff;
  border-radius: 8px;
  color: #1d4ed8;
  display: flex;
  height: 52px;
  justify-content: center;
  width: 52px;
}
.permissions-details {
  display: grid;
  gap: 10px;
  margin: 0 0 16px;
}
.permissions-details dd {
  margin: 0;
  overflow-wrap: anywhere;
}
.permissions-assigned {
  display: grid;
  gap: 10px;
  margin-top: 18px;
}
@media (max-width: 1200px) {
  .permissions-layout {
    grid-template-columns: 1fr;
  }
}
</style>
