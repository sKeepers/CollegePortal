<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { formatPhone } from '../../utils/phone'
import { useQuasar } from 'quasar'
import { useRoute, useRouter } from 'vue-router'
import { Download, Edit3, KeyRound, Plus, RefreshCw, Trash2, Upload } from '@lucide/vue'
import WorkspaceBackBar from '../../components/workspace/WorkspaceBackBar.vue'
import AppPage from '../../components/ui/AppPage.vue'
import PageHeader from '../../components/ui/PageHeader.vue'
import AppToolbar from '../../components/ui/AppToolbar.vue'
import AppTable from '../../components/ui/AppTable.vue'
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'
import AppEmptyState from '../../components/ui/AppEmptyState.vue'
import AppLoading from '../../components/ui/AppLoading.vue'
import AppErrorBanner from '../../components/ui/AppErrorBanner.vue'
import AppConfirmDialog from '../../components/ui/AppConfirmDialog.vue'
import DeletionRequestDialog from '../../components/trash/DeletionRequestDialog.vue'
import TeacherDetailsPanel from './TeacherDetailsPanel.vue'
import TeacherFilters from './TeacherFilters.vue'
import TeacherFormPanel from './TeacherFormPanel.vue'
import { useTeachersStore } from '../../stores/teachers'
import { usePermissions } from '../../composables/usePermissions'
import {
  TABLE_ROWS_PER_PAGE_OPTIONS,
  createTablePagination,
  persistTablePagination,
} from '../../services/tableSettings'

const permissions = usePermissions()
const store = useTeachersStore()
const $q = useQuasar()
const route = useRoute()
const router = useRouter()
const TEACHERS_ROWS_PER_PAGE_KEY = 'collegePortal.teachers.rowsPerPage'
const syncingQueryFromUi = ref(false)
const canCreate = computed(() => permissions.hasPermission('teachers.create') || permissions.hasPermission('teachers.edit'))
const canUpdate = computed(() => permissions.hasPermission('teachers.update') || permissions.hasPermission('teachers.edit'))
// Удаление в два шага: помечает тот, кто ведёт карточку, удаляет администратор.
const canDelete = computed(() => permissions.hasPermission('trash.manage'))
const canRequestDeletion = computed(() => !canDelete.value && permissions.hasPermission('trash.request'))
const canImport = computed(() => canUpdate.value)
const canExport = computed(() => permissions.hasPermission('teachers.update') || permissions.hasPermission('teachers.edit') || permissions.hasPermission('teachers.view'))

const canIssueAccounts = computed(() => permissions.hasPermission('teachers.bulk_accounts'))

const accountsPreview = ref(null)
const accountsDialogVisible = ref(false)
const issuedCredentials = ref([])
const credentialsDialogVisible = ref(false)

const importFile = ref(null)
const formVisible = ref(false)
const editingTeacher = ref(null)
const deletingTeacher = ref(null)
const deleteDialogVisible = ref(false)
const deletionRequestTeacher = ref(null)
const deletionRequestVisible = ref(false)
const tablePagination = ref(createTablePagination(TEACHERS_ROWS_PER_PAGE_KEY, {
  sortBy: 'full_name',
  rowsPerPage: 20,
}))

const columns = [
  {
    name: 'full_name',
    label: 'ФИО',
    field: (row) => fullName(row),
    align: 'left',
    sortable: true,
  },
  {
    name: 'department',
    label: 'Отделение / должность',
    field: 'department',
    align: 'left',
    sortable: true,
  },
  {
    name: 'status',
    label: 'Статус',
    field: 'is_active',
    align: 'left',
    sortable: true,
  },
  {
    name: 'contacts',
    label: 'Контакты',
    field: 'email',
    align: 'left',
  },
  {
    name: 'actions',
    label: '',
    field: 'actions',
    align: 'right',
  },
]

