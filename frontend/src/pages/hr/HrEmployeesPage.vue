<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useQuasar } from 'quasar'
import { useRoute, useRouter } from 'vue-router'
import { BadgeCheck, BriefcaseBusiness, Building2, CalendarDays, FileText, History, IdCard, Trash2, UserRound } from '@lucide/vue'
import WorkspaceBackBar from '../../components/workspace/WorkspaceBackBar.vue'
import WorkspacePanel from '../../components/workspace/WorkspacePanel.vue'
import AppCard from '../../components/ui/AppCard.vue'
import PageHeader from '../../components/ui/PageHeader.vue'
import PersonAccountActions from '../../components/identity/PersonAccountActions.vue'
import DeletionRequestDialog from '../../components/trash/DeletionRequestDialog.vue'
import { api } from '../../services/api'
import { useAuthStore } from '../../stores/auth'
import { useHrStore } from '../../stores/hr'
import { formatPhone } from '../../utils/phone'

const $q = useQuasar()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const store = useHrStore()

const activeTab = ref(route.path.includes('/departments') ? 'departments' : route.path.includes('/positions') ? 'positions' : 'employees')
const employeeDialog = ref(false)
const dictionaryDialog = ref(false)
const assignmentDialog = ref(false)
const statusDialog = ref(false)
const editingEmployeeId = ref(null)
const editingDictionary = ref(null)
const workspaceTab = ref('general')

const employeeForm = reactive({
  person_id: null,
  last_name: '',
  first_name: '',
  middle_name: '',
  birth_date: '',
  email: '',
  phone: '',
  snils: '',
  employee_number: '',
  status: 'active',
  employment_type: 'full_time',
  work_schedule_code: 'flexible',
  hired_at: '',
  dismissed_at: '',
  primary_department_id: null,
  primary_position_id: null,
  workload_rate: 1,
  is_teacher: false,
  comment: '',
})

const dictionaryForm = reactive({ code: '', name: '', category: '', description: '', is_active: true })
const positionCategoryOptions = [
  { label: 'Руководители', value: 'Руководители' },
  { label: 'Педагогические работники', value: 'Педагогические работники' },
  { label: 'Административно-управленческий персонал', value: 'Административно-управленческий персонал' },
  { label: 'Учебно-вспомогательный персонал', value: 'Учебно-вспомогательный персонал' },
  { label: 'Обслуживающий персонал', value: 'Обслуживающий персонал' },
]
const assignmentForm = reactive({ department_id: null, position_id: null, employment_type: 'full_time', rate: 1, started_at: '', ended_at: '', is_primary: true, order_number: '', order_date: '', comment: '' })
const statusForm = reactive({ status: 'active', date_from: '', date_to: '', reason: '', document_number: '', document_date: '', comment: '' })

const statusOptions = [
  { label: 'Кандидат', value: 'candidate', color: 'grey' },
  { label: 'Активен', value: 'active', color: 'positive' },
  { label: 'Испытательный срок', value: 'probation', color: 'blue' },
  { label: 'Отпуск', value: 'vacation', color: 'orange' },
  { label: 'Больничный', value: 'sick_leave', color: 'deep-orange' },
  { label: 'Декрет', value: 'maternity_leave', color: 'purple' },
  { label: 'Командировка', value: 'business_trip', color: 'teal' },
  { label: 'Отстранен', value: 'suspended', color: 'negative' },
  { label: 'Уволен', value: 'dismissed', color: 'dark' },
]

const employmentOptions = [
  { label: 'Полная занятость', value: 'full_time' },
  { label: 'Частичная занятость', value: 'part_time' },
  { label: 'Внутреннее совместительство', value: 'internal_part_time' },
  { label: 'Внешнее совместительство', value: 'external_part_time' },
  { label: 'Договор', value: 'contract' },
]

const workScheduleOptions = [
  { label: 'Не указан (гибкий график)', value: null },
  { label: 'Пн–Пт, 09:00–18:00', value: 'weekday_0900_1800' },
  { label: 'Пн–Пт, 09:00–17:00', value: 'weekday_0900_1700' },
  { label: 'Сменный 2/2, 08:00–20:00', value: 'shift_2_2_0800_2000' },
  { label: 'Гибкий график', value: 'flexible' },
]

const workingOptions = [
  { label: 'Все', value: '' },
  { label: 'Работают', value: 'active' },
  { label: 'Уволены', value: 'dismissed' },
]

const employeeColumns = [
  { name: 'employee_number', label: 'Табельный', field: 'employee_number', align: 'left', sortable: true },
  { name: 'person', label: 'Сотрудник', field: 'full_name', align: 'left', sortable: true },
  { name: 'department', label: 'Подразделение', field: (row) => row.primary_department?.name || '—', align: 'left' },
  { name: 'position', label: 'Должность', field: (row) => row.primary_position?.name || '—', align: 'left' },
  { name: 'rate', label: 'Ставка', field: 'workload_rate', align: 'right' },
  { name: 'status', label: 'Статус', field: 'status', align: 'left' },
  { name: 'actions', label: '', field: 'actions', align: 'right' },
]

