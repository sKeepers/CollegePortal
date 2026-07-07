<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useQuasar } from 'quasar'
import { Edit, Plus, RefreshCw, ShieldCheck, Trash2, UserPlus } from '@lucide/vue'
import AppPage from '../../../components/ui/AppPage.vue'
import PageHeader from '../../../components/ui/PageHeader.vue'
import AppToolbar from '../../../components/ui/AppToolbar.vue'
import AppFilterBar from '../../../components/ui/AppFilterBar.vue'
import AppTable from '../../../components/ui/AppTable.vue'
import AppCard from '../../../components/ui/AppCard.vue'
import AppErrorBanner from '../../../components/ui/AppErrorBanner.vue'
import AppEmptyState from '../../../components/ui/AppEmptyState.vue'
import AppLoading from '../../../components/ui/AppLoading.vue'
import AppConfirmDialog from '../../../components/ui/AppConfirmDialog.vue'
import { useRolesStore } from '../../../stores/roles'

const store = useRolesStore()
const $q = useQuasar()
const formOpen = ref(false)
const assignOpen = ref(false)
const deleteDialog = ref(false)
const editingRole = ref(null)
const pendingRole = ref(null)
const roleForm = reactive({ name: '', code: '', description: '' })
const assignment = reactive({ user_id: null, role_ids: [], primary_role_id: null })
const pagination = ref({ rowsPerPage: 20 })

const columns = [
  { name: 'name', label: 'Роль', field: 'name', align: 'left', sortable: true },
  { name: 'code', label: 'Код', field: 'code', align: 'left', sortable: true },
  { name: 'users_count', label: 'Пользователей', field: 'users_count', align: 'left', sortable: true },
  { name: 'actions', label: '', field: 'actions', align: 'right' },
]

const selectedRole = computed(() => store.selectedRole)
const assignedUsers = computed(() => store.users.filter((user) => user.roles?.some((role) => Number(role.id) === Number(store.selectedId))))
const selectedAssignmentUser = computed(() => store.users.find((user) => Number(user.id) === Number(assignment.user_id)) || null)

function resetRoleForm() {
  Object.assign(roleForm, { name: '', code: '', description: '' })
}

function openCreate() {
  editingRole.value = null
  resetRoleForm()
  formOpen.value = true
}

function openEdit(role) {
  editingRole.value = role
  Object.assign(roleForm, {
    name: role.name || '',
    code: role.code || '',
    description: role.description || '',
  })
  formOpen.value = true
}

async function saveRole() {
  await store.save({ ...roleForm }, editingRole.value?.id || null)
  formOpen.value = false
  $q.notify({ type: 'positive', message: editingRole.value ? 'Роль обновлена' : 'Роль создана', position: 'top-right' })
}

function askDelete(role) {
  pendingRole.value = role
  deleteDialog.value = true
}

async function confirmDelete() {
  await store.remove(pendingRole.value)
  $q.notify({ type: 'positive', message: 'Роль удалена', position: 'top-right' })
}

function openAssign(user = null) {
  const targetUser = user || selectedAssignmentUser.value || store.users[0] || null
  assignment.user_id = targetUser?.id || null
  assignment.role_ids = targetUser?.roles?.map((role) => role.id) || []
  assignment.primary_role_id = targetUser?.role_id || assignment.role_ids[0] || null
  assignOpen.value = true
}

async function assignRoles() {
  await store.assignUserRoles(assignment.user_id, assignment.role_ids, assignment.primary_role_id)
  assignOpen.value = false
  $q.notify({ type: 'positive', message: 'Роли пользователя обновлены', position: 'top-right' })
}

function selectRole(role) {
  store.selectedId = role.id
}

onMounted(async () => {
  await store.load()
  if (!store.selectedId && store.roles[0]) {
    store.selectedId = store.roles[0].id
  }
})
</script>