const tableSubtitle = computed(() => (
  `Найдено преподавателей: ${store.filteredTeachers.length}`
))

function fullName(teacher) {
  return [teacher?.last_name, teacher?.first_name, teacher?.middle_name].filter(Boolean).join(' ')
}

function notifySuccess(message) {
  $q.notify({
    type: 'positive',
    message,
    position: 'top-right',
    timeout: 1800,
  })
}

function tableRowClass(row) {
  return Number(row.id) === Number(store.selectedId) ? 'teachers-row--selected' : ''
}

function updateTablePagination(pagination) {
  tablePagination.value = pagination
  persistTablePagination(TEACHERS_ROWS_PER_PAGE_KEY, pagination)
}

function routeSelectedId() {
  return route.params.id ? String(route.params.id) : ''
}

function routeSearchText() {
  return route.query.search ? String(route.query.search) : ''
}

function routeAction() {
  return route.query.action ? String(route.query.action) : ''
}

async function syncTeacherQuery({ selectedId = routeSelectedId(), searchText = routeSearchText() }) {
  const query = { ...route.query }

  if (searchText) {
    query.search = searchText
  } else {
    delete query.search
  }

  syncingQueryFromUi.value = true
  await router.replace({ path: selectedId ? `/teachers/${selectedId}` : '/teachers', query })
  syncingQueryFromUi.value = false
}

async function selectTeacher(teacher) {
  store.selectTeacher(teacher)
  await syncTeacherQuery({ selectedId: teacher?.id || '' })
}

function openCreateForm() {
  if (!canCreate.value) return
  editingTeacher.value = null
  formVisible.value = true
}

function openEditForm(teacher) {
  if (!canUpdate.value) return
  editingTeacher.value = teacher
  formVisible.value = true
}

async function saveTeacher(payload) {
  const isEdit = Boolean(editingTeacher.value?.id)
  await store.save(payload, editingTeacher.value?.id || null)
  formVisible.value = false
  editingTeacher.value = null
  notifySuccess(isEdit ? 'Преподаватель обновлен' : 'Преподаватель создан')
}

function requestDelete(teacher) {
  if (!canDelete.value) return
  deletingTeacher.value = teacher
  deleteDialogVisible.value = true
}

async function confirmDelete() {
  const name = deletingTeacher.value ? fullName(deletingTeacher.value) : 'Преподаватель'
  await store.remove(deletingTeacher.value)
  deletingTeacher.value = null
  notifySuccess(`${name}: запись удалена`)
}

function askDeletionRequest(teacher) {
  if (!canRequestDeletion.value) return
  deletionRequestTeacher.value = teacher
  deletionRequestVisible.value = true
}

function onDeletionRequested() {
  const name = deletionRequestTeacher.value ? fullName(deletionRequestTeacher.value) : 'Карточка'
  deletionRequestTeacher.value = null
  notifySuccess(`${name}: заявка на удаление отправлена администратору`)
}

async function applyFilters(filters) {
  store.setFilters(filters)
  await syncTeacherQuery({ selectedId: '', searchText: filters.search })
}

async function resetFilters() {
  store.resetFilters()
  await syncTeacherQuery({ selectedId: '', searchText: '' })
}

async function handleImport(file) {
  if (!canImport.value) return
  if (!file) {
    return
  }

  await store.importCsv(file)
  importFile.value = null
  notifySuccess('Импорт преподавателей завершен')
}

/**
 * Выдача учётных записей преподавателям разом.
 *
 * Сперва предпросмотр: он ничего не пишет и говорит, скольким запись достанется.
 * Потом подтверждение — и **логины с паролями показываются один раз**. Второго
 * раза не будет: в базе лежит хеш, а не пароль.
 */
async function openAccounts() {
  if (!canIssueAccounts.value) return

  try {
    accountsPreview.value = await store.previewAccounts()
    accountsDialogVisible.value = true
  } catch {
    $q.notify({ type: 'negative', message: store.error })
  }
}