const dictionaryColumns = [
  { name: 'code', label: 'Код', field: 'code', align: 'left' },
  { name: 'name', label: 'Название', field: 'name', align: 'left', sortable: true },
  { name: 'category', label: 'Категория', field: (row) => row.category || '—', align: 'left' },
  { name: 'description', label: 'Описание', field: 'description', align: 'left' },
  { name: 'is_active', label: 'Активен', field: 'is_active', align: 'left' },
  { name: 'actions', label: '', field: 'actions', align: 'right' },
]

const selected = computed(() => store.selectedEmployee)

function employeeRowClass(row) {
  return Number(row.id) === Number(store.selectedId) ? 'workspace-row--selected' : ''
}
const canCreate = computed(() => auth.can('hr.employees.create'))
const exporting = ref(false)

/**
 * Выгрузка сотрудников теми же колонками, что у шаблона импорта: файл можно
 * поправить в Excel и залить обратно «Универсальным импортом».
 */
async function exportEmployees() {
  exporting.value = true
  try {
    const blob = await api.download('/employees/export')
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `employees-${new Date().toISOString().slice(0, 10)}.csv`
    document.body.appendChild(link)
    link.click()
    link.remove()
    URL.revokeObjectURL(url)
  } catch (err) {
    $q.notify({ type: 'negative', message: err.message || 'Не удалось выгрузить сотрудников' })
  } finally {
    exporting.value = false
  }
}
const canUpdate = computed(() => auth.can('hr.employees.update'))
const canDismiss = computed(() => auth.can('hr.employees.dismiss'))
// Удаление в два шага: карточку помечает кадровик, удаляет администратор.
const canRequestDeletion = computed(() => auth.can('trash.request'))
const deletionRequestEmployee = ref(null)
const deletionRequestVisible = ref(false)

function askDeletionRequest(employee) {
  if (!canRequestDeletion.value || !employee) return
  deletionRequestEmployee.value = employee
  deletionRequestVisible.value = true
}

function onDeletionRequested() {
  const name = deletionRequestEmployee.value?.name || 'Карточка'
  deletionRequestEmployee.value = null
  $q.notify({ type: 'positive', message: `${name}: заявка на удаление отправлена администратору`, position: 'top-right', timeout: 1800 })
}
const canIssueDigitalPass = computed(() => auth.can('hr.employees.digital_pass.issue'))
const canManageAssignments = computed(() => auth.can('hr.assignments.manage'))
const canManageStatuses = computed(() => auth.can('hr.statuses.manage'))
// `HR-002`: узкий взгляд в реестр — только найденные совпадения, только на выбор.
const canMatchPeople = computed(() => auth.can('hr.people.match') || auth.can('people.view'))
const personMatches = ref([])
const personMatchDialog = ref(false)
const canManageDepartments = computed(() => auth.can('hr.departments.manage'))
const canManagePositions = computed(() => auth.can('hr.positions.manage'))

const activeStatus = computed(() => statusOptions.find((item) => item.value === selected.value?.current_status) || statusOptions.find((item) => item.value === selected.value?.status))
const metrics = computed(() => selected.value ? [
  { label: 'Подразделение', value: selected.value.primary_department?.name || '—' },
  { label: 'Должность', value: selected.value.primary_position?.name || '—' },
  { label: 'Ставка', value: selected.value.workload_rate || '—' },
  { label: 'Назначений', value: selected.value.assignments?.length || 0 },
] : [])
const quickActions = computed(() => selected.value ? [
  // Обе ссылки вели параметром запроса, которого получатель не читает: «Люди» и
  // «Преподаватели» берут идентификатор из пути, а `?selected=` и `?teacher=`
  // молча отбрасывают — человек попадал в список вместо карточки и видел это
  // как сломанную кнопку.
  selected.value.person?.id ? { label: 'Личная карточка', to: `/people/${selected.value.person.id}` } : null,
  selected.value.teacher?.id ? { label: 'Преподаватель', to: `/teachers/${selected.value.teacher.id}` } : null,
  selected.value.teacher?.id ? { label: 'Расписание', to: `/schedule?teacher_id=${selected.value.teacher.id}` } : null,
  selected.value.teacher?.id ? { label: 'Нагрузка', to: `/teaching-load?teacher_id=${selected.value.teacher.id}` } : null,
  { label: 'История проходов', to: `/access/reports?type=employee&person_id=${selected.value.person_id}` },
  { label: 'Кадровый календарь', to: `/hr/calendar?employee_id=${selected.value.id}` },
].filter(Boolean) : [])

function statusLabel(value) {
  return statusOptions.find((item) => item.value === value)?.label || value || '—'
}

function statusColor(value) {
  return statusOptions.find((item) => item.value === value)?.color || 'grey'
}

function employmentLabel(value) {
  return employmentOptions.find((item) => item.value === value)?.label || value || '—'
}

function formatDate(value) {
  if (!value) return '—'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString('ru-RU')
}

function routeForTab(tab) {
  return tab === 'departments' ? '/hr/departments' : tab === 'positions' ? '/hr/positions' : '/hr/employees'
}

