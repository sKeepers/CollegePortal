<script setup>
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { usePermissions } from '../../../composables/usePermissions'
import { useAuthStore } from '../../../stores/auth'
import { api } from '../../../services/api'
import { useQuasar } from 'quasar'
import { Ban, CheckCircle2, Edit, Eye, KeyRound, Plus, Printer, RefreshCw, ShieldCheck, Trash2, UserRound } from '@lucide/vue'
import AppPage from '../../../components/ui/AppPage.vue'
import PageHeader from '../../../components/ui/PageHeader.vue'
import AppToolbar from '../../../components/ui/AppToolbar.vue'
import AppFilterBar from '../../../components/ui/AppFilterBar.vue'
import AppTable from '../../../components/ui/AppTable.vue'
import AppCard from '../../../components/ui/AppCard.vue'
import AppErrorBanner from '../../../components/ui/AppErrorBanner.vue'
import AppEmptyState from '../../../components/ui/AppEmptyState.vue'
import AppLoading from '../../../components/ui/AppLoading.vue'
import AppStatusBadge from '../../../components/ui/AppStatusBadge.vue'
import AppConfirmDialog from '../../../components/ui/AppConfirmDialog.vue'
import { createTablePagination, persistTablePagination, TABLE_ROWS_PER_PAGE_OPTIONS } from '../../../services/tableSettings'
import { useUsersStore } from '../../../stores/users'
import { escapeHtml, printHtmlDocument } from '../../../utils/print'
import { formatDateTime as formatCollegeDateTime } from '../../../utils/datetime'

const rowsPerPageKey = 'collegePortal.users.rowsPerPage'
const store = useUsersStore()
const permissions = usePermissions()
const canManage = computed(() => permissions.hasPermission('users.manage'))
const canViewAs = computed(() => permissions.hasPermission('users.view_as'))
const auth = useAuthStore()
const router = useRouter()
const $q = useQuasar()
const portalUrl = window.location.origin
const formOpen = ref(false)
const formError = ref('')
const editingUser = ref(null)
const deleteDialog = ref(false)
const blockDialog = ref(false)
const unblockDialog = ref(false)
const resetPasswordDialog = ref(false)
const rolesDialog = ref(false)
const provisionDialog = ref(false)
const credentialDialog = ref(false)
const pendingUser = ref(null)
const pagination = ref(createTablePagination(rowsPerPageKey, { rowsPerPage: 20 }))
const rolesForm = reactive({ role_ids: [], primary_role_id: null })
const provisionForm = reactive({ profile_type: 'student', profile_id: null })
const credential = ref(null)
const nameInput = ref(null)
const emailInput = ref(null)
const passwordInput = ref(null)
const roleInput = ref(null)
const personIdInput = ref(null)
const form = reactive({
  name: '',
  email: '',
  password: '',
  role_id: null,
  is_active: true,
  person_type: null,
  person_id: null,
})
const formErrors = reactive({
  name: '',
  email: '',
  password: '',
  role_id: '',
  person_type: '',
  person_id: '',
})

const validationMessages = {
  nameRequired: 'Введите имя пользователя.',
  emailRequired: 'Введите email.',
  emailInvalid: 'Введите корректный email.',
  passwordRequired: 'Введите пароль.',
  passwordMin: 'Пароль должен содержать не менее 8 символов.',
  roleRequired: 'Выберите роль.',
}


const columns = [
  { name: 'name', label: 'Пользователь', field: 'name', align: 'left', sortable: true },
  { name: 'role', label: 'Роль', field: 'role', align: 'left' },
  { name: 'status', label: 'Статус', field: 'is_active', align: 'left' },
  { name: 'last_login_at', label: 'Последний вход', field: 'last_login_at', align: 'left' },
  { name: 'actions', label: '', field: 'actions', align: 'right' },
]

const selectedUser = computed(() => store.selectedUser)
const activeCount = computed(() => store.users.filter((user) => user.is_active).length)
const blockedCount = computed(() => store.users.filter((user) => !user.is_active).length)


function resetFormErrors() {
  Object.keys(formErrors).forEach((key) => {
    formErrors[key] = ''
  })
  formError.value = ''
}

function clearFieldError(field) {
  if (formErrors[field]) {
    formErrors[field] = ''
  }
  formError.value = ''
}

function isValidEmail(value) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)
}

function firstErrorField() {
  return ['name', 'email', 'password', 'role_id', 'person_id'].find((field) => Boolean(formErrors[field]))
}