async function confirmAccounts() {
  try {
    const result = await store.applyAccounts()
    accountsDialogVisible.value = false

    if (result?.credentials?.length) {
      issuedCredentials.value = result.credentials
      credentialsDialogVisible.value = true
    } else {
      notifySuccess('Выдавать было нечего: у всех уже есть учётные записи')
    }
  } catch {
    $q.notify({ type: 'negative', message: store.error })
  }
}

/**
 * Список выдачи файлом.
 *
 * Пароли показываются один раз, и человеку нужно успеть их сохранить. BOM в
 * начале — иначе Excel открывает кириллицу набором символов.
 */
function credentialsCsv() {
  const rows = [['ФИО', 'Логин', 'Пароль']]
  issuedCredentials.value.forEach((row) => rows.push([row.name, row.login, row.password]))
  const csv = '\ufeff' + rows.map((row) => row.map((cell) => `"${String(cell).replaceAll('"', '""')}"`).join(';')).join('\r\n')
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = `teacher-accounts-${new Date().toISOString().slice(0, 10)}.csv`
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
}

async function exportTeachers() {
  if (!canExport.value) return
  await store.exportCsv()
  notifySuccess('Экспорт преподавателей подготовлен')
}

watch(
  () => [route.params.id, route.query.search, route.query.action],
  async () => {
    if (syncingQueryFromUi.value) {
      return
    }

    store.setFilters({
      search: routeSearchText(),
    })
    store.selectTeacherById(routeSelectedId())

    if (routeAction() === 'create') {
      openCreateForm()
    }
  },
)

onMounted(async () => {
  store.setFilters({
    search: routeSearchText(),
  })
  store.selectTeacherById(routeSelectedId())
  await store.load()

  if (routeAction() === 'create') {
    openCreateForm()
  }
})
</script>