function resetEmployeeForm(employee = null) {
  Object.assign(employeeForm, {
    person_id: employee?.person_id || employee?.person?.id || null,
    last_name: employee?.person?.last_name || '',
    first_name: employee?.person?.first_name || '',
    middle_name: employee?.person?.middle_name || '',
    birth_date: employee?.person?.birth_date || '',
    email: employee?.person?.email || '',
    phone: employee?.person?.phone || '',
    snils: employee?.person?.snils || '',
    employee_number: employee?.employee_number || '',
    status: employee?.status || 'active',
    employment_type: employee?.employment_type || 'full_time',
    work_schedule_code: employee ? employee.work_schedule_code : 'flexible',
    hired_at: employee?.hired_at || '',
    dismissed_at: employee?.dismissed_at || '',
    primary_department_id: employee?.primary_department_id || null,
    primary_position_id: employee?.primary_position_id || null,
    workload_rate: employee?.workload_rate || 1,
    is_teacher: Boolean(employee?.is_teacher),
    comment: employee?.comment || '',
  })
}

function openEmployeeDialog(employee = null) {
  editingEmployeeId.value = employee?.id || null
  resetEmployeeForm(employee)
  employeeDialog.value = true
}

/**
 * `HR-002`. Сервер отказывает, когда по введённым данным нашлось несколько
 * человек: привязать карточку не к тому — хуже, чем переспросить. Раньше на
 * этом всё и заканчивалось — «выберите вручную», а выбирать было не из чего.
 *
 * Теперь отказ превращается в вопрос: спрашиваем у сервера, кого именно он
 * нашёл, и показываем их. Реестр людей при этом остаётся закрыт — приходят
 * только те, на кого вышел собственный ввод кадровика.
 */
async function saveEmployee() {
  try {
    await store.saveEmployee(employeeForm, editingEmployeeId.value)
  } catch (err) {
    if (await offerPersonMatches()) return
    throw err
  }

  employeeDialog.value = false
  $q.notify({ type: 'positive', message: 'Карточка сотрудника сохранена' })
}

async function offerPersonMatches() {
  if (!canMatchPeople.value || employeeForm.person_id) return false

  try {
    const payload = await api.create('hr/person-matches', {
      last_name: employeeForm.last_name || null,
      first_name: employeeForm.first_name || null,
      middle_name: employeeForm.middle_name || null,
      birth_date: employeeForm.birth_date || null,
      snils: employeeForm.snils || null,
      email: employeeForm.email || null,
      phone: employeeForm.phone || null,
    })
    const matches = payload?.data?.matches || []
    if (matches.length < 2) return false

    personMatches.value = matches
    personMatchDialog.value = true
    return true
  } catch {
    // Не удалось спросить — пусть человек видит исходный отказ сервера.
    return false
  }
}

/** Подписи тому, чем совпало: «телефон» и «СНИЛС» весят по-разному. */
const MATCH_LABELS = {
  snils: 'СНИЛС',
  phone: 'телефон',
  email: 'почта',
  full_name_birth_date: 'ФИО и дата рождения',
  identity_document: 'документ',
}

function matchedByLabel(matchedBy) {
  return (matchedBy || []).map((item) => MATCH_LABELS[item] || item).join(', ')
}

async function choosePerson(match) {
  employeeForm.person_id = match.id
  personMatchDialog.value = false
  await saveEmployee()
}

/** Ни один из найденных не подходит — значит человек новый. */
async function createNewPerson() {
  employeeForm.person_id = null
  personMatchDialog.value = false
  $q.notify({
    type: 'info',
    message: 'Уточните СНИЛС или дату рождения: без них портал не отличит нового человека от найденных.',
    position: 'top-right',
    timeout: 4000,
  })
}

function confirmDismiss(employee) {
  $q.dialog({
    title: 'Уволить сотрудника?',
    message: `Статус сотрудника ${employee.full_name} будет изменен на «Уволен».`,
    cancel: true,
    persistent: true,
  }).onOk(async () => {
    await store.dismissEmployee(employee)
    $q.notify({ type: 'positive', message: 'Сотрудник уволен' })
  })
}

function selectExistingPerson(personId) {
  const person = store.people.find((item) => Number(item.id) === Number(personId))
  if (!person) return
  Object.assign(employeeForm, {
    last_name: person.last_name || '',
    first_name: person.first_name || '',
    middle_name: person.middle_name || '',
    email: person.email || '',
    phone: person.phone || '',
    snils: person.snils || '',
  })
}

function confirmIssueDigitalPass(employee) {
  $q.dialog({
    title: 'Выпустить цифровой пропуск?',
    message: `Для сотрудника ${employee.full_name} будет создан QR-пропуск. При наличии активный пропуск будет отозван.`,
    cancel: true,
    persistent: true,
    ok: { label: 'Выпустить', color: 'primary' },
  }).onOk(async () => {
    await store.issueDigitalPass(employee)
    $q.notify({ type: 'positive', message: 'Цифровой пропуск сотрудника выпущен' })
  })
}

function openDictionaryDialog(item = null) {
  editingDictionary.value = item
  Object.assign(dictionaryForm, { code: item?.code || '', name: item?.name || '', category: item?.category || '', description: item?.description || '', is_active: item?.is_active !== false })
  dictionaryDialog.value = true
}