async function focusFirstError() {
  await nextTick()
  const refs = {
    name: nameInput,
    email: emailInput,
    password: passwordInput,
    role_id: roleInput,
    person_id: personIdInput,
  }
  const field = firstErrorField()
  refs[field]?.value?.focus?.()
}

function validateUserForm() {
  resetFormErrors()
  if (!form.name?.trim()) {
    formErrors.name = validationMessages.nameRequired
  }
  if (!form.email?.trim()) {
    formErrors.email = validationMessages.emailRequired
  } else if (!isValidEmail(form.email.trim())) {
    formErrors.email = validationMessages.emailInvalid
  }
  if (!editingUser.value && !form.password?.trim()) {
    formErrors.password = validationMessages.passwordRequired
  } else if (form.password?.trim() && form.password.trim().length < 8) {
    formErrors.password = validationMessages.passwordMin
  }
  if (!form.role_id) {
    formErrors.role_id = validationMessages.roleRequired
  }

  if (firstErrorField()) {
    focusFirstError()
    return false
  }
  return true
}

function applyServerErrors(errors = {}) {
  resetFormErrors()
  Object.entries(errors).forEach(([field, messages]) => {
    const target = field === 'role' ? 'role_id' : field
    if (Object.prototype.hasOwnProperty.call(formErrors, target)) {
      formErrors[target] = Array.isArray(messages) ? messages[0] : String(messages || '')
    }
  })
  focusFirstError()
}

function statusLabel(user) {
  return user?.is_active ? 'Активен' : 'Заблокирован'
}

function statusTone(user) {
  return user?.is_active ? 'success' : 'danger'
}

function formatDate(value) {
  return formatCollegeDateTime(value)
}

function rolesLabel(user) {
  return user?.roles?.length ? user.roles.map((role) => role.name).join(', ') : (user?.role?.name || 'Роль не указана')
}

function roleChipTone(role, user) {
  return Number(role.id) === Number(user?.role_id) ? 'primary' : 'blue-1'
}

function roleChipText(role, user) {
  return Number(role.id) === Number(user?.role_id) ? 'white' : 'blue-9'
}

function personTypeLabel(type) {
  return store.personTypeOptions.find((item) => item.value === type)?.label || 'Не связана'
}

function resetForm() {
  resetFormErrors()
  Object.assign(form, {
    name: '',
    email: '',
    password: '',
    role_id: store.roleOptions[0]?.value || null,
    is_active: true,
    person_type: null,
    person_id: null,
  })
}

function openCreate() {
  editingUser.value = null
  resetForm()
  formOpen.value = true
  nextTick(() => nameInput.value?.focus?.())
}

function openEdit(user) {
  editingUser.value = user
  resetFormErrors()
  Object.assign(form, {
    name: user.name || '',
    email: user.email || '',
    password: '',
    role_id: user.role_id || null,
    is_active: Boolean(user.is_active),
    person_type: user.person_type || null,
    person_id: user.person_id || null,
  })
  formOpen.value = true
}

async function saveUser() {
  if (!validateUserForm()) return

  try {
    await store.save({ ...form }, editingUser.value?.id || null)
    resetFormErrors()
    formOpen.value = false
    $q.notify({ type: 'positive', message: editingUser.value ? 'Пользователь обновлен' : 'Пользователь создан', position: 'top-right' })
  } catch (err) {
    if (err.status === 422 && err.errors) {
      applyServerErrors(err.errors)
      return
    }
    formError.value = err.message || 'Не удалось сохранить пользователя'
  }
}

function askDelete(user) {
  pendingUser.value = user
  deleteDialog.value = true
}

/**
 * Открыть портал глазами выбранного человека.
 *
 * Подтверждения нет намеренно: режим ничего не меняет и снимается одной
 * кнопкой на полосе, которая после этого висит на каждом экране. Лишний
 * вопрос здесь только приучал бы жать «да» не глядя.
 *
 * Уходим на главную, а не остаёмся в списке учётных записей: под чужими
 * глазами его почти наверняка не видно, и человек упёрся бы в отказ сразу
 * после входа в режим.
 */
async function askViewAs(user) {
  try {
    await api.viewAsStart(user.id)
    await auth.restore()
    router.push('/')
  } catch (error) {
    $q.notify({ type: 'negative', message: error?.message || 'Не удалось открыть портал глазами этого человека.' })
  }
}

function askBlock(user) {
  pendingUser.value = user
  blockDialog.value = true
}

function askUnblock(user) {
  pendingUser.value = user
  unblockDialog.value = true
}