<template>
  <AppPage>
    <PageHeader
      title="Преподаватели"
      subtitle="Педагогический состав, контакты, отделения, CSV-обмен и быстрые переходы."
    >
      <template #actions>
        <q-btn v-if="canCreate" color="primary" @click="openCreateForm">
          <template #default>
            <Plus :size="16" />
            <span>Новый преподаватель</span>
          </template>
        </q-btn>
      </template>
    </PageHeader>

    <TeacherFilters
      :model-value="store.filters"
      :status-options="store.statusOptions"
      :department-options="store.departmentOptions"
      :loading="store.loading"
      @apply="applyFilters"
      @reset="resetFilters"
      @update:model-value="store.setFilters"
    />

    <AppToolbar>
      <span>{{ tableSubtitle }}</span>
      <template #actions>
        <AppLoading v-if="store.loading" label="Загрузка преподавателей..." />
        <q-file
          v-if="canImport"
          v-model="importFile"
          dense
          outlined
          clearable
          accept=".csv,text/csv,text/plain"
          class="teachers-import-field"
          label="Импорт"
          :disable="store.loading"
          @update:model-value="handleImport"
        >
          <template #prepend>
            <Upload :size="16" />
          </template>
          <q-tooltip>Загрузка файла CSV</q-tooltip>
        </q-file>
        <q-btn flat :disable="store.loading" @click="store.load">
          <template #default>
            <RefreshCw :size="16" />
            <span>Обновить</span>
          </template>
        </q-btn>
        <q-btn flat @click="resetSplitter">Сбросить размер</q-btn>
        <q-btn v-if="canExport" color="secondary" :disable="store.loading" @click="exportTeachers">
          <template #default>
            <Download :size="16" />
            <span>Экспорт</span>
            <q-tooltip>Скачать CSV</q-tooltip>
          </template>
        </q-btn>
        <q-btn v-if="canIssueAccounts" color="primary" :disable="store.loading" @click="openAccounts">
          <template #default>
            <KeyRound :size="16" />
            <span>Выдать учётные записи</span>
            <q-tooltip>Всем преподавателям без учётной записи. Пароли покажутся один раз.</q-tooltip>
          </template>
        </q-btn>
      </template>
    </AppToolbar>

    <AppErrorBanner :message="store.error" />

    <q-banner v-if="store.importSummary" rounded class="teachers-import-summary">
      <div>
        Импорт завершен:
        создано {{ store.importSummary.created }},
        обновлено {{ store.importSummary.updated }},
        строк с ошибками {{ store.importSummary.errors?.length || 0 }}.
      </div>
      <ul v-if="store.importSummary.errors?.length" class="teachers-import-summary__errors">
        <li v-for="errorRow in store.importSummary.errors" :key="errorRow.line">
          Строка {{ errorRow.line }}: {{ errorRow.messages?.join('; ') || 'ошибка импорта' }}
        </li>
      </ul>
    </q-banner>

    <div class="teachers-layout workspace-page" :class="{ 'workspace-page--card': Boolean(route.params.id) }">
      <div class="teachers-main workspace-page__list">
        <AppTable
          v-if="store.filteredTeachers.length || store.loading"
          :rows="store.filteredTeachers"
          :columns="columns"
          :loading="store.loading"
          :pagination="tablePagination"
          :rows-per-page-options="TABLE_ROWS_PER_PAGE_OPTIONS"
          :table-row-class-fn="tableRowClass"
          @update:pagination="updateTablePagination"
          :row-link="(row) => `/teachers/${row.id}`"
          @row-click="(_, row) => selectTeacher(row)"
        >
          <template #body-cell-full_name="props">
            <q-td :props="props">
              <button class="teachers-row-link" type="button" @click.stop="selectTeacher(props.row)">
                {{ fullName(props.row) }}
              </button>
            </q-td>
          </template>

          <template #body-cell-status="props">
            <q-td :props="props">
              <AppStatusBadge
                :label="props.row.is_active ? 'Активен' : 'Неактивен'"
                :tone="props.row.is_active ? 'success' : 'neutral'"
              />
            </q-td>
          </template>

          <template #body-cell-department="props">
            <q-td :props="props">
              <div class="teachers-secondary-cell">
                <span>{{ props.row.department || '—' }}</span>
                <small>{{ props.row.position || 'Должность не указана' }}</small>
              </div>
            </q-td>
          </template>

          <template #body-cell-contacts="props">
            <q-td :props="props">
              <div class="teachers-contact-cell">
                <span>{{ formatPhone(props.row.phone, "—") }}</span>
                <small>{{ props.row.email || '—' }}</small>
              </div>
            </q-td>
          </template>

          <template #body-cell-actions="props">
            <q-td :props="props">
              <div class="teachers-row-actions">
                <q-btn v-if="canUpdate" flat round dense title="Редактировать" @click.stop="openEditForm(props.row)">
                  <Edit3 :size="16" />
                </q-btn>
                <q-btn v-if="canDelete" flat round dense color="negative" title="Удалить" @click.stop="requestDelete(props.row)">
                  <Trash2 :size="16" />
                </q-btn>
                <q-btn v-else-if="canRequestDeletion" flat round dense color="negative" title="Пометить на удаление" @click.stop="askDeletionRequest(props.row)">
                  <Trash2 :size="16" />
                </q-btn>
              </div>
            </q-td>
          </template>
        </AppTable>

        <AppEmptyState
          v-else
          title="Преподаватели не найдены"
          description="Измените фильтры, импортируйте CSV или создайте преподавателя вручную."
        >
          <q-btn v-if="canCreate" color="primary" label="Новый преподаватель" @click="openCreateForm" />
        </AppEmptyState>
      </div>

      <aside class="teachers-side workspace-page__card">
        <WorkspaceBackBar />
        <TeacherDetailsPanel
          :teacher="store.selectedTeacher"
          :subjects="store.selectedTeacherSubjects"
          :lessons="store.selectedTeacherLessons"
        />
      </aside>
    </div>

    <q-dialog v-model="accountsDialogVisible">
      <q-card style="min-width: 420px">
        <q-card-section class="text-h6">Выдать учётные записи</q-card-section>
        <q-card-section>
          <div v-if="accountsPreview">
            Преподавателей всего: <b>{{ accountsPreview.selected }}</b>.<br/>
            Учётная запись будет выдана: <b>{{ accountsPreview.will_change }}</b>.<br/>
            Уже есть у: {{ accountsPreview.skipped }}.
          </div>
          <div class="text-caption text-orange-9 q-mt-sm">
            Логины и пароли покажутся <b>один раз</b>. Сохраните список сразу — второй раз
            портал их не покажет, в базе хранится не пароль.
          </div>
        </q-card-section>
        <q-card-actions align="right">
          <q-btn flat label="Отмена" v-close-popup />
          <q-btn
            color="primary"
            label="Выдать"
            :loading="store.saving"
            :disable="!accountsPreview?.will_change"
            @click="confirmAccounts"
          />
        </q-card-actions>
      </q-card>
    </q-dialog>

    <!--
      Окно нельзя закрыть мимо кнопки: пароли показываются один раз, и случайный
      щелчок по подложке стоил бы человеку всей выдачи.
    -->
    <q-dialog v-model="credentialsDialogVisible" persistent>
      <q-card style="min-width: 520px">
        <q-card-section class="text-h6">Учётные записи выданы</q-card-section>
        <q-card-section class="text-caption text-orange-9">
          Пароли показываются <b>один раз</b>. Скачайте список или перепишите его сейчас.
        </q-card-section>
        <q-card-section style="max-height: 50vh; overflow: auto">
          <q-markup-table flat bordered dense>
            <thead><tr><th class="text-left">ФИО</th><th class="text-left">Логин</th><th class="text-left">Пароль</th></tr></thead>
            <tbody>
              <tr v-for="row in issuedCredentials" :key="row.id">
                <td>{{ row.name }}</td>
                <td>{{ row.login }}</td>
                <td class="text-weight-medium">{{ row.password }}</td>
              </tr>
            </tbody>
          </q-markup-table>
        </q-card-section>
        <q-card-actions align="right">
          <q-btn color="secondary" icon="download" label="Скачать список" @click="credentialsCsv" />
          <q-btn flat label="Я сохранил" v-close-popup />
        </q-card-actions>
      </q-card>
    </q-dialog>

    <q-dialog v-model="formVisible" persistent>
      <div class="teachers-form-dialog">
        <TeacherFormPanel
          :teacher="editingTeacher"
          :saving="store.saving"
          @save="saveTeacher"
          @cancel="formVisible = false"
        />
      </div>
    </q-dialog>

    <AppConfirmDialog
      v-model="deleteDialogVisible"
      title="Удалить преподавателя?"
      :message="deletingTeacher ? `Будет удалена запись: ${fullName(deletingTeacher)}.` : 'Будет удалена выбранная запись.'"
      confirm-label="Удалить"
      tone="negative"
      @confirm="confirmDelete"
    />

    <DeletionRequestDialog
      v-model="deletionRequestVisible"
      subject-type="teacher"
      :subject-id="deletionRequestTeacher?.id ?? null"
      :subject-label="deletionRequestTeacher ? fullName(deletionRequestTeacher) : ''"
      @requested="onDeletionRequested"
    />
  </AppPage>
</template>

<style scoped>
.teachers-layout { gap: 0; }
.teachers-main, .teachers-side { min-width: 0; }
.teachers-main { padding-right: 10px; }
.teachers-side { max-width: none; padding-left: 10px; }
@media (max-width: 1100px) { .teachers-layout { grid-template-columns: 1fr !important; gap: 16px; } .teachers-main, .teachers-side { padding: 0; } }
</style>