async function saveDictionary() {
  if (activeTab.value === 'departments') {
    await store.saveDepartment(dictionaryForm, editingDictionary.value?.id || null)
  } else {
    await store.savePosition(dictionaryForm, editingDictionary.value?.id || null)
  }
  dictionaryDialog.value = false
}

function confirmRemoveDictionary(item) {
  $q.dialog({ title: 'Удалить запись?', message: item.name, cancel: true }).onOk(async () => {
    if (activeTab.value === 'departments') await store.removeDepartment(item)
    else await store.removePosition(item)
  })
}

function openAssignmentDialog() {
  Object.assign(assignmentForm, { department_id: selected.value?.primary_department_id || null, position_id: selected.value?.primary_position_id || null, employment_type: selected.value?.employment_type || 'full_time', rate: selected.value?.workload_rate || 1, started_at: new Date().toISOString().slice(0, 10), ended_at: '', is_primary: true, order_number: '', order_date: '', comment: '' })
  assignmentDialog.value = true
}

async function saveAssignment() {
  await store.addAssignment(selected.value.id, assignmentForm)
  assignmentDialog.value = false

/*
  Назначение и период статуса заводились и оставались навсегда: опечатку в них
  нельзя было исправить ничем (находка аудита 2.9). Ручки на бэкенде были, а
  кнопок не было.

  У назначения — удаление: другого способа исправить его нет. У периода —
  **отмена**, а не удаление: применённый период уже передвинул занятия, и
  удаление стёрло бы запись, оставив расписание переставленным без объяснения.
  Отмена возвращает занятия и оставляет след.
*/
function removeAssignment(item) {
  if (!canManageAssignments.value) return
  const name = [item.position?.name, item.department?.name].filter(Boolean).join(' · ') || 'Назначение'
  $q.dialog({ title: 'Убрать назначение?', message: name, cancel: true, ok: { label: 'Убрать', color: 'negative' } }).onOk(async () => {
    await store.removeAssignment(item.id)
    $q.notify({ type: 'positive', message: 'Назначение убрано' })
  })
}

function cancelStatusPeriod(item) {
  if (!canManageStatuses.value) return
  $q.dialog({
    title: 'Отменить период?',
    message: `${statusLabel(item.status)}: ${formatDate(item.date_from)} — ${formatDate(item.date_to)}. Занятия, перенесённые этим периодом, вернутся на место.`,
    prompt: { model: '', type: 'text', label: 'Причина', isValid: () => true },
    cancel: true,
    ok: { label: 'Отменить период', color: 'negative' },
  }).onOk(async (reason) => {
    await store.cancelStatusPeriodFromCard(item.id, reason)
    $q.notify({ type: 'positive', message: 'Период отменён' })
  })
}
}

function openStatusDialog(status = 'active') {
  Object.assign(statusForm, { status, date_from: new Date().toISOString().slice(0, 10), date_to: '', reason: '', document_number: '', document_date: '', comment: '' })
  statusDialog.value = true
}

async function saveStatusPeriod() {
  await store.addStatusPeriod(selected.value.id, statusForm)
  statusDialog.value = false
}

async function applyFilters() {
  await store.loadEmployees()
}

onMounted(() => store.load().catch(() => {}))

watch(activeTab, (tab) => {
  if (route.path !== routeForTab(tab)) router.replace(routeForTab(tab))
})

watch(() => route.path, (path) => {
  activeTab.value = path.includes('/departments') ? 'departments' : path.includes('/positions') ? 'positions' : 'employees'
})
</script>

<template>
  <div class="hr-page">