/**
 * Задать пароль названной записи.
 *
 * На своей строке кнопки нет: выданный пароль показывается один раз, а смена
 * пароля спрашивает текущий — не записав показанное, человек закрыл бы вход
 * себе. Сервер отказывает в том же случае и по той же причине: кнопки может не
 * быть, а ручка остаётся, и проверка на месте нужна обеим сторонам.
 */
function askResetPassword(user) {
  pendingUser.value = user
  resetPasswordDialog.value = true
}

async function confirmResetPassword() {
  try {
    credential.value = await store.resetPassword(pendingUser.value)
    credentialDialog.value = true
  } catch (error) {
    $q.notify({ type: 'negative', message: error?.message || 'Не удалось сбросить пароль', position: 'top-right' })
  }
}

async function confirmDelete() {
  await store.remove(pendingUser.value)
  $q.notify({ type: 'positive', message: 'Пользователь удален', position: 'top-right' })
}

async function confirmBlock() {
  await store.block(pendingUser.value)
  $q.notify({ type: 'warning', message: 'Пользователь заблокирован', position: 'top-right' })
}

async function confirmUnblock() {
  await store.unblock(pendingUser.value)
  $q.notify({ type: 'positive', message: 'Пользователь разблокирован', position: 'top-right' })
}

function openRolesDialog(user = selectedUser.value) {
  pendingUser.value = user
  rolesForm.role_ids = user?.roles?.length ? user.roles.map((role) => role.id) : (user?.role_id ? [user.role_id] : [])
  rolesForm.primary_role_id = user?.role_id || rolesForm.role_ids[0] || null
  rolesDialog.value = true
}

async function saveRoles() {
  await store.assignRoles(pendingUser.value, rolesForm.role_ids, rolesForm.primary_role_id)
  rolesDialog.value = false
  $q.notify({ type: 'positive', message: 'Роли пользователя обновлены', position: 'top-right' })
}

const profileOptions = ref([])
const profileSearchLoading = ref(false)

/*
 * Логин и email — разные вещи. Когда учётную запись заводит портал, логином
 * становится телефон, а email при его отсутствии достраивается служебным
 * адресом @accounts.collegeportal.local. Показывать этот адрес как почту
 * человека нельзя: письма туда не ходят.
 */
const SERVICE_EMAIL_DOMAIN = '@accounts.collegeportal.local'

function loginOf(user) {
  return user?.username || user?.email || '—'
}

function realEmailOf(user) {
  const email = user?.email || ''
  return !email || email.endsWith(SERVICE_EMAIL_DOMAIN) ? 'не указан' : email
}
const personProfileOptions = ref([])
const personSearchLoading = ref(false)
const isSearchablePersonType = computed(() => ['person', 'student', 'teacher', 'employee'].includes(form.person_type))

function filterPersonProfiles(input, update) {
  personSearchLoading.value = true
  store.searchProfiles(form.person_type, input || '')
    .then((options) => update(() => { personProfileOptions.value = options }))
    .catch(() => update(() => { personProfileOptions.value = [] }))
    .finally(() => { personSearchLoading.value = false })
}

function onPersonTypeChange() {
  form.person_id = null
  personProfileOptions.value = []
}

/*
 * Выбранная карточка — источник данных, а не отдельная сущность: ФИО и почта
 * подставляются из нее, иначе одно и то же приходится вводить дважды.
 */
function onPersonProfileChange(personId) {
  const option = personProfileOptions.value.find((item) => Number(item.value) === Number(personId))
  if (!option) {
    return
  }

  if (option.fullName) {
    form.name = option.fullName
  }
  if (option.email && !form.email) {
    form.email = option.email
  }
}

// Список людей подгружается поиском: справочники слишком велики для полного списка.
function filterProfiles(input, update) {
  profileSearchLoading.value = true
  store.searchProfiles(provisionForm.profile_type, input || '')
    .then((options) => update(() => { profileOptions.value = options }))
    .catch(() => update(() => { profileOptions.value = [] }))
    .finally(() => { profileSearchLoading.value = false })
}

function onProvisionTypeChange() {
  provisionForm.profile_id = null
  profileOptions.value = []
}

async function provisionAccount() {
  credential.value = await store.provision(provisionForm.profile_type, provisionForm.profile_id)
  provisionDialog.value = false
  credentialDialog.value = true
}