<template>
  <AppPage>
    <PageHeader title="Роли" subtitle="MVP управления ролями пользователей и подготовка будущего RBAC." />
    <AppToolbar>
      <span>Роли задают базовую область доступа. Сложная матрица прав будет добавлена отдельным этапом.</span>
      <template #actions>
        <AppLoading v-if="store.loading || store.saving" label="Обработка..." />
        <q-btn flat :disable="store.loading" @click="store.load"><RefreshCw :size="16" class="q-mr-xs" /> Обновить</q-btn>
        <q-btn outline color="primary" @click="openAssign()"><UserPlus :size="16" class="q-mr-xs" /> Назначить</q-btn>
        <q-btn color="primary" @click="openCreate"><Plus :size="16" class="q-mr-xs" /> Создать</q-btn>
      </template>
    </AppToolbar>
    <AppErrorBanner :message="store.error" />

    <AppFilterBar>
      <q-input v-model="store.search" dense outlined clearable label="Поиск по названию, коду или описанию" @keyup.enter="store.load" />
      <q-btn color="primary" @click="store.load">Применить</q-btn>
      <q-btn flat @click="store.search = ''; store.load()">Сбросить</q-btn>
    </AppFilterBar>

    <div class="roles-layout">
      <section class="roles-main">
        <AppTable v-if="store.roles.length" v-model:pagination="pagination" :rows="store.roles" :columns="columns" :loading="store.loading">
          <template #body-cell-name="props">
            <q-td :props="props">
              <button class="roles-link" type="button" @click="selectRole(props.row)">
                <strong>{{ props.row.name }}</strong>
                <span>{{ props.row.description || 'Описание не указано' }}</span>
              </button>
            </q-td>
          </template>
          <template #body-cell-code="props">
            <q-td :props="props"><q-chip dense color="blue-1" text-color="blue-9">{{ props.row.code }}</q-chip></q-td>
          </template>
          <template #body-cell-users_count="props">
            <q-td :props="props">{{ props.row.users_count ?? 0 }}</q-td>
          </template>
          <template #body-cell-actions="props">
            <q-td :props="props" class="roles-actions">
              <q-btn flat dense round title="Редактировать" @click.stop="openEdit(props.row)"><Edit :size="16" /></q-btn>
              <q-btn flat dense round color="negative" title="Удалить" @click.stop="askDelete(props.row)"><Trash2 :size="16" /></q-btn>
            </q-td>
          </template>
        </AppTable>
        <AppEmptyState v-else title="Роли не найдены" description="Создайте роль или измените фильтр поиска." />
      </section>

      <aside class="roles-side">
        <AppCard v-if="selectedRole" title="Карточка роли" subtitle="Назначения и описание">
          <div class="roles-card-head">
            <div class="roles-icon"><ShieldCheck :size="28" /></div>
            <div>
              <h3>{{ selectedRole.name }}</h3>
              <p>{{ selectedRole.code }}</p>
            </div>
          </div>
          <p class="roles-description">{{ selectedRole.description || 'Описание не указано.' }}</p>
          <div class="roles-metric">
            <span>Пользователей</span>
            <strong>{{ selectedRole.users_count ?? assignedUsers.length }}</strong>
          </div>
          <div class="roles-users">
            <strong>Назначено пользователям</strong>
            <q-list v-if="assignedUsers.length" dense bordered separator>
              <q-item v-for="user in assignedUsers" :key="user.id">
                <q-item-section>
                  <q-item-label>{{ user.name }}</q-item-label>
                  <q-item-label caption>{{ user.email }}</q-item-label>
                </q-item-section>
                <q-item-section side>
                  <q-btn flat dense color="primary" @click="openAssign(user)">Изменить</q-btn>
                </q-item-section>
              </q-item>
            </q-list>
            <p v-else>Пока нет назначений.</p>
          </div>
        </AppCard>
        <AppEmptyState v-else title="Роль не выбрана" description="Выберите роль в таблице." />
      </aside>
    </div>

    <q-dialog v-model="formOpen">
      <q-card class="roles-dialog">
        <q-card-section><div class="text-h6">{{ editingRole ? 'Редактировать роль' : 'Создать роль' }}</div></q-card-section>
        <q-card-section class="roles-form">
          <q-input v-model="roleForm.name" outlined dense label="Название" />
          <q-input v-model="roleForm.code" outlined dense label="Код" />
          <q-input v-model="roleForm.description" outlined dense label="Описание" type="textarea" />
        </q-card-section>
        <q-card-actions align="right">
          <q-btn flat label="Отмена" v-close-popup />
          <q-btn color="primary" :loading="store.saving" label="Сохранить" @click="saveRole" />
        </q-card-actions>
      </q-card>
    </q-dialog>

    <q-dialog v-model="assignOpen">
      <q-card class="roles-dialog">
        <q-card-section><div class="text-h6">Назначить роли пользователю</div></q-card-section>
        <q-card-section class="roles-form">
          <q-select v-model="assignment.user_id" outlined dense emit-value map-options label="Пользователь" :options="store.userOptions" />
          <q-select v-model="assignment.role_ids" outlined dense emit-value map-options multiple use-chips label="Роли" :options="store.roleOptions" />
          <q-select v-model="assignment.primary_role_id" outlined dense emit-value map-options label="Основная роль" :options="store.roleOptions" />
        </q-card-section>
        <q-card-actions align="right">
          <q-btn flat label="Отмена" v-close-popup />
          <q-btn color="primary" :disable="!assignment.user_id || !assignment.role_ids.length" :loading="store.saving" label="Назначить" @click="assignRoles" />
        </q-card-actions>
      </q-card>
    </q-dialog>

    <AppConfirmDialog v-model="deleteDialog" title="Удалить роль" :message="`Удалить роль ${pendingRole?.name || ''}?`" confirm-label="Удалить" @confirm="confirmDelete" />
  </AppPage>
</template>

<style scoped>
.roles-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 400px;
  gap: 16px;
  align-items: start;
}

.roles-link {
  display: grid;
  gap: 2px;
  padding: 0;
  border: 0;
  background: transparent;
  color: #0f172a;
  text-align: left;
  cursor: pointer;
}

.roles-link span,
.roles-card-head p,
.roles-description,
.roles-users p {
  color: #64748b;
}

.roles-actions {
  white-space: nowrap;
}

.roles-card-head {
  display: flex;
  gap: 12px;
  align-items: center;
}

.roles-card-head h3 {
  margin: 0;
  font-size: 18px;
}

.roles-card-head p {
  margin: 4px 0 0;
}

.roles-icon {
  display: grid;
  place-items: center;
  width: 52px;
  height: 52px;
  border-radius: 8px;
  background: #eef2ff;
  color: #1d4ed8;
}

.roles-description {
  margin: 14px 0;
}

.roles-metric {
  display: grid;
  gap: 4px;
  padding: 12px;
  border-radius: 8px;
  background: #f8fafc;
}

.roles-metric span {
  color: #64748b;
}

.roles-metric strong {
  font-size: 24px;
}

.roles-users {
  display: grid;
  gap: 10px;
  margin-top: 16px;
}

.roles-dialog {
  width: min(640px, calc(100vw - 32px));
}

.roles-form {
  display: grid;
  gap: 12px;
}

@media (max-width: 1439px) {
  .roles-layout {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