<!--
      Заголовок — общей опорой `PageHeader`, как в прочих разделах. Раньше здесь
      стояла своя шапка: на экране она выглядела заголовком, но набиралась не
      тем же, чем у соседей, и раздел выбивался из общего вида. Подзаголовок
      переписан для человека: прежний объяснял устройство API, а не то, что
      человек здесь делает.
    -->
    <PageHeader
      title="Сотрудники"
      subtitle="Сотрудники, подразделения и должности. Карточка сотрудника связана с личной карточкой человека."
    >
      <template #actions>
        <q-btn v-if="activeTab === 'employees'" outline no-caps :loading="exporting" @click="exportEmployees">Экспорт CSV</q-btn>
        <q-btn v-if="activeTab === 'employees' && canCreate" color="primary" no-caps @click="openEmployeeDialog()">Новый сотрудник</q-btn>
        <q-btn v-if="activeTab === 'departments' && canManageDepartments" color="primary" no-caps @click="openDictionaryDialog()">Новое подразделение</q-btn>
        <q-btn v-if="activeTab === 'positions' && canManagePositions" color="primary" no-caps @click="openDictionaryDialog()">Новая должность</q-btn>
      </template>
    </PageHeader>

    <q-tabs v-model="activeTab" align="left" class="text-primary hr-tabs" dense>
      <q-tab name="employees" label="Сотрудники" />
      <q-tab name="departments" label="Подразделения" />
      <q-tab name="positions" label="Должности" />
    </q-tabs>

    <q-banner v-if="store.error" class="bg-red-1 text-negative q-mb-md" rounded>{{ store.error }}</q-banner>

    <div
      v-if="activeTab === 'employees'"
      class="hr-layout workspace-page"
      :class="{ 'workspace-page--card': Boolean(selected) }"
    >
      <div class="hr-main workspace-page__list">
        <AppCard>
          <div class="hr-filters">
            <q-input v-model="store.filters.search" dense outlined label="Поиск" clearable @keyup.enter="applyFilters" />
            <q-select v-model="store.filters.status" dense outlined label="Статус" :options="[{ label: 'Все', value: '' }, ...statusOptions]" emit-value map-options clearable />
            <q-select v-model="store.filters.department_id" dense outlined label="Подразделение" :options="store.departmentOptions" emit-value map-options clearable />
            <q-select v-model="store.filters.position_id" dense outlined label="Должность" :options="store.positionOptions" emit-value map-options clearable />
            <q-select v-model="store.filters.employment_type" dense outlined label="Занятость" :options="[{ label: 'Все', value: '' }, ...employmentOptions]" emit-value map-options clearable />
            <q-select v-model="store.filters.working" dense outlined label="Работа" :options="workingOptions" emit-value map-options />
            <q-btn color="primary" no-caps :loading="store.loading" @click="applyFilters">Применить</q-btn>
          </div>
        </AppCard>

        <AppCard>
          <q-table
            flat
            dense
            :rows="store.employees"
            :columns="employeeColumns"
            row-key="id"
            :loading="store.loading"
            :rows-per-page-options="[20, 50, 100]"
            class="hr-table"
            :table-row-class-fn="employeeRowClass"
            @row-click="(_, row) => store.selectedId = row.id"
          >
            <template #body-cell-status="props">
              <q-td :props="props"><q-chip dense :color="statusColor(props.row.current_status || props.row.status)" text-color="white">{{ statusLabel(props.row.current_status || props.row.status) }}</q-chip></q-td>
            </template>
            <template #body-cell-actions="props">
              <q-td :props="props" class="q-gutter-xs">
                <q-btn flat dense no-caps color="primary" @click.stop="store.selectedId = props.row.id">Открыть</q-btn>
                <q-btn v-if="canUpdate" flat dense no-caps @click.stop="openEmployeeDialog(props.row)">Изменить</q-btn>
              </q-td>
            </template>
          </q-table>
        </AppCard>
      </div>

      <div class="workspace-page__card">
        <WorkspaceBackBar @back="store.selectedId = null" />
        <WorkspacePanel
          v-if="selected"
          class="hr-workspace"
          :title="selected.full_name"
          :subtitle="[selected.employee_number, selected.primary_position?.name, selected.primary_department?.name]"
          :metrics="metrics"
          :actions="quickActions"
        >
          <template #photo>
            <q-avatar size="72px" square class="hr-avatar"><img v-if="selected.photo_url" :src="selected.photo_url" alt="" /><UserRound v-else :size="36" /></q-avatar>
          </template>
          <template #status>
            <q-chip :color="activeStatus?.color || 'grey'" text-color="white" dense>{{ statusLabel(selected.current_status || selected.status) }}</q-chip>
          </template>

          <q-tabs v-model="workspaceTab" dense align="left" class="text-primary q-mt-md">
            <q-tab name="general" label="Общее" />
            <q-tab name="assignments" label="Назначения" />
            <q-tab name="statuses" label="Статусы" />
            <q-tab name="documents" label="Документы" />
            <q-tab name="history" label="История" />
          </q-tabs>
          <q-separator />
          <q-tab-panels v-model="workspaceTab" animated class="bg-transparent">
            <q-tab-panel name="general" class="q-px-none">
              <div class="hr-info-grid">
                <span>Телефон</span><strong>{{ formatPhone(selected.person?.phone, "—") }}</strong>
                <span>Email</span><strong>{{ selected.person?.email || '—' }}</strong>
                <span>Принят</span><strong>{{ formatDate(selected.hired_at) }}</strong>
                <span>Уволен</span><strong>{{ formatDate(selected.dismissed_at) }}</strong>
                <span>Тип занятости</span><strong>{{ employmentLabel(selected.employment_type) }}</strong>
                <span>Преподаватель</span><strong>{{ selected.is_teacher ? 'Да' : 'Нет' }}</strong>
              </div>
              <div class="q-gutter-sm q-mt-md">
                <q-btn v-if="canUpdate" outline no-caps color="primary" @click="openEmployeeDialog(selected)">Редактировать</q-btn>
                <q-btn v-if="canManageStatuses" outline no-caps color="orange" @click="openStatusDialog('vacation')">Оформить отпуск</q-btn>
                <q-btn v-if="canManageStatuses" outline no-caps color="deep-orange" @click="openStatusDialog('sick_leave')">Больничный</q-btn>
                <q-btn v-if="canIssueDigitalPass" outline no-caps color="primary" :loading="store.saving" @click="confirmIssueDigitalPass(selected)"><BadgeCheck :size="16" class="q-mr-xs" />Выпустить цифровой пропуск</q-btn>
                <q-btn v-if="canDismiss && selected.status !== 'dismissed'" outline no-caps color="negative" @click="confirmDismiss(selected)">Уволить</q-btn>
                <!-- Увольнение и удаление — разное. Уволенный сотрудник остаётся в
                     кадрах, а ошибочно заведённая карточка уходит к администратору. -->
                <q-btn v-if="canRequestDeletion" outline no-caps color="negative" @click="askDeletionRequest(selected)">Пометить на удаление</q-btn>
                <PersonAccountActions profile-type="employee" :profile-id="selected.id" :has-account="Boolean(selected.user_id)" />
              </div>
            </q-tab-panel>
            <q-tab-panel name="assignments" class="q-px-none">
              <q-btn v-if="canManageAssignments" color="primary" no-caps class="q-mb-sm" @click="openAssignmentDialog">Добавить назначение</q-btn>
              <q-list bordered separator class="rounded-borders">
                <q-item v-for="item in selected.assignments || []" :key="item.id">
                  <q-item-section>
                    <q-item-label>{{ item.position?.name || 'Должность' }} · {{ item.department?.name || 'Подразделение' }}</q-item-label>
                    <q-item-label caption>{{ formatDate(item.started_at) }} — {{ formatDate(item.ended_at) }} · {{ item.rate }} ставки</q-item-label>
                  </q-item-section>
                  <q-item-section side class="row items-center no-wrap q-gutter-xs">
                    <q-chip v-if="item.is_primary" dense color="primary" text-color="white">Основное</q-chip>
                    <q-btn v-if="canManageAssignments" flat dense round color="negative" @click="removeAssignment(item)">
                      <Trash2 :size="16" />
                      <q-tooltip>Убрать назначение</q-tooltip>
                    </q-btn>
                  </q-item-section>
                </q-item>
                <q-item v-if="!(selected.assignments || []).length"><q-item-section class="text-grey-7">Назначений пока нет</q-item-section></q-item>
              </q-list>
            </q-tab-panel>
            <q-tab-panel name="statuses" class="q-px-none">
              <div class="q-gutter-sm q-mb-sm"><q-btn v-if="canManageStatuses" color="primary" no-caps @click="openStatusDialog()">Добавить период</q-btn><q-btn outline no-caps color="primary" to="/hr/calendar">Открыть календарь</q-btn></div>
              <q-list bordered separator class="rounded-borders">
                <q-item v-for="item in selected.status_periods || []" :key="item.id">
                  <q-item-section>
                    <q-item-label>{{ statusLabel(item.status) }} · {{ item.period_status || 'planned' }}</q-item-label>
                    <q-item-label caption>{{ formatDate(item.date_from) }} — {{ formatDate(item.date_to) }} · {{ item.reason || 'Без причины' }}</q-item-label>
                  </q-item-section>
                  <q-item-section side>
                    <q-btn
                      v-if="canManageStatuses && item.period_status !== 'cancelled'"
                      flat
                      dense
                      no-caps
                      color="negative"
                      @click="cancelStatusPeriod(item)"
                    >
                      Отменить
                      <q-tooltip>Занятия, перенесённые периодом, вернутся на место</q-tooltip>
                    </q-btn>
                  </q-item-section>
                </q-item>
                <q-item v-if="!(selected.status_periods || []).length"><q-item-section class="text-grey-7">История статусов пока пуста</q-item-section></q-item>
              </q-list>
            </q-tab-panel>
            <q-tab-panel name="documents" class="q-px-none"><q-banner rounded class="bg-grey-2">Документы сотрудника подготовлены как вкладка MVP. Хранение файлов будет расширено в следующем этапе.</q-banner></q-tab-panel>
            <q-tab-panel name="history" class="q-px-none"><q-banner rounded class="bg-grey-2">История кадровых действий фиксируется в Audit Log.</q-banner></q-tab-panel>
          </q-tab-panels>
        </WorkspacePanel>
      </div>
    </div>

    <AppCard v-else>
      <q-table
        flat
        :rows="activeTab === 'departments' ? store.departments : store.positions"
        :columns="dictionaryColumns"
        row-key="id"
        :loading="store.loading"
        :rows-per-page-options="[20, 50, 100]"
      >
        <template #body-cell-is_active="props"><q-td :props="props"><q-chip dense :color="props.row.is_active ? 'positive' : 'grey'" text-color="white">{{ props.row.is_active ? 'Да' : 'Нет' }}</q-chip></q-td></template>
        <template #body-cell-actions="props">
          <q-td :props="props" class="q-gutter-xs">
            <q-btn v-if="activeTab === 'departments' ? canManageDepartments : canManagePositions" flat dense no-caps color="primary" @click="openDictionaryDialog(props.row)">Изменить</q-btn>
            <q-btn v-if="activeTab === 'departments' ? canManageDepartments : canManagePositions" flat dense no-caps color="negative" @click="confirmRemoveDictionary(props.row)">Удалить</q-btn>
          </q-td>
        </template>
      </q-table>
    </AppCard>

    <q-dialog v-model="employeeDialog" persistent>
      <q-card class="hr-dialog">
        <q-card-section><div class="text-h6">{{ editingEmployeeId ? 'Редактировать сотрудника' : 'Новый сотрудник' }}</div></q-card-section>
        <q-card-section class="hr-form-grid">
          <!-- Реестр людей открыт не каждой роли, а пустой список без объяснения
               читается как поломка. Нет доступа — нет и подсказки: существующего
               человека всё равно найдёт сервер при сохранении карточки. -->
          <q-select v-if="store.personOptions.length" v-model="employeeForm.person_id" outlined dense clearable emit-value map-options label="Личная карточка (выбрать существующую)" :options="store.personOptions" class="hr-form-wide" @update:model-value="selectExistingPerson" />
          <q-input v-model="employeeForm.employee_number" outlined dense label="Табельный номер" />
          <q-input v-model="employeeForm.last_name" outlined dense label="Фамилия" />
          <q-input v-model="employeeForm.first_name" outlined dense label="Имя" />
          <q-input v-model="employeeForm.middle_name" outlined dense label="Отчество" />
          <!-- Дата рождения — не украшение карточки: по ней портал узнаёт человека
               по ФИО, а не только по СНИЛС, телефону и почте. Без неё двое
               однофамильцев с общим рабочим телефоном неразличимы. -->
          <q-input v-model="employeeForm.birth_date" outlined dense type="date" label="Дата рождения" />
          <q-input v-model="employeeForm.email" outlined dense label="Email" />
          <q-input v-model="employeeForm.phone" outlined dense label="Телефон" />
          <q-select v-model="employeeForm.primary_department_id" outlined dense label="Подразделение" :options="store.departmentOptions" emit-value map-options clearable />
          <q-select v-model="employeeForm.primary_position_id" outlined dense label="Должность" :options="store.positionOptions" emit-value map-options clearable />
          <q-select v-model="employeeForm.status" outlined dense label="Статус" :options="statusOptions" emit-value map-options />
          <q-select v-model="employeeForm.employment_type" outlined dense label="Занятость" :options="employmentOptions" emit-value map-options />
          <q-select v-model="employeeForm.work_schedule_code" outlined dense label="Рабочий график" :options="workScheduleOptions" emit-value map-options />
          <q-input v-model="employeeForm.workload_rate" outlined dense type="number" step="0.25" label="Ставка" />
          <q-input v-model="employeeForm.hired_at" outlined dense type="date" label="Дата приема (необязательно)" />
          <q-toggle v-model="employeeForm.is_teacher" label="Является преподавателем" />
          <q-input v-model="employeeForm.comment" outlined dense type="textarea" label="Комментарий" class="hr-form-wide" />
        </q-card-section>
        <q-card-actions align="right"><q-btn flat no-caps label="Отмена" v-close-popup /><q-btn color="primary" no-caps label="Сохранить" :loading="store.saving" @click="saveEmployee" /></q-card-actions>
      </q-card>
    </q-dialog>

    <q-dialog v-model="dictionaryDialog">
      <q-card class="hr-dialog hr-dialog--small">
        <q-card-section><div class="text-h6">{{ activeTab === 'departments' ? 'Подразделение' : 'Должность' }}</div></q-card-section>
        <q-card-section class="q-gutter-md">
          <q-input v-model="dictionaryForm.code" outlined dense label="Код" />
          <q-input v-model="dictionaryForm.name" outlined dense label="Название" />
          <q-select v-if="activeTab === 'positions'" v-model="dictionaryForm.category" outlined dense clearable emit-value map-options label="Категория" :options="positionCategoryOptions" />
          <q-input v-model="dictionaryForm.description" outlined dense type="textarea" label="Описание" />
          <q-toggle v-model="dictionaryForm.is_active" label="Активно" />
        </q-card-section>
        <q-card-actions align="right"><q-btn flat no-caps label="Отмена" v-close-popup /><q-btn color="primary" no-caps label="Сохранить" @click="saveDictionary" /></q-card-actions>
      </q-card>
    </q-dialog>

    <q-dialog v-model="assignmentDialog">
      <q-card class="hr-dialog hr-dialog--small">
        <q-card-section><div class="text-h6">Назначение сотрудника</div></q-card-section>
        <q-card-section class="q-gutter-md">
          <q-select v-model="assignmentForm.department_id" outlined dense label="Подразделение" :options="store.departmentOptions" emit-value map-options />
          <q-select v-model="assignmentForm.position_id" outlined dense label="Должность" :options="store.positionOptions" emit-value map-options />
          <q-select v-model="assignmentForm.employment_type" outlined dense label="Занятость" :options="employmentOptions" emit-value map-options />
          <q-input v-model="assignmentForm.rate" outlined dense type="number" step="0.25" label="Ставка" />
          <q-input v-model="assignmentForm.started_at" outlined dense type="date" label="Дата начала" />
          <q-toggle v-model="assignmentForm.is_primary" label="Основное назначение" />
        </q-card-section>
        <q-card-actions align="right"><q-btn flat no-caps label="Отмена" v-close-popup /><q-btn color="primary" no-caps label="Сохранить" @click="saveAssignment" /></q-card-actions>
      </q-card>
    </q-dialog>

    <q-dialog v-model="statusDialog">
      <q-card class="hr-dialog hr-dialog--small">
        <q-card-section><div class="text-h6">Кадровый статус</div></q-card-section>
        <q-card-section class="q-gutter-md">
          <q-select v-model="statusForm.status" outlined dense label="Статус" :options="statusOptions" emit-value map-options />
          <q-input v-model="statusForm.date_from" outlined dense type="date" label="С даты" />
          <q-input v-model="statusForm.date_to" outlined dense type="date" label="По дату" />
          <q-input v-model="statusForm.reason" outlined dense label="Причина" />
          <q-input v-model="statusForm.comment" outlined dense type="textarea" label="Комментарий" />
        </q-card-section>
        <q-card-actions align="right"><q-btn flat no-caps label="Отмена" v-close-popup /><q-btn color="primary" no-caps label="Сохранить" @click="saveStatusPeriod" /></q-card-actions>
      </q-card>
    </q-dialog>

    <!-- `HR-002`: вместо «выберите вручную» — те, кого портал действительно нашёл. -->
    <q-dialog v-model="personMatchDialog">
      <q-card class="hr-dialog hr-dialog--small">
        <q-card-section>
          <div class="text-h6">Такой человек уже есть в портале</div>
          <p class="hr-match__hint">
            По введённым данным нашлось несколько человек. Выберите того, чью карточку заводите, —
            иначе в базе появится второй он же.
          </p>
        </q-card-section>
        <q-list separator bordered class="rounded-borders q-mx-md">
          <q-item v-for="match in personMatches" :key="match.id" clickable @click="choosePerson(match)">
            <q-item-section>
              <q-item-label>{{ match.full_name }}</q-item-label>
              <q-item-label caption>
                <template v-if="match.birth_date">{{ formatDate(match.birth_date) }}</template>
                <template v-if="match.roles.length"> · {{ match.roles.join(', ') }}</template>
              </q-item-label>
              <q-item-label caption class="hr-match__matched">Совпало: {{ matchedByLabel(match.matched_by) }}</q-item-label>
            </q-item-section>
            <q-item-section side><q-btn flat dense no-caps color="primary" label="Это он" /></q-item-section>
          </q-item>
        </q-list>
        <q-card-actions align="right">
          <q-btn flat no-caps label="Никто из них" @click="createNewPerson" />
          <q-btn flat no-caps label="Отмена" v-close-popup />
        </q-card-actions>
      </q-card>
    </q-dialog>

    <DeletionRequestDialog
      v-model="deletionRequestVisible"
      subject-type="employee"
      :subject-id="deletionRequestEmployee?.id ?? null"
      :subject-label="deletionRequestEmployee?.name || ''"
      @requested="onDeletionRequested"
    />
  </div>