/**
 * Карточка доступа печатается отдельным документом, а не страницей.
 *
 * До 28.08.2026 здесь стоял голый `window.print()`, а печатных стилей в файле
 * не было вовсе — и глобальных в портале тоже нет. Замерено в браузере при
 * `emulateMedia('print')`: на `/admin/users` боковая панель **видна**, таблица
 * **видна**, в ней двадцать строк. То есть оператор, печатавший логин и пароль
 * **одного** человека, уносил с принтера меню, фильтры и список чужих людей —
 * не выбирал этого, на экране не видел и замечал, только когда лист уже вышел.
 *
 * Лечится не печатным блоком, а тем, что печатать перестают страницу: у
 * `@media print` здесь не было бы даже того слабого щита, что у карточек
 * студентов, а следующий `scoped`-блок перебил бы его молча. Отдельный документ
 * не перебивается по построению — каскад приложения в него не приходит.
 *
 * Даты на листке нет намеренно: её пришлось бы печатать часами браузера без
 * пояса, а карточку отдают из рук в руки сразу.
 */
function printCredential() {
  if (!credential.value) return

  const row = credential.value

  printHtmlDocument(`<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Карточка доступа</title>
<style>
  @page { size: A4 portrait; margin: 18mm; }
  body { margin: 0; color: #000; font-family: Arial, "Helvetica Neue", Helvetica, sans-serif; font-size: 14px; }
  h1 { font-size: 16px; margin: 0 0 12px; }
  .card { border: 1px solid #000; border-radius: 6px; padding: 14px 16px; max-width: 120mm; }
  .name { font-size: 15px; font-weight: bold; margin-bottom: 8px; }
  dl { display: grid; grid-template-columns: max-content 1fr; gap: 4px 12px; margin: 0; }
  dt { color: #333; }
  dd { margin: 0; font-family: "Courier New", Courier, monospace; font-weight: bold; }
  .hint { margin-top: 12px; font-size: 12px; }
</style>
</head>
<body>
<h1>Карточка доступа</h1>
<div class="card">
  <div class="name">${escapeHtml(row.name)}</div>
  <dl>
    <dt>Роль</dt><dd>${escapeHtml(row.role_name || row.role)}</dd>
    <dt>Логин</dt><dd>${escapeHtml(row.login)}</dd>
    <dt>Стартовый пароль</dt><dd>${escapeHtml(row.password)}</dd>
    <dt>Вход</dt><dd>${escapeHtml(portalUrl)}</dd>
  </dl>
  <div class="hint">Смените пароль после первого входа. Пароль показывается один раз и не сохраняется.</div>
</div>
</body>
</html>`)
}

function applyFilters() {
  store.load()
}

function resetFilters() {
  store.resetFilters()
  store.load()
}

function rowClass(row) {
  return Number(row.id) === Number(store.selectedId) ? 'cp-selected-row' : ''
}

/**
 * Адрес карточки, связанной с учётной записью.
 *
 * Здесь две поправки разом. Первая: идентификатор идёт в пути, а не в
 * `?selected=`, который получатель не читает, — раньше кнопка открывала список.
 *
 * Вторая важнее и незаметнее. `person_id` у записи — это идентификатор
 * **человека**, а разделы адресуют карточку идентификатором **профиля**: у
 * одного и того же человека это разные числа, и ссылка открыла бы чужую
 * карточку с уверенным видом. Правильный источник — `person.id`, он уже
 * разрешён в профиль на стороне ответа. Признак того, что разрешён: заполнено
 * `person.name`; без профиля ответ подставляет туда идентификатор человека, и
 * такую ссылку строить нельзя.
 */
function openPerson(user) {
  const profile = user?.person
  if (!profile?.id || !profile?.name) return null
  if (profile.type === 'student') return `/students/${profile.id}`
  if (profile.type === 'teacher') return `/teachers/${profile.id}`
  return null
}

watch(() => form.name, () => clearFieldError('name'))
watch(() => form.email, () => clearFieldError('email'))
watch(() => form.password, () => clearFieldError('password'))
watch(() => form.role_id, () => clearFieldError('role_id'))
watch(() => form.person_type, () => clearFieldError('person_type'))
watch(() => form.person_id, () => clearFieldError('person_id'))
watch(formOpen, (open) => {
  if (!open) {
    resetFormErrors()
  }
})
watch(pagination, (value) => persistTablePagination(rowsPerPageKey, value), { deep: true })
// Карточка открывается по выбору человека, а не сама.
//
// Раздел, открывающийся первой строкой, показывает каждому заходящему **чужие
// личные данные**: у пропусков это фамилия, QR-код и токен, у учётных записей —
// чужой логин и роль. Владелец 29.08.2026 открыл «Цифровые пропуска» и увидел
// карточку постороннего человека; у выпускников то же самое чинилось 28.08, и
// тогда мы решили, что случай единичный.
onMounted(async () => {
  await store.load()
})
</script>

<template>
  <AppPage>
    <PageHeader title="Пользователи" subtitle="Управление учётными записями CollegePortal: доступ, статус, роль и связь с личной карточкой." />
    <AppToolbar>
      <span>Роли на этом этапе используются как основа RBAC, без сложных сценариев делегирования.</span>
      <template #actions>
        <AppLoading v-if="store.loading || store.saving" label="Обработка..." />
        <q-btn flat :disable="store.loading" @click="store.load"><RefreshCw :size="16" class="q-mr-xs" /> Обновить</q-btn>
        <q-btn v-if="canManage" color="primary" title="Завести учётную запись вручную: вы сами задаете email и пароль" @click="openCreate"><Plus :size="16" class="q-mr-xs" /> Создать вручную</q-btn>
        <q-btn v-if="canManage" outline color="primary" title="Завести учётную запись человеку, который уже есть в системе: логин и пароль портал придумает сам" @click="provisionDialog = true"><KeyRound :size="16" class="q-mr-xs" /> Создать по профилю</q-btn>
      </template>
    </AppToolbar>
    <AppErrorBanner :message="store.error" />

    <AppFilterBar>
      <q-input v-model="store.filters.search" dense outlined clearable label="Поиск по имени или email" @keyup.enter="applyFilters" />
      <q-select v-model="store.filters.status" dense outlined clearable emit-value map-options label="Статус" :options="store.statusOptions" />
      <q-btn color="primary" @click="applyFilters">Применить</q-btn>
      <q-btn flat @click="resetFilters">Сбросить</q-btn>
    </AppFilterBar>

    <div class="users-layout">
      <section class="users-main">
        <div class="users-stats">
          <AppCard title="Всего" :subtitle="`${store.users.length}`" />
          <AppCard title="Активные" :subtitle="`${activeCount}`" />
          <AppCard title="Заблокированные" :subtitle="`${blockedCount}`" />
        </div>

        <AppTable
          v-if="store.users.length"
          v-model:pagination="pagination"
          :rows="store.users"
          :columns="columns"
          :loading="store.loading"
          :rows-per-page-options="TABLE_ROWS_PER_PAGE_OPTIONS"
          :table-row-class-fn="rowClass"
        >
          <template #body-cell-name="props">
            <q-td :props="props">
              <button class="users-link" type="button" @click="store.selectedId = props.row.id">
                <strong>{{ props.row.name }}</strong>
                <span>{{ loginOf(props.row) }}</span>
              </button>
            </q-td>
          </template>
          <template #body-cell-role="props">
            <q-td :props="props">{{ rolesLabel(props.row) }}</q-td>
          </template>
          <template #body-cell-status="props">
            <q-td :props="props"><AppStatusBadge :label="statusLabel(props.row)" :tone="statusTone(props.row)" /></q-td>
          </template>
          <template #body-cell-last_login_at="props">
            <q-td :props="props">{{ formatDate(props.row.last_login_at) }}</q-td>
          </template>
          <template #body-cell-actions="props">
            <q-td :props="props" class="users-actions">
              <q-btn v-if="canViewAs && props.row.is_active" flat dense round color="purple-9" title="Смотреть портал глазами этого человека (только просмотр)" @click.stop="askViewAs(props.row)"><Eye :size="16" /></q-btn>
              <q-btn v-if="canManage" flat dense round title="Редактировать" @click.stop="openEdit(props.row)"><Edit :size="16" /></q-btn>
              <q-btn v-if="canManage && props.row.is_active && props.row.id !== auth.user?.id" flat dense round color="primary" title="Задать этой записи новый пароль и показать карточку доступа" @click.stop="askResetPassword(props.row)"><KeyRound :size="16" /></q-btn>
              <q-btn v-if="canManage && props.row.is_active" flat dense round color="warning" title="Заблокировать" @click.stop="askBlock(props.row)"><Ban :size="16" /></q-btn>
              <q-btn v-else-if="canManage" flat dense round color="positive" title="Разблокировать" @click.stop="askUnblock(props.row)"><CheckCircle2 :size="16" /></q-btn>
              <q-btn v-if="canManage" flat dense round color="negative" title="Удалить" @click.stop="askDelete(props.row)"><Trash2 :size="16" /></q-btn>
            </q-td>
          </template>
        </AppTable>
        <AppEmptyState v-else title="Пользователи не найдены" description="Измените фильтры или создайте новую учётную запись." />
      </section>

      <aside class="users-side">
        <AppCard v-if="selectedUser" title="Карточка пользователя" subtitle="Доступ и связь с личной карточкой">
          <div class="users-card-head">
            <div class="users-avatar"><UserRound :size="28" /></div>
            <div>
              <h3>{{ selectedUser.name }}</h3>
              <p>{{ loginOf(selectedUser) }}</p>
            </div>
          </div>
          <div class="users-card-status">
            <AppStatusBadge :label="statusLabel(selectedUser)" :tone="statusTone(selectedUser)" />
            <q-chip v-for="role in selectedUser.roles || []" :key="role.id" dense :color="roleChipTone(role, selectedUser)" :text-color="roleChipText(role, selectedUser)">{{ role.name }}</q-chip>
            <q-chip v-if="!selectedUser.roles?.length" dense color="blue-1" text-color="blue-9">{{ selectedUser.role?.name || 'Роль не указана' }}</q-chip>
          </div>

          <dl class="users-fields">
            <dt>Логин для входа</dt>
            <dd>{{ loginOf(selectedUser) }}</dd>
            <dt>Email</dt>
            <dd>{{ realEmailOf(selectedUser) }}</dd>
            <dt>Связан с карточкой</dt>
            <dd>
              <template v-if="selectedUser.person?.name">{{ selectedUser.person.name }} · {{ personTypeLabel(selectedUser.person_type) }}</template>
              <template v-else-if="selectedUser.person_type">{{ personTypeLabel(selectedUser.person_type) }} №{{ selectedUser.person_id }}</template>
              <template v-else>Не связана</template>
            </dd>
            <dt>Последний вход</dt>
            <dd>{{ formatDate(selectedUser.last_login_at) }}</dd>
            <dt>Создан</dt>
            <dd>{{ formatDate(selectedUser.created_at) }}</dd>
          </dl>

          <div class="users-card-actions">
            <q-btn v-if="canManage" outline color="primary" @click="openEdit(selectedUser)"><Edit :size="16" class="q-mr-xs" /> Редактировать</q-btn>
            <q-btn v-if="canManage" outline color="primary" @click="openRolesDialog(selectedUser)"><ShieldCheck :size="16" class="q-mr-xs" /> Роли</q-btn>
            <q-btn v-if="canManage && selectedUser.is_active && selectedUser.id !== auth.user?.id" outline color="primary" @click="askResetPassword(selectedUser)"><KeyRound :size="16" class="q-mr-xs" /> Сбросить пароль</q-btn>
            <q-btn v-if="canManage && selectedUser.is_active" outline color="warning" @click="askBlock(selectedUser)"><Ban :size="16" class="q-mr-xs" /> Заблокировать</q-btn>
            <q-btn v-else-if="canManage" outline color="positive" @click="askUnblock(selectedUser)"><CheckCircle2 :size="16" class="q-mr-xs" /> Разблокировать</q-btn>
            <q-btn v-if="openPerson(selectedUser)" flat color="primary" :to="openPerson(selectedUser)">Открыть связанную карточку</q-btn>
          </div>
        </AppCard>
        <AppEmptyState v-else title="Пользователь не выбран" description="Выберите строку в таблице, чтобы открыть карточку." />
      </aside>
    </div>

    <q-dialog v-model="formOpen">
      <q-card class="users-dialog">
        <q-card-section>
          <div class="text-h6">{{ editingUser ? 'Редактировать пользователя' : 'Создать учётную запись вручную' }}</div>
          <p v-if="!editingUser" class="users-dialog-subtitle">
            Email и пароль задаете вы, и их нужно передать человеку самостоятельно. Если человек уже заведен
            как студент, преподаватель или сотрудник, быстрее и надежнее «Создать по профилю»: там логин
            и пароль генерируются, а вместе с учётной записью выпускается QR-пропуск.
          </p>
        </q-card-section>
        <q-card-section class="users-form">
          <AppErrorBanner v-if="formError" :message="formError" />
          <q-input ref="nameInput" v-model="form.name" outlined dense label="ФИО *" hint="Как показывать человека в списках. Это не логин. Если ниже выбрана карточка, подставляется из нее." :error="Boolean(formErrors.name)" :error-message="formErrors.name" bottom-slots />
          <q-input ref="emailInput" v-model="form.email" outlined dense label="Email *" type="email" hint="Он же логин для входа при ручном создании." :error="Boolean(formErrors.email)" :error-message="formErrors.email" bottom-slots />
          <q-input ref="passwordInput" v-model="form.password" outlined dense :label="editingUser ? 'Новый пароль, если нужно' : 'Пароль *'" type="password" :error="Boolean(formErrors.password)" :error-message="formErrors.password" bottom-slots />
          <q-select ref="roleInput" v-model="form.role_id" outlined dense emit-value map-options label="Роль *" :options="store.roleOptions" :error="Boolean(formErrors.role_id)" :error-message="formErrors.role_id" bottom-slots />
          <q-select v-model="form.person_type" outlined dense clearable emit-value map-options label="Связать с карточкой" hint="Нужно, чтобы человек видел свое расписание, журнал и пропуск" :options="store.personTypeOptions" :error="Boolean(formErrors.person_type)" :error-message="formErrors.person_type" bottom-slots @update:model-value="onPersonTypeChange" />
          <q-select
            v-if="isSearchablePersonType"
            v-model="form.person_id"
            outlined
            dense
            clearable
            emit-value
            map-options
            use-input
            input-debounce="300"
            label="Кто это"
            hint="Начните вводить фамилию"
            :options="personProfileOptions"
            :loading="personSearchLoading"
            :error="Boolean(formErrors.person_id)"
            :error-message="formErrors.person_id"
            bottom-slots
            @filter="filterPersonProfiles"
            @update:model-value="onPersonProfileChange"
          >
            <template #no-option>
              <q-item><q-item-section class="text-grey">Никого не нашли. Проверьте, заведен ли человек в своем разделе.</q-item-section></q-item>
            </template>
          </q-select>
          <q-input v-else-if="form.person_type" ref="personIdInput" v-model.number="form.person_id" outlined dense clearable label="Связанная запись" type="number" hint="Для этого типа выбор из списка пока недоступен: укажите ID карточки." :error="Boolean(formErrors.person_id)" :error-message="formErrors.person_id" bottom-slots />
          <q-toggle v-model="form.is_active" label="Активен" />
        </q-card-section>
        <q-card-actions align="right">
          <q-btn flat label="Отмена" v-close-popup />
          <q-btn v-if="canManage" color="primary" :loading="store.saving" label="Сохранить" @click="saveUser" />
        </q-card-actions>
      </q-card>
    </q-dialog>


    <q-dialog v-model="rolesDialog">
      <q-card class="users-dialog">
        <q-card-section>
          <div class="text-h6">Назначить роли</div>
          <p class="users-dialog-subtitle">{{ pendingUser?.name }}</p>
        </q-card-section>
        <q-card-section class="users-form">
          <q-select v-model="rolesForm.role_ids" outlined dense emit-value map-options multiple use-chips label="Роли" :options="store.roleOptions" />
          <q-select v-model="rolesForm.primary_role_id" outlined dense emit-value map-options label="Основная роль" :options="store.roleOptions" />
        </q-card-section>
        <q-card-actions align="right">
          <q-btn flat label="Отмена" v-close-popup />
          <q-btn v-if="canManage" color="primary" :disable="!rolesForm.role_ids.length" :loading="store.saving" label="Сохранить роли" @click="saveRoles" />
        </q-card-actions>
      </q-card>
    </q-dialog>

    <q-dialog v-model="provisionDialog" persistent>
      <q-card class="users-dialog">
        <q-card-section>
          <div class="text-h6">Создать учётную запись по профилю</div>
          <p class="users-dialog-subtitle">
            Для человека, который уже заведен в системе как студент, преподаватель или сотрудник.
            Логин и пароль портал придумает сам: логином станет телефон, при его отсутствии — email,
            пароль будет пятизначным. Обе строки показываются один раз в карточке доступа, распечатайте ее сразу.
            Вместе с учётной записью выпускается QR-пропуск.
          </p>
        </q-card-section>
        <q-card-section class="users-form">
          <q-select v-model="provisionForm.profile_type" outlined dense emit-value map-options label="Кого заводим" :options="[{ label: 'Студент', value: 'student' }, { label: 'Преподаватель', value: 'teacher' }, { label: 'Сотрудник', value: 'employee' }]" @update:model-value="onProvisionTypeChange" />
          <q-select
            v-model="provisionForm.profile_id"
            outlined
            dense
            emit-value
            map-options
            use-input
            input-debounce="300"
            label="Человек"
            hint="Начните вводить фамилию"
            :options="profileOptions"
            :loading="profileSearchLoading"
            @filter="filterProfiles"
          >
            <template #no-option>
              <q-item><q-item-section class="text-grey">Никого не нашли. Проверьте, заведен ли человек в своем разделе.</q-item-section></q-item>
            </template>
          </q-select>
        </q-card-section>
        <q-card-actions align="right"><q-btn flat label="Отмена" v-close-popup /><q-btn color="primary" :disable="!provisionForm.profile_id" :loading="store.saving" label="Создать и показать карточку" @click="provisionAccount" /></q-card-actions>
      </q-card>
    </q-dialog>

    <q-dialog v-model="credentialDialog" persistent>
      <q-card class="users-dialog credential-card">
        <q-card-section><div class="text-h6">Карточка доступа</div><p class="users-dialog-subtitle">Распечатайте или передайте данные сейчас: пароль не сохраняется и повторно не отображается.</p></q-card-section>
        <q-card-section v-if="credential" class="credential-card__body"><strong>{{ credential.name }}</strong><span>Роль: {{ credential.role_name || credential.role }}</span><span>Логин: <b>{{ credential.login }}</b></span><span>Стартовый пароль: <b>{{ credential.password }}</b></span><span>Вход: {{ portalUrl }}</span></q-card-section>
        <q-card-actions align="right"><q-btn flat label="Закрыть" @click="credentialDialog = false; credential = null" /><q-btn color="primary" @click="printCredential"><Printer :size="16" class="q-mr-xs" />Печать</q-btn></q-card-actions>
      </q-card>
    </q-dialog>

    <AppConfirmDialog v-model="deleteDialog" title="Удалить пользователя" :message="`Удалить учётную запись ${pendingUser?.name || ''}?`" confirm-label="Удалить" @confirm="confirmDelete" />
    <AppConfirmDialog v-model="blockDialog" title="Заблокировать пользователя" :message="`Заблокировать ${pendingUser?.name || ''}? Пользователь не сможет войти в систему.`" confirm-label="Заблокировать" tone="warning" @confirm="confirmBlock" />
    <AppConfirmDialog v-model="unblockDialog" title="Разблокировать пользователя" :message="`Разблокировать ${pendingUser?.name || ''}?`" confirm-label="Разблокировать" tone="success" @confirm="confirmUnblock" />
    <AppConfirmDialog v-model="resetPasswordDialog" title="Сбросить пароль" :message="`Выдать новый пароль для ${pendingUser?.name || ''}? Прежний перестанет работать сразу, а новый портал покажет один раз — записать или распечатать его нужно до закрытия карточки.`" confirm-label="Сбросить" tone="warning" @confirm="confirmResetPassword" />
  </AppPage>