</template>

<style scoped>
/* Тот же потолок ширины, что и у страниц на общем контейнере: без него раздел
   упирался в край окна, а соседние разделы — нет. */
.hr-page { display: flex; flex-direction: column; gap: 16px; max-width: var(--cp-page-max-width); }
.hr-tabs { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; }
/* Без выбранного сотрудника список занимает всю ширину; ширину карточки задаёт
   разделитель, поэтому вторая колонка приходит из inline-стиля, а не отсюда. */
.hr-layout { display: grid; grid-template-columns: minmax(0, 1fr); gap: 16px; align-items: start; }
.hr-layout.resizable-workspace { gap: 0; }
.hr-main { min-width: 0; }
.hr-main > * + * { margin-top: 16px; }
.hr-filters { display: grid; grid-template-columns: repeat(3, minmax(180px, 1fr)) auto; gap: 10px; align-items: end; }
/*
  Высота от окна, а не в постоянных пикселях.

  С `620px` таблица не росла вовсе: замерено 28.08.2026 — при окне 1000 px под
  карточкой оставалось 22 px, при 1400 px те же 587 px таблицы и **455 px
  пустоты**, из двадцати строк было видно двенадцать. Остальные списки портала
  уже считают так же (`calc(100vh - 342px)` в `main.css`), и `.hr-table`
  оставалась единственной списочной таблицей с высотой числом.
*/
.hr-table { max-height: calc(100vh - 342px); }
/* Карточка не должна ездить вбок: по горизонтали содержимое сжимается само,
   а элементы вроде вкладок прокручиваются внутри себя. */