</template>

<style scoped>
.users-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 400px;
  gap: 16px;
  align-items: start;
}

.users-main,
.users-side {
  display: grid;
  gap: 16px;
}

.users-stats {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
}

.users-link {
  display: grid;
  gap: 2px;
  padding: 0;
  border: 0;
  background: transparent;
  color: #0f172a;
  text-align: left;
  cursor: pointer;
}

.users-link span {
  color: #64748b;
  font-size: 12px;
}

.users-actions {
  white-space: nowrap;
}

.users-card-head {
  display: flex;
  gap: 12px;
  align-items: center;
}

.users-avatar {
  display: grid;
  place-items: center;
  width: 52px;
  height: 52px;
  border-radius: 8px;
  background: #eef2ff;
  color: #1d4ed8;
}

.users-card-head h3 {
  margin: 0;
  font-size: 18px;
}

.users-card-head p {
  margin: 4px 0 0;
  color: #64748b;
}

.users-card-status,
.users-card-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 14px;
}

.users-fields {
  display: grid;
  grid-template-columns: 150px 1fr;
  gap: 8px 12px;
  margin: 16px 0 0;
}

.users-fields dt {
  color: #64748b;
}

.users-fields dd {
  margin: 0;
  overflow-wrap: anywhere;
}

.users-dialog {
  width: min(640px, calc(100vw - 32px));
}

.users-form {
  display: grid;
  gap: 12px;
}

.users-dialog-subtitle {
  margin: 4px 0 0;
  color: #64748b;
}

.credential-card__body { display: grid; gap: 10px; font-size: 16px; }

:deep(.cp-selected-row) {
  background: #eff6ff;
}

@media (max-width: 1439px) {
  .users-layout {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