.hr-workspace { position: sticky; top: 82px; min-width: 0; max-height: calc(100vh - 104px); overflow-x: hidden; overflow-y: auto; }
.hr-avatar { background: #f8fafc; color: #334155; border: 1px solid #e2e8f0; }
.hr-info-grid { display: grid; grid-template-columns: minmax(110px, auto) minmax(0, 1fr); gap: 8px 12px; font-size: 13px; }
.hr-info-grid strong { overflow-wrap: anywhere; }
.hr-info-grid span { color: #64748b; }
.hr-info-grid strong { color: #0f172a; font-weight: 600; }
.hr-dialog { width: min(860px, calc(100vw - 32px)); }
.hr-dialog--small { width: min(520px, calc(100vw - 32px)); }
.hr-match__hint { margin: 8px 0 0; color: #64748b; font-size: 13px; }
.hr-match__matched { color: #0f172a; }
.hr-form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
.hr-form-wide { grid-column: 1 / -1; }
@media (max-width: 1360px) { .hr-filters { grid-template-columns: repeat(2, minmax(180px, 1fr)); } }
@media (max-width: 1023px) { .hr-layout { display: block; } .hr-workspace { position: static; margin-top: 16px; max-height: none; } .hr-filters, .hr-form-grid { grid-template-columns: 1fr; } }
</style>
