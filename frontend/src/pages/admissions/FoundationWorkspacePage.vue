<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { formatPhone } from '../../utils/phone'
import { useQuasar } from 'quasar'
import { useRoute, useRouter } from 'vue-router'
import {
  AlertCircle,
  Archive,
  ClipboardCheck,
  Download,
  Edit3,
  Eye,
  FileSearch,
  FileText,
  History,
  Link,
  Plus,
  RefreshCw,
  Save,
  Search,
  Trash2,
  Upload,
  UserCheck,
  Users,
} from '@lucide/vue'
import AppPage from '../../components/ui/AppPage.vue'
import PageHeader from '../../components/ui/PageHeader.vue'
import AppToolbar from '../../components/ui/AppToolbar.vue'
import AppFilterBar from '../../components/ui/AppFilterBar.vue'
import AppTable from '../../components/ui/AppTable.vue'
import AppEmptyState from '../../components/ui/AppEmptyState.vue'
import AppLoading from '../../components/ui/AppLoading.vue'
import AppErrorBanner from '../../components/ui/AppErrorBanner.vue'
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'
import WorkspacePanel from '../../components/workspace/WorkspacePanel.vue'
import WorkspaceSplitter from '../../components/workspace/WorkspaceSplitter.vue'
import { useResizableWorkspace } from '../../composables/useResizableWorkspace'
import { humanizeApiMessage } from '../../services/api'
import { createTablePagination, persistTablePagination } from '../../services/tableSettings'
import {
  choiceProgramName,
  formatDate,
  formatDateTime,
  personName,
  programName,
  referenceLabel,
  sourceLabel,
  statusCode,
  statusLabel,
  useAdmissionsFoundationStore,
} from '../../stores/admissionsFoundation'

const store = useAdmissionsFoundationStore()
const route = useRoute()
const router = useRouter()
const $q = useQuasar()

const rowsKey = 'collegePortal.admissionsFoundation.rowsPerPage'
const splitterKey = 'collegePortal.admissionsFoundation.splitter.v1'
const syncingQuery = ref(false)
const activeTab = ref('general')
const wizardOpen = ref(false)
const wizardStep = ref('application')
const wizardSteps = ['application', 'identity', 'education', 'files', 'choices']
const wizardApplicantMode = ref('existing')
const personEditorOpen = ref(false)
const applicantEditorOpen = ref(false)
const detailError = ref('')
const duplicateResult = ref(null)
const duplicateDecision = ref('')
const wizardError = ref('')
const wizardTouched = reactive({})
const selectedUploadIdentityId = ref(null)
const selectedUploadEducationId = ref(null)
const uploadCategory = ref('other')
const uploadFiles = ref([])
const rowsPerPageOptions = [10, 20, 50]
const tablePagination = ref(createTablePagination(rowsKey, { sortBy: 'submitted_at', descending: true, rowsPerPage: 20 }))
const {
  resetSplitter,
  startResize,
  workspaceRef,
  workspaceStyle,
} = useResizableWorkspace({
  storageKey: splitterKey,
  defaultDetailsWidth: 480,
  minDetailsWidth: 360,
  maxDetailsWidth: 640,
  minListWidth: 560,
  resizeBodyClass: 'admissions-foundation-splitter-resizing',
})

const applicationForm = reactive({
  admission_year: new Date().getFullYear(),
  application_number: '',
  education_program_id: null,
  source_id: null,
  submitted_at: '',
  education_base: '',
  comment: '',
})

const wizardForm = reactive({
  applicant_id: null,
  admission_year: new Date().getFullYear(),
  application_number: '',
  education_program_id: null,
  source_id: null,
  submitted_at: new Date().toISOString().slice(0, 10),
  education_base: 'after_9',
  comment: '',
  snils: '',
  identity: {
    document_type_id: null,
    series: '',
    number: '',
    issue_date: '',
    issued_by: '',
    subdivision_code: '',
    release_country_name: 'Россия',
    is_primary: true,
    verification_status: 'pending_review',
  },
  education: {
    document_type_id: null,
    series: '',
    number: '',
    issue_date: '',
    document_organization: '',
    education_level_id: null,
    graduation_year: '',
    is_original: false,
    average_score: '',
    average_score_scale: '5',
    qualification_name: '',
    speciality_name: '',
    registration_number: '',
    is_primary: true,
    verification_status: 'pending_review',
  },
  choice: {
    priority: 1,
    education_form_id: null,
    funding_form_id: null,
    base_education_type_id: null,
    quota_type_id: null,
    status_id: null,
  },
  identityFiles: [],
  educationFiles: [],
})

const wizardAdditionalPerson = reactive({
  registration_address: '',
  residential_address: '',
  inn: '',
})

const personForm = reactive({
  id: null,
  last_name: '',
  first_name: '',
  middle_name: '',
  birth_date: '',
  gender: '',
  citizenship: '',
  place_birth: '',
  phone: '',
  email: '',
  address: '',
  snils: '',
  inn: '',
  status: 'active',
})

const applicantForm = reactive({
  id: null,
  person_id: null,
  source_id: null,
  status_id: null,
  first_contact_at: new Date().toISOString().slice(0, 10),
  notes: '',
})

const identityForm = reactive({
  document_type_id: null,
  series: '',
  number: '',
  issue_date: '',
  issued_by: '',
  subdivision_code: '',
  release_country_name: 'Россия',
  is_primary: true,
  verification_status: 'pending_review',
})

const educationForm = reactive({
  document_type_id: null,
  series: '',
  number: '',
  issue_date: '',
  document_organization: '',
  education_level_id: null,
  graduation_year: '',
  is_original: false,
  average_score: '',
  average_score_scale: '5',
  qualification_name: '',
  speciality_name: '',
  registration_number: '',
  is_primary: true,
  verification_status: 'pending_review',
})

const choiceForm = reactive({
  priority: 1,
  education_program_id: null,
  education_form_id: null,
  funding_form_id: null,
  base_education_type_id: null,
  quota_type_id: null,
  status_id: null,
})

const columns = [
  { name: 'application_number', label: '№ заявления', field: 'application_number', align: 'left', sortable: true },
  { name: 'applicant', label: 'Абитуриент', field: (row) => personName(row), align: 'left', sortable: true },
  { name: 'year', label: 'Год', field: 'admission_year', align: 'left', sortable: true },
  { name: 'source', label: 'Источник', field: (row) => sourceLabel(row), align: 'left' },
  { name: 'status', label: 'Статус', field: (row) => statusLabel(row), align: 'left', sortable: true },
  { name: 'choices_count', label: 'Программы', field: 'choices_count', align: 'right' },
  { name: 'documents', label: 'Комплектность', field: 'documents', align: 'left' },
  { name: 'created_at', label: 'Создано', field: 'created_at', align: 'left', sortable: true },
  { name: 'registered_at', label: 'Регистрация', field: 'registered_at', align: 'left', sortable: true },
  { name: 'actions', label: '', field: 'actions', align: 'right' },
]

const choiceColumns = [
  { name: 'priority', label: 'Приоритет', field: 'priority', align: 'left' },
  { name: 'program', label: 'Программа', field: (row) => choiceProgramName(row), align: 'left' },
  { name: 'education_form', label: 'Форма', field: (row) => referenceLabel(row.education_form), align: 'left' },
  { name: 'funding_form', label: 'Финансирование', field: (row) => referenceLabel(row.funding_form), align: 'left' },
  { name: 'base', label: 'Основание', field: (row) => referenceLabel(row.base_education_type), align: 'left' },
  { name: 'status', label: 'Статус', field: (row) => referenceLabel(row.status), align: 'left' },
  { name: 'actions', label: '', field: 'actions', align: 'right' },
]

const documentColumns = [
  { name: 'type', label: 'Тип', field: (row) => referenceLabel(row.document_type), align: 'left' },
  { name: 'number', label: 'Серия / номер', field: 'number', align: 'left' },
  { name: 'version', label: 'Версия', field: 'version_number', align: 'left' },
  { name: 'status', label: 'Проверка', field: 'verification_status', align: 'left' },
  { name: 'files', label: 'Файлы', field: 'files_count', align: 'right' },
  { name: 'actions', label: '', field: 'actions', align: 'right' },
]

const fileColumns = [
  { name: 'name', label: 'Файл', field: 'original_name', align: 'left' },
  { name: 'category', label: 'Категория', field: 'category', align: 'left' },
  { name: 'size', label: 'Размер', field: 'size_bytes', align: 'right' },
  { name: 'uploaded_at', label: 'Загружен', field: 'uploaded_at', align: 'left' },
  { name: 'actions', label: '', field: 'actions', align: 'right' },
]

const selected = computed(() => store.selectedApplication)
const selectedPerson = computed(() => store.selectedPerson)
const selectedApplicant = computed(() => selected.value?.applicant || null)
const selectedRegistered = computed(() => store.selectedRegistered)
const selectedStatusTone = computed(() => statusTone(statusCode(selected.value)))
const tableSubtitle = computed(() => `Найдено foundation-заявлений: ${store.pagination.total || 0}`)
const selectedTitle = computed(() => selected.value?.application_number || `Заявление #${selected.value?.id || ''}`)
const selectedSubtitle = computed(() => [
  personName(selected.value),
  selected.value?.admission_year ? `Прием ${selected.value.admission_year}` : '',
  sourceLabel(selected.value),
])
const selectedMetrics = computed(() => [
  { label: 'Программы', value: store.sortedChoices.length },
  { label: 'Документы', value: readinessLabel.value },
  { label: 'ФИС', value: store.readiness?.fis?.fis_mapping_ready ? 'справочники готовы' : 'есть замечания' },
])
const readinessLabel = computed(() => {
  if (!store.readiness) return '—'
  if (store.readiness.internal_complete && store.readiness.review_complete) return 'готово'
  if (store.readiness.internal_complete) return 'на проверке'
  return 'есть замечания'
})
const wizardApplicant = computed(() => store.applicants.find((item) => Number(item.id) === Number(wizardForm.applicant_id)) || null)
const allFiles = computed(() => [
  ...store.identityDocuments.flatMap((document) => (document.files || []).map((file) => ({ ...file, document_kind: 'identity', document_id: document.id }))),
  ...store.educationDocuments.flatMap((document) => (document.files || []).map((file) => ({ ...file, document_kind: 'education', document_id: document.id }))),
])
const assignedIdentityId = computed(() => store.documentSet?.identity_document_id || null)
const assignedEducationId = computed(() => store.documentSet?.education_document_id || null)
const identityUploadOptions = computed(() => store.currentIdentityDocuments.map((document) => ({ label: documentLabel(document), value: document.id })))
const educationUploadOptions = computed(() => store.currentEducationDocuments.map((document) => ({ label: documentLabel(document), value: document.id })))
const statusOptions = computed(() => referenceOptions('admission_application_statuses', 'Все статусы'))
const sourceOptions = computed(() => referenceOptions('admission_sources', 'Все источники', 'id'))
const applicantStatusOptions = computed(() => referenceOptions('applicant_statuses', 'Не выбран', 'id'))
const applicantOptions = computed(() => store.applicants.map((item) => ({ label: applicantLabel(item), value: item.id })))
const personOptions = computed(() => store.people.map((item) => ({ label: personOptionLabel(item), value: item.id })))
const programOptions = computed(() => store.educationPrograms.map((program) => ({ label: programName(program), value: program.id })))
const identityTypeOptions = computed(() => referenceOptions('admission_identity_document_types', 'Не выбран', 'id'))
const educationTypeOptions = computed(() => referenceOptions('admission_education_document_types', 'Не выбран', 'id'))
const educationLevelOptions = computed(() => referenceOptions('education_levels', 'Не выбран', 'id'))
const fileCategoryOptions = computed(() => referenceOptions('admission_document_file_categories', 'Другое', 'code'))
const verificationStatusOptions = computed(() => referenceOptions('admission_document_verification_statuses', 'Ожидает проверки'))
const choiceStatusOptions = computed(() => referenceOptions('application_choice_statuses', 'Активно', 'id'))
const baseEducationOptions = computed(() => referenceOptions('base_education_types', 'Не выбрано', 'id'))
const quotaOptions = computed(() => referenceOptions('quota_types', 'Не выбрано', 'id'))
const legacyEducationBaseOptions = [
  { label: 'После 9 класса', value: 'after_9' },
  { label: 'После 11 класса', value: 'after_11' },
  { label: 'Основное общее образование', value: 'basic_general' },
  { label: 'Среднее общее образование', value: 'secondary_general' },
]
const applicationEducationBaseOptions = [
  { label: 'Не менять', value: '' },
  ...legacyEducationBaseOptions,
]
const hasChoicesOptions = [
  { label: 'Все заявления', value: '' },
  { label: 'Есть выбранные программы', value: '1' },
  { label: 'Без выбранных программ', value: '0' },
]
const applicantModeOptions = [
  { label: 'Выбрать существующего абитуриента', value: 'existing' },
  { label: 'Создать нового абитуриента', value: 'new' },
]
const personStatusOptions = [
  { label: 'Активен', value: 'active' },
  { label: 'Неактивен', value: 'inactive' },
  { label: 'Архив', value: 'archived' },
]
const genderOptions = [
  { label: 'Не указан', value: '' },
  { label: 'Женский', value: 'female' },
  { label: 'Мужской', value: 'male' },
]
const duplicateMatches = computed(() => duplicateResult.value?.matches || [])
const filterChips = computed(() => {
  const chips = []
  if (store.filters.q) chips.push({ key: 'q', label: `Поиск: ${store.filters.q}` })
  if (store.filters.status) chips.push({ key: 'status', label: `Статус: ${optionLabel(statusOptions.value, store.filters.status)}` })
  if (store.filters.admission_year) chips.push({ key: 'admission_year', label: `Год: ${store.filters.admission_year}` })
  if (store.filters.source_id) chips.push({ key: 'source_id', label: `Источник: ${optionLabel(sourceOptions.value, store.filters.source_id)}` })
  if (store.filters.has_choices !== '') chips.push({ key: 'has_choices', label: optionLabel(hasChoicesOptions, store.filters.has_choices) })
  return chips
})
const validationMessages = computed(() => Object.entries(store.validationErrors || {}).flatMap(([field, messages]) => (
  (Array.isArray(messages) ? messages : [messages])
    .filter(Boolean)
    .map((message) => humanizeApiMessage(message, field))
)))

function fieldError(key) {
  return wizardTouched[key] ? wizardTouched[key] : ''
}

function requiredField(value, message) {
  return value !== '' && value !== null && value !== undefined ? '' : message
}

function digitsOnly(value) {
  return String(value || '').replace(/\D/g, '')
}

function validEmail(value) {
  return !value || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(value).trim())
}

function validPhone(value) {
  const digits = digitsOnly(value)
  return !digits || digits.length >= 10
}

function validSnils(value) {
  const digits = digitsOnly(value)
  return !digits || digits.length === 11
}

function wizardStepIndex(step) {
  return wizardSteps.indexOf(step)
}

function isWizardStepDone(step) {
  return wizardStepIndex(wizardStep.value) > wizardStepIndex(step)
}

function wizardStepColor(step) {
  if (isWizardStepDone(step)) return 'positive'
  return wizardStep.value === step ? 'primary' : 'grey-6'
}

function firstOptionValue(options = []) {
  return options.find((option) => option.value !== '' && option.value !== null && option.value !== undefined)?.value || null
}

function applyWizardDefaults() {
  const defaultSourceId = firstOptionValue(sourceOptions.value)
  const defaultApplicantStatusId = firstOptionValue(applicantStatusOptions.value)
  const defaultChoiceStatusId = firstOptionValue(choiceStatusOptions.value)
  const defaultBaseEducationTypeId = firstOptionValue(baseEducationOptions.value)

  if (!wizardForm.source_id && defaultSourceId) wizardForm.source_id = defaultSourceId
  if (!applicantForm.source_id && defaultSourceId) applicantForm.source_id = defaultSourceId
  if (!applicantForm.status_id && defaultApplicantStatusId) applicantForm.status_id = defaultApplicantStatusId
  if (!wizardForm.choice.status_id && defaultChoiceStatusId) wizardForm.choice.status_id = defaultChoiceStatusId
  if (!wizardForm.choice.base_education_type_id && defaultBaseEducationTypeId) wizardForm.choice.base_education_type_id = defaultBaseEducationTypeId
}

function wizardStepErrors(step = wizardStep.value) {
  const errors = {}

  if (step === 'application') {
    if (wizardApplicantMode.value === 'existing') {
      errors.applicant_id = requiredField(wizardForm.applicant_id, 'Выберите абитуриента или переключитесь на создание нового.')
    } else if (!applicantForm.person_id) {
      errors.last_name = requiredField(personForm.last_name, 'Укажите фамилию.')
      errors.first_name = requiredField(personForm.first_name, 'Укажите имя.')
      errors.birth_date = requiredField(personForm.birth_date, 'Укажите дату рождения.')
      if (!validEmail(personForm.email)) errors.email = 'Укажите корректный email.'
      if (!validPhone(personForm.phone)) errors.phone = 'Телефон должен содержать не менее 10 цифр.'
      if (!validSnils(personForm.snils)) errors.person_snils = 'СНИЛС должен содержать 11 цифр.'
    }

    errors.education_program_id = requiredField(wizardForm.education_program_id, 'Выберите основную образовательную программу.')
    errors.admission_year = requiredField(wizardForm.admission_year, 'Укажите год приема.')
    errors.source_id = requiredField(wizardForm.source_id, 'Выберите источник заявления.')
  }

  if (step === 'identity' && !validSnils(wizardForm.snils)) {
    errors.snils = 'СНИЛС должен содержать 11 цифр.'
  }

  if (step === 'identity' && hasDocumentInput(wizardForm.identity)) {
    errors.identity_document_type_id = requiredField(wizardForm.identity.document_type_id, 'Выберите тип документа.')
    errors.identity_number = requiredField(wizardForm.identity.number, 'Укажите номер документа.')
  }

  if (step === 'education' && hasDocumentInput(wizardForm.education)) {
    errors.education_document_type_id = requiredField(wizardForm.education.document_type_id, 'Выберите тип документа об образовании.')
    errors.education_number = requiredField(wizardForm.education.number, 'Укажите номер документа об образовании.')
    if (wizardForm.education.average_score !== '' && (Number(wizardForm.education.average_score) < 0 || Number(wizardForm.education.average_score) > 5)) {
      errors.education_average_score = 'Средний балл должен быть от 0 до 5.'
    }
  }

  if (step === 'choices') {
    if (!Number.isFinite(Number(wizardForm.choice.priority)) || Number(wizardForm.choice.priority) < 1) {
      errors.choice_priority = 'Приоритет должен быть положительным числом.'
    }
  }

  return errors
}

function applyWizardErrors(errors) {
  Object.keys(wizardTouched).forEach((key) => { delete wizardTouched[key] })
  Object.entries(errors)
    .filter(([, message]) => Boolean(message))
    .forEach(([key, message]) => { wizardTouched[key] = message })

  const messages = Object.values(wizardTouched)
  wizardError.value = messages[0] || ''
  return messages.length === 0
}

function validateWizardStep(step = wizardStep.value) {
  return applyWizardErrors(wizardStepErrors(step))
}

function validateWizardAll() {
  for (const step of wizardSteps) {
    const errors = wizardStepErrors(step)
    if (Object.values(errors).some(Boolean)) {
      wizardStep.value = step
      return applyWizardErrors(errors)
    }
  }

  return applyWizardErrors({})
}

async function goWizardStep(nextStep) {
  if (!validateWizardStep()) {
    $q.notify({ type: 'warning', message: wizardError.value || 'Проверьте поля текущего шага.' })
    return
  }

  if (wizardStep.value === 'identity' && wizardApplicantMode.value === 'new' && !applicantForm.person_id) {
    try {
      const duplicate = await runDuplicateCheck({ silent: true })
      if ((duplicate?.matches || []).length > 0) {
        wizardError.value = 'Найдены возможные дубли человека. Выберите существующую запись перед продолжением.'
        $q.notify({ type: 'warning', message: wizardError.value })
        return
      }
    } catch {
      wizardError.value = detailError.value || 'Не удалось проверить дубли перед продолжением.'
      $q.notify({ type: 'warning', message: wizardError.value })
      return
    }
  }

  wizardError.value = ''
  wizardStep.value = nextStep
}

function referenceOptions(code, allLabel, valueField = 'code') {
  const items = store.referenceCatalogs[code]?.items || []
  return [
    { label: allLabel, value: '' },
    ...items.map((item) => ({
      label: item.name,
      value: valueField === 'id' ? item.id : item.code,
      code: item.code,
    })),
  ]
}

function optionLabel(options, value) {
  return options.find((option) => String(option.value) === String(value))?.label || value || '—'
}

function applicantLabel(applicant) {
  const person = applicant?.person || {}
  const fullName = person.full_name || [person.last_name, person.first_name, person.middle_name].filter(Boolean).join(' ')
  const archived = applicant?.archived_at ? ' · архив' : ''
  return `${fullName || applicant?.uuid || `Абитуриент #${applicant?.id}`} · ID ${applicant?.id}${archived}`
}

function personOptionLabel(person) {
  const fullName = person?.full_name || [person?.last_name, person?.first_name, person?.middle_name].filter(Boolean).join(' ')
  return [fullName || `Человек #${person?.id}`, person?.birth_date ? formatDate(person.birth_date) : '', person?.email || '', formatPhone(person?.phone)].filter(Boolean).join(' · ')
}

function resetPersonForm() {
  Object.assign(personForm, {
    id: null,
    last_name: '',
    first_name: '',
    middle_name: '',
    birth_date: '',
    gender: '',
    citizenship: '',
    place_birth: '',
    phone: '',
    email: '',
    address: '',
    snils: '',
    inn: '',
    status: 'active',
  })
}

function resetApplicantForm() {
  Object.assign(applicantForm, {
    id: null,
    person_id: null,
    source_id: null,
    status_id: null,
    first_contact_at: new Date().toISOString().slice(0, 10),
    notes: '',
  })
}

function resetWizardForm() {
  Object.assign(wizardForm, {
    applicant_id: null,
    admission_year: new Date().getFullYear(),
    application_number: '',
    education_program_id: null,
    source_id: null,
    submitted_at: new Date().toISOString().slice(0, 10),
    education_base: 'after_9',
    comment: '',
    snils: '',
    identityFiles: [],
    educationFiles: [],
  })
  Object.assign(wizardForm.identity, {
    document_type_id: null,
    series: '',
    number: '',
    issue_date: '',
    issued_by: '',
    subdivision_code: '',
    release_country_name: 'Россия',
    is_primary: true,
    verification_status: 'pending_review',
  })
  Object.assign(wizardForm.education, {
    document_type_id: null,
    series: '',
    number: '',
    issue_date: '',
    document_organization: '',
    education_level_id: null,
    graduation_year: '',
    is_original: false,
    average_score: '',
    average_score_scale: '5',
    qualification_name: '',
    speciality_name: '',
    registration_number: '',
    is_primary: true,
    verification_status: 'pending_review',
  })
  Object.assign(wizardForm.choice, {
    priority: 1,
    education_form_id: null,
    funding_form_id: null,
    base_education_type_id: null,
    quota_type_id: null,
    status_id: null,
  })
  Object.assign(wizardAdditionalPerson, {
    registration_address: '',
    residential_address: '',
    inn: '',
  })
  Object.keys(wizardTouched).forEach((key) => { delete wizardTouched[key] })
  wizardError.value = ''
}

function fillPersonForm(person = selectedPerson.value) {
  if (!person) return resetPersonForm()
  Object.assign(personForm, {
    id: person.id || null,
    last_name: person.last_name || '',
    first_name: person.first_name || '',
    middle_name: person.middle_name || '',
    birth_date: person.birth_date || '',
    gender: person.gender || '',
    citizenship: person.citizenship || '',
    place_birth: person.place_birth || '',
    phone: person.phone || '',
    email: person.email || '',
    address: person.address || '',
    snils: person.snils || '',
    inn: person.inn || '',
    status: person.status || 'active',
  })
}

function fillApplicantForm(applicant = selectedApplicant.value) {
  if (!applicant) return resetApplicantForm()
  Object.assign(applicantForm, {
    id: applicant.id || null,
    person_id: applicant.person_id || applicant.person?.id || null,
    source_id: applicant.source_id || applicant.source?.id || null,
    status_id: applicant.status_id || applicant.status?.id || null,
    first_contact_at: applicant.first_contact_at ? String(applicant.first_contact_at).slice(0, 10) : '',
    notes: applicant.notes || '',
  })
}

function personPayload() {
  const addressParts = [
    wizardAdditionalPerson.registration_address ? `Адрес регистрации: ${wizardAdditionalPerson.registration_address}` : '',
    wizardAdditionalPerson.residential_address ? `Адрес проживания: ${wizardAdditionalPerson.residential_address}` : '',
  ].filter(Boolean)
  const address = addressParts.length ? addressParts.join('\n') : personForm.address

  return normalizePayload({
    last_name: personForm.last_name,
    first_name: personForm.first_name,
    middle_name: personForm.middle_name,
    birth_date: personForm.birth_date,
    gender: personForm.gender,
    citizenship: personForm.citizenship,
    place_birth: personForm.place_birth,
    phone: personForm.phone,
    email: personForm.email,
    address,
    snils: personForm.snils,
    inn: personForm.inn || wizardAdditionalPerson.inn,
    status: personForm.status,
  })
}

function applicantPayload() {
  return normalizePayload({
    person_id: applicantForm.person_id,
    source_id: applicantForm.source_id,
    status_id: applicantForm.status_id,
    first_contact_at: applicantForm.first_contact_at,
    notes: applicantForm.notes,
  })
}

function duplicatePayload() {
  return normalizePayload({
    ...personPayload(),
    snils: personForm.snils || wizardForm.snils,
    identity_document: normalizePayload({
      series: wizardForm.identity.series,
      number: wizardForm.identity.number,
    }),
  })
}

function statusTone(value) {
  if (['registered', 'accepted', 'active', 'verified'].includes(value)) return 'success'
  if (['draft', 'new', 'pending_review', 'received'].includes(value)) return 'info'
  if (['withdrawn', 'rejected', 'excluded'].includes(value)) return 'danger'
  if (['documents_incomplete', 'replacement_required'].includes(value)) return 'warning'
  return 'neutral'
}

function personStatusLabel(value) {
  const labels = { active: 'Активен', inactive: 'Неактивен', archived: 'Архив' }
  return labels[value] || value || '—'
}

function genderLabel(value) {
  const labels = { female: 'Женский', male: 'Мужской' }
  return labels[value] || 'Не указан'
}

function documentLabel(document) {
  return [
    referenceLabel(document.document_type),
    document.series || document.series_masked,
    document.number || document.number_masked,
    `v${document.version_number || 1}`,
  ].filter(Boolean).join(' · ')
}

function documentNumber(document) {
  return [document.series || document.series_masked, document.number || document.number_masked].filter(Boolean).join(' ') || '—'
}

function fileSize(value) {
  const size = Number(value || 0)
  if (size >= 1024 * 1024) return `${(size / 1024 / 1024).toFixed(1)} МБ`
  if (size >= 1024) return `${Math.ceil(size / 1024)} КБ`
  return `${size} Б`
}

function normalizePayload(payload) {
  return Object.fromEntries(Object.entries(payload).filter(([, value]) => value !== '' && value !== null && value !== undefined))
}

function tableRowClass(row) {
  return Number(row.id) === Number(store.selectedId) ? 'admissions-foundation-row--selected' : ''
}

function updateTablePagination(pagination) {
  tablePagination.value = pagination
  persistTablePagination(rowsKey, pagination)
}

function applyServerPagination() {
  tablePagination.value = {
    ...tablePagination.value,
    page: store.pagination.current_page || 1,
    rowsPerPage: store.pagination.per_page || tablePagination.value.rowsPerPage,
    rowsNumber: store.pagination.total || 0,
  }
}

function routeSelectedId() {
  return route.query.selected ? String(route.query.selected) : ''
}

function filtersFromRoute() {
  return {
    status: route.query.status ? String(route.query.status) : '',
    admission_year: route.query.admission_year ? String(route.query.admission_year) : '',
    source_id: route.query.source_id ? String(route.query.source_id) : '',
    has_choices: route.query.has_choices !== undefined ? String(route.query.has_choices) : '',
  }
}

async function syncQuery(selectedId = routeSelectedId()) {
  const query = { ...route.query }
  selectedId ? query.selected = selectedId : delete query.selected
  store.filters.status ? query.status = store.filters.status : delete query.status
  store.filters.admission_year ? query.admission_year = store.filters.admission_year : delete query.admission_year
  store.filters.source_id ? query.source_id = store.filters.source_id : delete query.source_id
  store.filters.has_choices !== '' ? query.has_choices = store.filters.has_choices : delete query.has_choices
  delete query.q

  syncingQuery.value = true
  await router.replace({ path: '/admissions/foundation', query })
  syncingQuery.value = false
}

async function load(tableOptions = tablePagination.value) {
  await store.loadApplications(tableOptions)
  applyServerPagination()
}

async function applyFilters() {
  tablePagination.value = { ...tablePagination.value, page: 1 }
  await load(tablePagination.value)
  await syncQuery('')
  await store.selectApplication(null)
}

async function quickFilter(payload) {
  store.setFilters(payload)
  await applyFilters()
}

async function resetFilters() {
  store.resetFilters()
  tablePagination.value = { ...tablePagination.value, page: 1 }
  await load(tablePagination.value)
  await syncQuery('')
  await store.selectApplication(null)
}

async function clearFilter(key) {
  store.setFilters({ [key]: '' })
  await applyFilters()
}

async function selectApplication(application) {
  detailError.value = ''
  await store.selectApplication(application)
  fillApplicationForm()
  fillPersonForm()
  fillApplicantForm()
  await syncQuery(application?.id || '')
}

async function refreshSelected() {
  if (store.selectedId) {
    await store.loadApplication(store.selectedId)
    fillApplicationForm()
    fillPersonForm()
    fillApplicantForm()
  }
}

async function handleTableRequest({ pagination }) {
  updateTablePagination(pagination)
  await load(pagination)
}

function fillApplicationForm() {
  if (!selected.value) return
  applicationForm.admission_year = selected.value.admission_year || new Date().getFullYear()
  applicationForm.application_number = selected.value.application_number || ''
  applicationForm.education_program_id = selected.value.education_program_id || null
  applicationForm.source_id = selected.value.source?.id || selected.value.source_id || null
  applicationForm.submitted_at = selected.value.submitted_at || ''
  applicationForm.education_base = selected.value.education_base || ''
  applicationForm.comment = selected.value.comment || ''
}

function openWizard() {
  wizardApplicantMode.value = 'new'
  resetWizardForm()
  resetPersonForm()
  resetApplicantForm()
  applyWizardDefaults()
  duplicateResult.value = null
  duplicateDecision.value = ''
  wizardOpen.value = true
  wizardStep.value = 'application'
  detailError.value = ''
}

function snilsMask(value) {
  const digits = String(value || '').replace(/\D/g, '').slice(0, 11)
  return [
    digits.slice(0, 3),
    digits.slice(3, 6),
    digits.slice(6, 9),
  ].filter(Boolean).join('-') + (digits.length > 9 ? ` ${digits.slice(9, 11)}` : '')
}

function filterApplicants(value, update) {
  store.searchApplicants(value, { with_archived: true }).finally(() => update(() => {}))
}

function filterPeople(value, update) {
  store.searchPeople(value).finally(() => update(() => {}))
}

async function runDuplicateCheck({ silent = false } = {}) {
  detailError.value = ''
  duplicateDecision.value = ''
  duplicateResult.value = null

  try {
    duplicateResult.value = await store.checkPersonDuplicates(duplicatePayload())
    if (!silent && !duplicateResult.value.has_matches) {
      $q.notify({ type: 'positive', message: 'Дубли не найдены' })
    }
    return duplicateResult.value
  } catch (err) {
    detailError.value = err.message || 'Не удалось проверить дубли'
    throw err
  }
}

function useDuplicatePerson(match) {
  const person = match?.person || match
  if (!person?.id) return
  applicantForm.person_id = person.id
  fillPersonForm(person)
  duplicateDecision.value = `Используется существующая карточка человека #${person.id}`
  $q.notify({ type: 'info', message: duplicateDecision.value })
}

async function savePersonCard() {
  if (selectedRegistered.value) return
  detailError.value = ''

  try {
    const person = personForm.id
      ? await store.updatePerson(personForm.id, personPayload())
      : await store.createPerson(personPayload())
    fillPersonForm(person)
    if (selected.value?.id) await store.loadApplication(selected.value.id)
    personEditorOpen.value = false
    $q.notify({ type: 'positive', message: 'Карточка человека сохранена' })
  } catch (err) {
    detailError.value = err.message || 'Не удалось сохранить карточку человека'
  }
}

async function saveApplicantCard() {
  if (selectedRegistered.value) return
  detailError.value = ''

  try {
    const applicant = applicantForm.id
      ? await store.updateApplicant(applicantForm.id, applicantPayload())
      : await store.createApplicant(applicantPayload())
    fillApplicantForm(applicant)
    if (selected.value?.id) await store.loadApplication(selected.value.id)
    applicantEditorOpen.value = false
    $q.notify({ type: 'positive', message: 'Абитуриент сохранен' })
  } catch (err) {
    detailError.value = err.message || 'Не удалось сохранить абитуриента'
  }
}

async function archiveSelectedApplicant() {
  if (!selectedApplicant.value?.id || selectedRegistered.value) return

  $q.dialog({
    title: 'Архивировать абитуриента',
    message: 'Профиль абитуриента будет архивирован без физического удаления. Продолжить?',
    cancel: true,
    persistent: true,
  }).onOk(async () => {
    try {
      await store.archiveApplicant(selectedApplicant.value.id)
      await refreshSelected()
      $q.notify({ type: 'positive', message: 'Абитуриент архивирован' })
    } catch (err) {
      detailError.value = err.message || 'Не удалось архивировать абитуриента'
    }
  })
}

async function ensureWizardApplicant() {
  if (wizardApplicantMode.value === 'existing') {
    if (!wizardForm.applicant_id) {
      throw new Error('Выберите существующего абитуриента')
    }
    return wizardForm.applicant_id
  }

  if (!applicantForm.person_id) {
    const duplicate = await runDuplicateCheck()
    const matches = duplicate?.matches || []

    if (matches.length > 0) {
      throw new Error('Найдены возможные дубли человека. Выберите существующую запись или повторите создание после проверки.')
    }

    const person = await store.createPerson(normalizePayload({
      ...personPayload(),
      snils: personForm.snils || wizardForm.snils,
    }))
    applicantForm.person_id = person.id
  }

  const applicant = await store.createApplicant(applicantPayload())
  wizardForm.applicant_id = applicant.id
  return applicant.id
}

async function finishWizard() {
  detailError.value = ''
  if (!validateWizardAll()) {
    $q.notify({ type: 'warning', message: wizardError.value || 'Проверьте поля текущего шага.' })
    return
  }

  try {
    const applicantId = await ensureWizardApplicant()
    const applicationPayload = normalizePayload({
      applicant_id: applicantId,
      admission_year: wizardForm.admission_year,
      application_number: wizardForm.application_number,
      education_program_id: wizardForm.education_program_id,
      source_id: wizardForm.source_id,
      submitted_at: wizardForm.submitted_at,
      education_base: wizardForm.education_base,
      comment: wizardForm.comment,
    })
    const application = await store.createApplication(applicationPayload)

    if (wizardForm.snils) {
      await store.updateSnils(applicantId, wizardForm.snils)
    }

    const identity = hasDocumentInput(wizardForm.identity)
      ? await store.createIdentityDocument(applicantId, normalizePayload(wizardForm.identity), application.id)
      : null
    const education = hasDocumentInput(wizardForm.education)
      ? await store.createEducationDocument(applicantId, normalizePayload(wizardForm.education), application.id)
      : null

    if (wizardForm.education_program_id) {
      await store.createChoice(application.id, normalizePayload({
        ...wizardForm.choice,
        education_program_id: wizardForm.education_program_id,
      }))
    }

    if (identity?.id) {
      for (const file of wizardForm.identityFiles || []) {
        await store.uploadDocumentFile('identity', identity.id, file, application.id, uploadCategory.value)
      }
    }
    if (education?.id) {
      for (const file of wizardForm.educationFiles || []) {
        await store.uploadDocumentFile('education', education.id, file, application.id, uploadCategory.value)
      }
    }

    wizardOpen.value = false
    await load()
    await selectApplication(application)
    $q.notify({ type: 'positive', message: 'Черновик заявления создан' })
  } catch (err) {
    const messages = err.validationMessages?.length ? err.validationMessages : [err.message].filter(Boolean)
    detailError.value = messages.join('\n') || 'Не удалось создать заявление'
    $q.notify({ type: 'negative', message: detailError.value })
  }
}

function hasDocumentInput(document) {
  return Object.entries(document).some(([key, value]) => !['is_primary', 'verification_status', 'release_country_name', 'average_score_scale'].includes(key) && value !== '' && value !== null)
}

async function saveApplication() {
  if (!selected.value || selectedRegistered.value) return
  detailError.value = ''

  try {
    await store.updateApplication(selected.value.id, normalizePayload(applicationForm))
    await load()
    $q.notify({ type: 'positive', message: 'Заявление сохранено' })
  } catch (err) {
    detailError.value = err.message || 'Не удалось сохранить заявление'
  }
}

async function registerSelected() {
  if (!selected.value || selectedRegistered.value) return

  try {
    await store.registerApplication(selected.value.id, true)
    await load()
    $q.notify({ type: 'positive', message: 'Заявление зарегистрировано' })
  } catch (err) {
    detailError.value = err.message || 'Регистрация отклонена backend-проверкой'
  }
}

async function saveIdentityDocument() {
  if (!selected.value || selectedRegistered.value) return
  try {
    await store.createIdentityDocument(selected.value.applicant_id, normalizePayload(identityForm), selected.value.id)
    Object.assign(identityForm, { series: '', number: '', issue_date: '', issued_by: '', subdivision_code: '' })
    $q.notify({ type: 'positive', message: 'Документ личности добавлен' })
  } catch (err) {
    detailError.value = err.message || 'Не удалось сохранить документ личности'
  }
}

async function saveEducationDocument() {
  if (!selected.value || selectedRegistered.value) return
  try {
    await store.createEducationDocument(selected.value.applicant_id, normalizePayload(educationForm), selected.value.id)
    Object.assign(educationForm, { series: '', number: '', issue_date: '', document_organization: '', average_score: '' })
    $q.notify({ type: 'positive', message: 'Документ об образовании добавлен' })
  } catch (err) {
    detailError.value = err.message || 'Не удалось сохранить документ об образовании'
  }
}

async function addChoice() {
  if (!selected.value || selectedRegistered.value) return
  try {
    await store.createChoice(selected.value.id, normalizePayload(choiceForm))
    choiceForm.priority = store.sortedChoices.length + 1
    $q.notify({ type: 'positive', message: 'Программа добавлена' })
  } catch (err) {
    detailError.value = err.message || 'Не удалось добавить программу'
  }
}

async function moveChoice(choice, direction) {
  const nextPriority = Number(choice.priority || 0) + direction
  if (nextPriority < 1 || selectedRegistered.value) return
  await store.updateChoice(choice.id, { priority: nextPriority }).catch((err) => {
    detailError.value = err.message || 'Не удалось изменить приоритет'
  })
}

async function uploadSelectedFiles() {
  if (!uploadFiles.value?.length || selectedRegistered.value) return
  const identityId = selectedUploadIdentityId.value
  const educationId = selectedUploadEducationId.value

  try {
    for (const file of uploadFiles.value) {
      if (identityId) await store.uploadDocumentFile('identity', identityId, file, selected.value.id, uploadCategory.value)
      if (educationId) await store.uploadDocumentFile('education', educationId, file, selected.value.id, uploadCategory.value)
    }
    uploadFiles.value = []
    $q.notify({ type: 'positive', message: 'Файлы загружены' })
  } catch (err) {
    detailError.value = err.message || 'Не удалось загрузить файлы'
  }
}

watch(() => route.query.selected, async (value) => {
  if (syncingQuery.value) return
  if (value) {
    await store.loadApplication(String(value)).catch(() => {})
    fillApplicationForm()
    fillPersonForm()
    fillApplicantForm()
  } else {
    await store.selectApplication(null)
  }
})

watch(() => [route.query.status, route.query.admission_year, route.query.source_id, route.query.has_choices], async () => {
  if (syncingQuery.value) return
  store.setFilters(filtersFromRoute())
  await load(tablePagination.value)
}, { deep: true })

watch(selected, () => {
  fillApplicationForm()
  fillPersonForm()
  fillApplicantForm()
})

onMounted(async () => {
  store.reset()
  store.setFilters(filtersFromRoute())
  await Promise.all([
    store.loadReferences(),
    store.loadEducationPrograms(),
    store.searchApplicants(''),
    store.searchPeople(''),
  ])
  await load(tablePagination.value)

  if (routeSelectedId()) {
    await store.loadApplication(routeSelectedId()).catch(() => {})
    fillApplicationForm()
    fillPersonForm()
    fillApplicantForm()
  }
})
</script>

<template>
  <AppPage>
    <PageHeader
      title="Приёмная комиссия"
      subtitle="Рабочее место сотрудника приёмной комиссии: заявления, документы, выбранные программы, комплектность и регистрация."
    >
      <template #actions>
        <q-btn color="primary" unelevated @click="openWizard">
          <Plus :size="16" class="q-mr-xs" />
          Новое заявление
        </q-btn>
      </template>
    </PageHeader>

    <AppToolbar>
      <span>{{ tableSubtitle }}</span>
      <template #actions>
        <AppLoading v-if="store.loading || store.detailsLoading || store.saving" label="Обработка заявления..." />
        <q-btn flat @click="resetSplitter">Сбросить размер</q-btn>
        <q-btn flat :disable="store.loading" @click="load()">
          <RefreshCw :size="16" class="q-mr-xs" />
          Обновить
        </q-btn>
      </template>
    </AppToolbar>

    <AppErrorBanner :message="store.error || detailError" />
    <q-banner v-if="validationMessages.length" rounded class="admissions-foundation-warning">
      <AlertCircle :size="18" />
      <div>
        <strong>Проверьте поля формы</strong>
        <div v-for="message in validationMessages" :key="message">{{ message }}</div>
      </div>
    </q-banner>

    <AppFilterBar>
      <q-input v-model="store.filters.q" dense outlined clearable label="Номер заявления или ФИО" @keyup.enter="applyFilters">
        <template #prepend><Search :size="16" /></template>
      </q-input>
      <q-select v-model="store.filters.status" dense outlined emit-value map-options label="Статус" :options="statusOptions" />
      <q-input v-model="store.filters.admission_year" dense outlined clearable label="Год приема" />
      <q-select v-model="store.filters.source_id" dense outlined emit-value map-options label="Источник" :options="sourceOptions" />
      <q-select v-model="store.filters.has_choices" dense outlined emit-value map-options label="Выбранные программы" :options="hasChoicesOptions" />

      <template #actions>
        <q-btn color="primary" :disable="store.loading" @click="applyFilters">Применить</q-btn>
        <q-btn flat :disable="store.loading" @click="resetFilters">Очистить</q-btn>
      </template>

      <template #footer>
        <div class="admissions-foundation-filter-chips">
          <q-btn dense flat no-caps @click="quickFilter({ status: 'draft' })">Черновики</q-btn>
          <q-btn dense flat no-caps @click="quickFilter({ status: 'registered' })">Зарегистрированы</q-btn>
          <q-btn dense flat no-caps @click="quickFilter({ has_choices: '0' })">Без программ</q-btn>
          <q-chip v-for="chip in filterChips" :key="chip.key" removable dense @remove="clearFilter(chip.key)">
            {{ chip.label }}
          </q-chip>
        </div>
      </template>
    </AppFilterBar>

    <div ref="workspaceRef" class="admissions-foundation-workspace" :style="workspaceStyle">
      <section class="admissions-foundation-main">
        <AppTable
          v-if="store.applications.length || store.loading"
          :rows="store.applications"
          :columns="columns"
          :loading="store.loading"
          :pagination="tablePagination"
          :rows-per-page-options="rowsPerPageOptions"
          :table-row-class-fn="tableRowClass"
          @update:pagination="updateTablePagination"
          @request="handleTableRequest"
          @row-click="(_, row) => selectApplication(row)"
        >
          <template #body-cell-application_number="props">
            <q-td :props="props">
              <button class="admissions-foundation-row-link" type="button" @click.stop="selectApplication(props.row)">
                {{ props.row.application_number || `#${props.row.id}` }}
              </button>
            </q-td>
          </template>
          <template #body-cell-applicant="props">
            <q-td :props="props">
              <div class="admissions-foundation-person-cell">
                <strong>{{ personName(props.row) }}</strong>
                <small>{{ props.row.applicant?.uuid ? `Код абитуриента: ${props.row.applicant.uuid}` : 'Код абитуриента не указан' }}</small>
              </div>
            </q-td>
          </template>
          <template #body-cell-status="props">
            <q-td :props="props"><AppStatusBadge :label="statusLabel(props.row)" :tone="statusTone(statusCode(props.row))" /></q-td>
          </template>
          <template #body-cell-choices_count="props">
            <q-td :props="props"><q-chip dense :color="Number(props.row.choices_count || 0) > 0 ? 'green-1' : 'grey-2'" text-color="dark">{{ props.row.choices_count ?? 0 }}</q-chip></q-td>
          </template>
          <template #body-cell-documents="props">
            <q-td :props="props"><q-chip dense color="blue-1" text-color="primary">{{ Number(props.row.id) === Number(store.selectedId) ? readinessLabel : 'Открыть' }}</q-chip></q-td>
          </template>
          <template #body-cell-created_at="props"><q-td :props="props">{{ formatDate(props.row.created_at) }}</q-td></template>
          <template #body-cell-registered_at="props"><q-td :props="props">{{ formatDate(props.row.registered_at) }}</q-td></template>
          <template #body-cell-actions="props">
            <q-td :props="props">
              <q-btn flat round dense title="Открыть" @click.stop="selectApplication(props.row)"><Eye :size="16" /></q-btn>
            </q-td>
          </template>
        </AppTable>
        <AppEmptyState v-else title="Заявления не найдены" description="Измените фильтры или создайте новое заявление." />
      </section>

      <WorkspaceSplitter label="Изменить ширину карточки заявления" @resize-start="startResize" @reset="resetSplitter" />

      <aside class="admissions-foundation-side">
        <AppEmptyState v-if="!selected && !store.detailsError" title="Заявление не выбрано" description="Выберите строку или создайте новое заявление.">
          <FileSearch :size="44" />
        </AppEmptyState>
        <q-banner v-else-if="store.detailsError" rounded class="app-error-banner">
          <div class="row items-center justify-between q-gutter-sm">
            <span>{{ store.detailsError }}</span>
            <q-btn flat label="Повторить" @click="refreshSelected" />
          </div>
        </q-banner>

        <WorkspacePanel v-else class="admissions-foundation-card" :title="selectedTitle" :subtitle="selectedSubtitle" :metrics="selectedMetrics">
          <template #status><AppStatusBadge :label="statusLabel(selected)" :tone="selectedStatusTone" /></template>

          <template #actions>
            <div class="admissions-foundation-actions">
              <q-btn color="primary" unelevated :disable="selectedRegistered || store.saving" @click="saveApplication">
                <Save :size="16" class="q-mr-xs" /> Сохранить
              </q-btn>
              <q-btn color="positive" unelevated :disable="selectedRegistered || store.saving" @click="registerSelected">
                <ClipboardCheck :size="16" class="q-mr-xs" /> Зарегистрировать
              </q-btn>
            </div>
          </template>

          <q-banner v-if="selectedRegistered" rounded class="admissions-foundation-note">
            <AlertCircle :size="18" />
            Заявление зарегистрировано. Реквизиты и файлы закрепленных документов доступны только для чтения.
          </q-banner>

          <q-tabs v-model="activeTab" dense align="left" class="text-primary admissions-foundation-tabs">
            <q-tab name="general" label="Общее" />
            <q-tab name="person" label="Личные данные" />
            <q-tab name="applicant" label="Абитуриент" />
            <q-tab name="documents" label="Документы" />
            <q-tab name="choices" label="Специальности" />
            <q-tab name="readiness" label="Комплектность" />
            <q-tab name="files" label="Файлы" />
            <q-tab name="fis" label="ФИС" />
            <q-tab name="history" label="История" />
          </q-tabs>

          <q-tab-panels v-model="activeTab" animated class="admissions-foundation-panels">
            <q-tab-panel name="general" class="q-pa-none">
              <section class="admissions-foundation-section">
                <h3>Заявление</h3>
                <div class="admissions-foundation-form-grid">
                  <q-input v-model="applicationForm.application_number" dense outlined label="Номер заявления" :readonly="selectedRegistered" />
                  <q-input v-model.number="applicationForm.admission_year" dense outlined type="number" label="Год приема" :readonly="selectedRegistered" />
                  <q-select v-model="applicationForm.education_program_id" dense outlined emit-value map-options label="Основная программа" :options="programOptions" :readonly="selectedRegistered" />
                  <q-select v-model="applicationForm.source_id" dense outlined emit-value map-options label="Источник" :options="sourceOptions" :readonly="selectedRegistered" />
                  <q-input v-model="applicationForm.submitted_at" dense outlined type="date" label="Дата подачи" :readonly="selectedRegistered" />
                  <q-select v-model="applicationForm.education_base" dense outlined emit-value map-options label="База поступления" :options="applicationEducationBaseOptions" :readonly="selectedRegistered" />
                </div>
                <q-input v-model="applicationForm.comment" dense outlined type="textarea" autogrow label="Комментарий" :readonly="selectedRegistered" />
              </section>

              <section class="admissions-foundation-section">
                <h3>Абитуриент</h3>
                <dl>
                  <div><dt>ФИО</dt><dd>{{ personName(selected) }}</dd></div>
                  <div><dt>Дата рождения</dt><dd>{{ formatDate(selectedPerson?.birth_date) }}</dd></div>
                  <div><dt>Пол</dt><dd>{{ genderLabel(selectedPerson?.gender) }}</dd></div>
                  <div><dt>Гражданство</dt><dd>{{ selectedPerson?.citizenship || '—' }}</dd></div>
                  <div><dt>Телефон</dt><dd>{{ formatPhone(selectedPerson?.phone, '—') }}</dd></div>
                  <div><dt>Email</dt><dd>{{ selectedPerson?.email || '—' }}</dd></div>
                  <div><dt>СНИЛС</dt><dd>{{ selectedPerson?.snils_masked || 'Не указан' }}</dd></div>
                </dl>
              </section>
            </q-tab-panel>

            <q-tab-panel name="person" class="q-pa-none">
              <section class="admissions-foundation-section">
                <div class="admissions-foundation-section-header">
                  <h3>Личные данные</h3>
                  <q-btn v-if="!selectedRegistered" color="primary" flat dense no-caps @click="fillPersonForm(); personEditorOpen = !personEditorOpen">
                    <Edit3 :size="15" class="q-mr-xs" /> {{ personEditorOpen ? 'Свернуть' : 'Редактировать' }}
                  </q-btn>
                </div>
                <dl>
                  <div><dt>ФИО</dt><dd>{{ selectedPerson?.full_name || personName(selected) }}</dd></div>
                  <div><dt>Дата рождения</dt><dd>{{ formatDate(selectedPerson?.birth_date) }}</dd></div>
                  <div><dt>Пол</dt><dd>{{ genderLabel(selectedPerson?.gender) }}</dd></div>
                  <div><dt>Место рождения</dt><dd>{{ selectedPerson?.place_birth || '—' }}</dd></div>
                  <div><dt>Гражданство</dt><dd>{{ selectedPerson?.citizenship || '—' }}</dd></div>
                  <div><dt>Телефон</dt><dd>{{ formatPhone(selectedPerson?.phone, '—') }}</dd></div>
                  <div><dt>Email</dt><dd>{{ selectedPerson?.email || '—' }}</dd></div>
                  <div><dt>СНИЛС</dt><dd>{{ selectedPerson?.snils_masked || 'Не указан' }}</dd></div>
                  <div><dt>Статус</dt><dd>{{ personStatusLabel(selectedPerson?.status) }}</dd></div>
                </dl>
              </section>

              <section v-if="personEditorOpen && !selectedRegistered" class="admissions-foundation-section">
                <h3>Редактирование личных данных</h3>
                <div class="admissions-foundation-form-grid">
                  <q-input v-model="personForm.last_name" dense outlined label="Фамилия" />
                  <q-input v-model="personForm.first_name" dense outlined label="Имя" />
                  <q-input v-model="personForm.middle_name" dense outlined label="Отчество" />
                  <q-input v-model="personForm.birth_date" dense outlined type="date" label="Дата рождения" />
                  <q-select v-model="personForm.gender" dense outlined emit-value map-options label="Пол" :options="genderOptions" />
                  <q-input v-model="personForm.citizenship" dense outlined label="Гражданство" />
                  <q-input v-model="personForm.place_birth" dense outlined label="Место рождения" />
                  <q-input v-model="personForm.phone" dense outlined label="Телефон" />
                  <q-input v-model="personForm.email" dense outlined type="email" label="Email" />
                  <q-input :model-value="personForm.snils" dense outlined label="СНИЛС" @update:model-value="personForm.snils = snilsMask($event)" />
                  <q-input v-model="personForm.inn" dense outlined label="ИНН" />
                  <q-select v-model="personForm.status" dense outlined emit-value map-options label="Статус" :options="personStatusOptions" />
                </div>
                <q-input v-model="personForm.address" dense outlined type="textarea" autogrow label="Адрес" />
                <div class="admissions-foundation-actions">
                  <q-btn color="primary" unelevated :loading="store.saving" @click="savePersonCard">
                    <Save :size="16" class="q-mr-xs" /> Сохранить личные данные
                  </q-btn>
                </div>
              </section>
            </q-tab-panel>

            <q-tab-panel name="applicant" class="q-pa-none">
              <section class="admissions-foundation-section">
                <div class="admissions-foundation-section-header">
                  <h3>Карточка абитуриента</h3>
                  <div class="admissions-foundation-actions">
                    <q-btn v-if="!selectedRegistered" color="primary" flat dense no-caps @click="fillApplicantForm(); applicantEditorOpen = !applicantEditorOpen">
                      <Edit3 :size="15" class="q-mr-xs" /> {{ applicantEditorOpen ? 'Свернуть' : 'Редактировать' }}
                    </q-btn>
                    <q-btn v-if="!selectedRegistered && !selectedApplicant?.archived_at" color="negative" flat dense no-caps @click="archiveSelectedApplicant">
                      <Archive :size="15" class="q-mr-xs" /> Архивировать
                    </q-btn>
                  </div>
                </div>
                <dl>
                  <div><dt>ID абитуриента</dt><dd>{{ selectedApplicant?.id || '—' }}</dd></div>
                  <div><dt>Код записи</dt><dd>{{ selectedApplicant?.uuid || '—' }}</dd></div>
                  <div><dt>Источник</dt><dd>{{ referenceLabel(selectedApplicant?.source) }}</dd></div>
                  <div><dt>Статус</dt><dd>{{ referenceLabel(selectedApplicant?.status) }}</dd></div>
                  <div><dt>Первый контакт</dt><dd>{{ formatDate(selectedApplicant?.first_contact_at) }}</dd></div>
                  <div><dt>Ответственный</dt><dd>{{ selectedApplicant?.responsible_user?.name || '—' }}</dd></div>
                  <div><dt>Архив</dt><dd>{{ selectedApplicant?.archived_at ? formatDateTime(selectedApplicant.archived_at) : 'Нет' }}</dd></div>
                </dl>
                <q-banner v-if="selectedApplicant?.notes" rounded class="admissions-foundation-note">
                  <FileText :size="18" /> {{ selectedApplicant.notes }}
                </q-banner>
              </section>

              <section v-if="applicantEditorOpen && !selectedRegistered" class="admissions-foundation-section">
                <h3>Редактирование абитуриента</h3>
                <div class="admissions-foundation-form-grid">
                  <q-select v-model="applicantForm.person_id" dense outlined emit-value map-options label="Личные данные" :options="personOptions" disable hint="Связь с личной карточкой у существующего абитуриента здесь не меняется." />
                  <q-select v-model="applicantForm.source_id" dense outlined emit-value map-options label="Источник" :options="sourceOptions" />
                  <q-select v-model="applicantForm.status_id" dense outlined emit-value map-options label="Статус" :options="applicantStatusOptions" />
                  <q-input v-model="applicantForm.first_contact_at" dense outlined type="date" label="Первый контакт" />
                </div>
                <q-input v-model="applicantForm.notes" dense outlined type="textarea" autogrow label="Заметки" />
                <div class="admissions-foundation-actions">
                  <q-btn color="primary" unelevated :loading="store.saving" @click="saveApplicantCard">
                    <Save :size="16" class="q-mr-xs" /> Сохранить абитуриента
                  </q-btn>
                </div>
              </section>
            </q-tab-panel>

            <q-tab-panel name="documents" class="q-pa-none">
              <section class="admissions-foundation-section">
                <h3>Закрепленные документы заявления</h3>
                <dl>
                  <div><dt>Документ личности</dt><dd>{{ assignedIdentityId || 'Не закреплен' }}</dd></div>
                  <div><dt>Документ об образовании</dt><dd>{{ assignedEducationId || 'Не закреплен' }}</dd></div>
                  <div><dt>Фиксация</dt><dd>{{ formatDateTime(store.documentSet?.linked_at) }}</dd></div>
                </dl>
              </section>

              <section class="admissions-foundation-section">
                <h3>Identity Documents</h3>
                <AppTable :rows="store.identityDocuments" :columns="documentColumns" :pagination="{ rowsPerPage: 0 }" :rows-per-page-options="[0]">
                  <template #body-cell-number="props"><q-td :props="props">{{ documentNumber(props.row) }}</q-td></template>
                  <template #body-cell-version="props"><q-td :props="props"><q-chip dense :color="Number(props.row.id) === Number(assignedIdentityId) ? 'green-1' : 'grey-2'">v{{ props.row.version_number || 1 }}</q-chip></q-td></template>
                  <template #body-cell-status="props"><q-td :props="props"><AppStatusBadge :label="props.row.verification_status" :tone="statusTone(props.row.verification_status)" /></q-td></template>
                  <template #body-cell-actions="props">
                    <q-td :props="props">
                      <q-btn v-if="!selectedRegistered" flat dense no-caps :disable="Number(props.row.id) === Number(assignedIdentityId)" @click="store.assignIdentityDocument(selected.id, props.row.id)">Закрепить</q-btn>
                    </q-td>
                  </template>
                </AppTable>
              </section>

              <section class="admissions-foundation-section">
                <h3>Education Documents</h3>
                <AppTable :rows="store.educationDocuments" :columns="documentColumns" :pagination="{ rowsPerPage: 0 }" :rows-per-page-options="[0]">
                  <template #body-cell-number="props"><q-td :props="props">{{ documentNumber(props.row) }}</q-td></template>
                  <template #body-cell-version="props"><q-td :props="props"><q-chip dense :color="Number(props.row.id) === Number(assignedEducationId) ? 'green-1' : 'grey-2'">v{{ props.row.version_number || 1 }}</q-chip></q-td></template>
                  <template #body-cell-status="props"><q-td :props="props"><AppStatusBadge :label="props.row.verification_status" :tone="statusTone(props.row.verification_status)" /></q-td></template>
                  <template #body-cell-actions="props">
                    <q-td :props="props">
                      <q-btn v-if="!selectedRegistered" flat dense no-caps :disable="Number(props.row.id) === Number(assignedEducationId)" @click="store.assignEducationDocument(selected.id, props.row.id)">Закрепить</q-btn>
                    </q-td>
                  </template>
                </AppTable>
              </section>

              <section v-if="!selectedRegistered" class="admissions-foundation-section">
                <h3>Добавить документ</h3>
                <q-expansion-item dense label="Паспорт / документ личности" icon="badge">
                  <div class="admissions-foundation-form-grid q-mt-sm">
                    <q-select v-model="identityForm.document_type_id" dense outlined emit-value map-options label="Тип документа" :options="identityTypeOptions" />
                    <q-input v-model="identityForm.series" dense outlined label="Серия" />
                    <q-input v-model="identityForm.number" dense outlined label="Номер" />
                    <q-input v-model="identityForm.issue_date" dense outlined type="date" label="Дата выдачи" />
                    <q-input v-model="identityForm.issued_by" dense outlined label="Кем выдан" />
                    <q-input v-model="identityForm.subdivision_code" dense outlined label="Код подразделения" />
                    <q-select v-model="identityForm.verification_status" dense outlined emit-value map-options label="Проверка" :options="verificationStatusOptions" />
                  </div>
                  <q-btn class="q-mt-sm" color="primary" unelevated :disable="store.saving" @click="saveIdentityDocument">Добавить и закрепить</q-btn>
                </q-expansion-item>
                <q-expansion-item dense label="Документ об образовании" icon="school">
                  <div class="admissions-foundation-form-grid q-mt-sm">
                    <q-select v-model="educationForm.document_type_id" dense outlined emit-value map-options label="Тип документа" :options="educationTypeOptions" />
                    <q-input v-model="educationForm.series" dense outlined label="Серия" />
                    <q-input v-model="educationForm.number" dense outlined label="Номер" />
                    <q-input v-model="educationForm.issue_date" dense outlined type="date" label="Дата выдачи" />
                    <q-input v-model="educationForm.document_organization" dense outlined label="Выдавшая организация" />
                    <q-select v-model="educationForm.education_level_id" dense outlined emit-value map-options label="Уровень образования" :options="educationLevelOptions" />
                    <q-input v-model.number="educationForm.average_score" dense outlined type="number" step="0.01" label="Средний балл" />
                    <q-input v-model="educationForm.qualification_name" dense outlined label="Квалификация" />
                    <q-input v-model="educationForm.speciality_name" dense outlined label="Специальность / профессия" />
                    <q-select v-model="educationForm.verification_status" dense outlined emit-value map-options label="Проверка" :options="verificationStatusOptions" />
                  </div>
                  <q-btn class="q-mt-sm" color="primary" unelevated :disable="store.saving" @click="saveEducationDocument">Добавить и закрепить</q-btn>
                </q-expansion-item>
              </section>
            </q-tab-panel>

            <q-tab-panel name="choices" class="q-pa-none">
              <section class="admissions-foundation-section">
                <h3>Выбранные специальности и программы</h3>
                <q-banner rounded class="admissions-foundation-note">
                  Форма обучения и финансирование отображаются из BACK-004, если они уже заданы. Их справочники пока не отдаются admissions reference API, поэтому выбор этих полей оставлен для следующего backend/frontend slice.
                </q-banner>
                <AppTable :rows="store.sortedChoices" :columns="choiceColumns" :pagination="{ rowsPerPage: 0 }" :rows-per-page-options="[0]">
                  <template #body-cell-program="props"><q-td :props="props">{{ choiceProgramName(props.row) }}</q-td></template>
                  <template #body-cell-actions="props">
                    <q-td :props="props">
                      <q-btn v-if="!selectedRegistered" flat dense round title="Выше" @click="moveChoice(props.row, -1)">↑</q-btn>
                      <q-btn v-if="!selectedRegistered" flat dense round title="Ниже" @click="moveChoice(props.row, 1)">↓</q-btn>
                      <q-btn v-if="!selectedRegistered" flat dense round color="negative" title="Удалить" @click="store.deleteChoice(props.row.id)"><Trash2 :size="15" /></q-btn>
                    </q-td>
                  </template>
                </AppTable>
              </section>
              <section v-if="!selectedRegistered" class="admissions-foundation-section">
                <h3>Добавить программу</h3>
                <div class="admissions-foundation-form-grid">
                  <q-input v-model.number="choiceForm.priority" dense outlined type="number" label="Приоритет" />
                  <q-select v-model="choiceForm.education_program_id" dense outlined emit-value map-options label="Программа" :options="programOptions" />
                  <q-select v-model="choiceForm.base_education_type_id" dense outlined emit-value map-options label="Основание" :options="baseEducationOptions" />
                  <q-select v-model="choiceForm.quota_type_id" dense outlined emit-value map-options label="Квота" :options="quotaOptions" />
                  <q-select v-model="choiceForm.status_id" dense outlined emit-value map-options label="Статус" :options="choiceStatusOptions" />
                </div>
                <q-btn color="primary" unelevated :disable="store.saving" @click="addChoice">Добавить</q-btn>
              </section>
            </q-tab-panel>

            <q-tab-panel name="readiness" class="q-pa-none">
              <section class="admissions-foundation-section">
                <h3>Готовность заявления</h3>
                <div class="admissions-foundation-checklist">
                  <div :class="['admissions-foundation-check', store.readiness?.internal_complete ? 'is-ready' : 'is-pending']">
                    <span>Внутренняя комплектность</span><strong>{{ store.readiness?.internal_complete ? 'Готово' : 'Есть замечания' }}</strong>
                  </div>
                  <div :class="['admissions-foundation-check', store.readiness?.review_complete ? 'is-ready' : 'is-pending']">
                    <span>Проверка документов</span><strong>{{ store.readiness?.review_complete ? 'Готово' : 'Требует проверки' }}</strong>
                  </div>
                </div>
                <div v-if="store.readiness?.blocking_reasons_detailed?.length" class="admissions-foundation-blockers">
                  <q-banner v-for="item in store.readiness.blocking_reasons_detailed" :key="item.code" rounded class="admissions-foundation-warning">
                    <strong>{{ item.code }}</strong><span>{{ item.message }}</span>
                  </q-banner>
                </div>
                <div v-if="store.readiness?.review_blocking_reasons_detailed?.length" class="admissions-foundation-blockers">
                  <q-banner v-for="item in store.readiness.review_blocking_reasons_detailed" :key="item.code" rounded class="admissions-foundation-warning">
                    <strong>{{ item.code }}</strong><span>{{ item.message }}</span>
                  </q-banner>
                </div>
              </section>

              <!-- Требования схемы ФИС — отдельно от комплектности документов:
                   там правила приёмной комиссии, здесь то, без чего заявление не
                   пройдёт выгрузку, и совпадают они не всегда. -->
              <section class="admissions-foundation-section">
                <h3>Выгрузка в ФИС ГИА</h3>
                <div v-if="store.fisReadinessLoading" class="text-caption text-grey-7">Проверяем…</div>
                <template v-else-if="store.fisReadiness">
                  <div class="admissions-foundation-checklist">
                    <div :class="['admissions-foundation-check', store.fisReadiness.ready ? 'is-ready' : 'is-pending']">
                      <span>Готовность к выгрузке</span>
                      <strong>{{ store.fisReadiness.ready ? 'Готово' : 'Не хватает данных' }}</strong>
                    </div>
                  </div>
                  <div v-if="store.fisReadiness.blockers?.length" class="admissions-foundation-blockers">
                    <q-banner v-for="(item, index) in store.fisReadiness.blockers" :key="`${item.code}-${index}`" rounded class="admissions-foundation-warning">
                      <strong>{{ item.field || item.code }}</strong><span>{{ item.message }}</span>
                    </q-banner>
                  </div>
                </template>
                <div v-else class="text-caption text-grey-7">Проверка недоступна.</div>
              </section>
            </q-tab-panel>

            <q-tab-panel name="files" class="q-pa-none">
              <section class="admissions-foundation-section">
                <h3>Файлы документов</h3>
                <AppTable :rows="allFiles" :columns="fileColumns" :pagination="{ rowsPerPage: 0 }" :rows-per-page-options="[0]">
                  <template #body-cell-size="props"><q-td :props="props">{{ fileSize(props.row.size_bytes) }}</q-td></template>
                  <template #body-cell-uploaded_at="props"><q-td :props="props">{{ formatDateTime(props.row.uploaded_at) }}</q-td></template>
                  <template #body-cell-actions="props">
                    <q-td :props="props">
                      <q-btn flat dense round title="Скачать" @click="store.downloadDocumentFile(props.row)"><Download :size="15" /></q-btn>
                      <q-btn v-if="!selectedRegistered" flat dense round color="negative" title="Удалить" @click="store.deleteDocumentFile(props.row.id)"><Trash2 :size="15" /></q-btn>
                    </q-td>
                  </template>
                </AppTable>
              </section>
              <section v-if="!selectedRegistered" class="admissions-foundation-section">
                <h3>Загрузка файлов</h3>
                <div class="admissions-foundation-form-grid">
                  <q-select v-model="selectedUploadIdentityId" dense outlined clearable emit-value map-options label="Документ личности" :options="identityUploadOptions" />
                  <q-select v-model="selectedUploadEducationId" dense outlined clearable emit-value map-options label="Документ об образовании" :options="educationUploadOptions" />
                  <q-select v-model="uploadCategory" dense outlined emit-value map-options label="Категория" :options="fileCategoryOptions" />
                  <q-file v-model="uploadFiles" dense outlined multiple label="Файлы" />
                </div>
                <q-btn color="primary" unelevated :disable="!uploadFiles?.length || (!selectedUploadIdentityId && !selectedUploadEducationId)" @click="uploadSelectedFiles">
                  <Upload :size="16" class="q-mr-xs" /> Загрузить
                </q-btn>
              </section>
            </q-tab-panel>

            <q-tab-panel name="fis" class="q-pa-none">
              <section class="admissions-foundation-section">
                <h3>Готовность к ФИС</h3>
                <dl>
                  <div><dt>Данные ФИС</dt><dd>{{ store.readiness?.fis?.fis_data_ready ? 'Готовы' : 'Не готовы' }}</dd></div>
                  <div><dt>Mapping справочников</dt><dd>{{ store.readiness?.fis?.fis_mapping_ready ? 'Готов' : 'Есть несопоставленные значения' }}</dd></div>
                  <div><dt>XSD-структуры</dt><dd>{{ store.readiness?.fis?.supported_xsd_structures?.join(', ') || '—' }}</dd></div>
                </dl>
                <div class="admissions-foundation-blockers">
                  <q-banner v-for="item in store.readiness?.fis?.blocking_reasons_detailed || []" :key="item.code" rounded class="admissions-foundation-warning">
                    <strong>{{ item.field }}</strong><span>{{ item.message }}</span>
                  </q-banner>
                </div>
              </section>
            </q-tab-panel>

            <q-tab-panel name="history" class="q-pa-none">
              <section class="admissions-foundation-section">
                <h3>История изменений</h3>
                <AppLoading v-if="store.auditLoading" label="Загрузка истории..." />
                <div v-else-if="store.auditLogs.length" class="admissions-foundation-history">
                  <div v-for="log in store.auditLogs" :key="log.id" class="admissions-foundation-history-item">
                    <History :size="16" />
                    <div><strong>{{ log.action }}</strong><span>{{ formatDateTime(log.created_at) }} · {{ log.user?.name || 'system' }}</span></div>
                  </div>
                </div>
                <q-banner v-else rounded class="admissions-foundation-note">История по выбранному заявлению пока не найдена в доступной выборке Audit API.</q-banner>
              </section>
            </q-tab-panel>
          </q-tab-panels>
        </WorkspacePanel>
      </aside>
    </div>

    <q-dialog v-model="wizardOpen" persistent maximized>
      <q-card class="admissions-foundation-wizard">
        <q-card-section class="row items-center justify-between">
          <div>
            <h2>Новое заявление</h2>
            <p>Можно выбрать существующего абитуриента или создать нового вместе с личными данными.</p>
          </div>
          <q-btn flat round dense icon="close" @click="wizardOpen = false" />
        </q-card-section>
        <q-separator />
        <q-card-section>
          <q-stepper v-model="wizardStep" flat animated contracted class="admissions-foundation-stepper" active-color="primary" done-color="positive">
            <q-step name="application" title="Общие сведения" :done="isWizardStepDone('application')" :color="wizardStepColor('application')">
              <q-banner v-if="wizardError" rounded class="admissions-foundation-warning q-mb-md">
                <AlertCircle :size="18" /> {{ wizardError }}
              </q-banner>
              <div class="admissions-foundation-mode">
                <q-option-group v-model="wizardApplicantMode" inline :options="applicantModeOptions" color="primary" />
              </div>

              <section v-if="wizardApplicantMode === 'existing'" class="admissions-foundation-section">
                <h3>Существующий абитуриент</h3>
                <q-select v-model="wizardForm.applicant_id" use-input dense outlined emit-value map-options label="Абитуриент" :options="applicantOptions" :loading="store.applicantsLoading" :error="Boolean(fieldError('applicant_id'))" :error-message="fieldError('applicant_id')" @filter="filterApplicants" />
                <q-banner v-if="wizardApplicant" rounded class="admissions-foundation-note q-mt-md">
                  <FileText :size="18" />
                  {{ applicantLabel(wizardApplicant) }}
                </q-banner>
              </section>

              <section v-else class="admissions-foundation-section">
                <h3>Новый абитуриент</h3>
                <div class="admissions-foundation-form-grid">
                  <q-select v-model="applicantForm.person_id" use-input dense outlined clearable emit-value map-options label="Использовать существующую личную карточку" :options="personOptions" :loading="store.peopleLoading" @filter="filterPeople" />
                  <q-select v-model="applicantForm.source_id" dense outlined emit-value map-options label="Источник" :options="sourceOptions" />
                  <q-select v-model="applicantForm.status_id" dense outlined emit-value map-options label="Статус абитуриента" :options="applicantStatusOptions" />
                  <q-input v-model="applicantForm.first_contact_at" dense outlined type="date" label="Первый контакт" />
                </div>
                <q-input v-model="applicantForm.notes" dense outlined type="textarea" autogrow label="Заметки по абитуриенту" />

                <div v-if="!applicantForm.person_id" class="admissions-foundation-subsection">
                  <h3>Новые личные данные</h3>
                  <div class="admissions-foundation-form-grid">
                    <q-input v-model="personForm.last_name" dense outlined label="Фамилия" :error="Boolean(fieldError('last_name'))" :error-message="fieldError('last_name')" />
                    <q-input v-model="personForm.first_name" dense outlined label="Имя" :error="Boolean(fieldError('first_name'))" :error-message="fieldError('first_name')" />
                    <q-input v-model="personForm.middle_name" dense outlined label="Отчество" />
                    <q-input v-model="personForm.birth_date" dense outlined type="date" label="Дата рождения" :error="Boolean(fieldError('birth_date'))" :error-message="fieldError('birth_date')" />
                    <q-select v-model="personForm.gender" dense outlined emit-value map-options label="Пол" :options="genderOptions" />
                    <q-input v-model="personForm.citizenship" dense outlined label="Гражданство" />
                    <q-input v-model="personForm.place_birth" dense outlined label="Место рождения" />
                    <q-input v-model="personForm.phone" dense outlined label="Телефон" :error="Boolean(fieldError('phone'))" :error-message="fieldError('phone')" />
                    <q-input v-model="personForm.email" dense outlined type="email" label="Email" :error="Boolean(fieldError('email'))" :error-message="fieldError('email')" />
                    <q-input :model-value="personForm.snils" dense outlined label="СНИЛС" :error="Boolean(fieldError('person_snils'))" :error-message="fieldError('person_snils')" @update:model-value="personForm.snils = snilsMask($event)" />
                  </div>
                  <q-input v-model="wizardAdditionalPerson.registration_address" dense outlined type="textarea" autogrow label="Адрес регистрации" />
                  <q-input v-model="wizardAdditionalPerson.residential_address" dense outlined type="textarea" autogrow label="Адрес проживания" hint="Заполняется, если отличается от адреса регистрации." />
                  <q-expansion-item dense label="Дополнительные данные" caption="Не обязательны для основного сценария приема">
                    <div class="admissions-foundation-form-grid q-pt-sm">
                      <q-input v-model="wizardAdditionalPerson.inn" dense outlined label="ИНН" />
                    </div>
                  </q-expansion-item>
                  <div class="admissions-foundation-actions">
                    <q-btn flat color="primary" :loading="store.saving" @click="runDuplicateCheck">
                      <Users :size="16" class="q-mr-xs" /> Проверить дубли
                    </q-btn>
                  </div>
                </div>

                <div v-if="duplicateResult" class="admissions-foundation-duplicates">
                  <q-banner v-if="!duplicateResult.has_matches" rounded class="admissions-foundation-note">
                    <UserCheck :size="18" /> Дубли не найдены. При создании заявления будет создана новая личная карточка.
                  </q-banner>
                  <q-banner v-else rounded class="admissions-foundation-warning">
                    <AlertCircle :size="18" /> Найдено совпадений: {{ duplicateResult.matches_count }}. Автоматическое объединение не выполняется.
                  </q-banner>
                  <div v-for="match in duplicateMatches" :key="match.person.id" class="admissions-foundation-duplicate">
                    <div>
                      <strong>{{ personOptionLabel(match.person) }}</strong>
                      <small>Совпадение: {{ match.matched_by.join(', ') }}</small>
                    </div>
                    <q-btn flat dense no-caps color="primary" @click="useDuplicatePerson(match)">
                      <Link :size="15" class="q-mr-xs" /> Использовать
                    </q-btn>
                  </div>
                  <q-banner v-if="duplicateDecision" rounded class="admissions-foundation-note">{{ duplicateDecision }}</q-banner>
                </div>
              </section>

              <div class="admissions-foundation-form-grid">
                <q-select v-model="wizardForm.education_program_id" dense outlined emit-value map-options label="Основная программа" :options="programOptions" :error="Boolean(fieldError('education_program_id'))" :error-message="fieldError('education_program_id')" />
                <q-input v-model.number="wizardForm.admission_year" dense outlined type="number" label="Год приема" :error="Boolean(fieldError('admission_year'))" :error-message="fieldError('admission_year')" />
                <q-input v-model="wizardForm.application_number" dense outlined label="Номер заявления" />
                <q-select v-model="wizardForm.source_id" dense outlined emit-value map-options label="Источник" :options="sourceOptions" :error="Boolean(fieldError('source_id'))" :error-message="fieldError('source_id')" />
                <q-input v-model="wizardForm.submitted_at" dense outlined type="date" label="Дата подачи" />
                <q-select v-model="wizardForm.education_base" dense outlined emit-value map-options label="База поступления" :options="legacyEducationBaseOptions" />
              </div>
              <q-stepper-navigation>
                <q-btn color="primary" @click="goWizardStep('identity')">Далее</q-btn>
              </q-stepper-navigation>
            </q-step>
            <q-step name="identity" title="Паспорт и СНИЛС" :done="isWizardStepDone('identity')" :color="wizardStepColor('identity')">
              <q-banner v-if="wizardError" rounded class="admissions-foundation-warning q-mb-md">
                <AlertCircle :size="18" /> {{ wizardError }}
              </q-banner>
              <div class="admissions-foundation-form-grid">
                <q-input :model-value="wizardForm.snils" dense outlined label="СНИЛС" :error="Boolean(fieldError('snils'))" :error-message="fieldError('snils')" @update:model-value="wizardForm.snils = snilsMask($event)" />
                <q-select v-model="wizardForm.identity.document_type_id" dense outlined emit-value map-options label="Тип документа" :options="identityTypeOptions" :error="Boolean(fieldError('identity_document_type_id'))" :error-message="fieldError('identity_document_type_id')" />
                <q-input v-model="wizardForm.identity.series" dense outlined label="Серия" />
                <q-input v-model="wizardForm.identity.number" dense outlined label="Номер" :error="Boolean(fieldError('identity_number'))" :error-message="fieldError('identity_number')" />
                <q-input v-model="wizardForm.identity.issue_date" dense outlined type="date" label="Дата выдачи" />
                <q-input v-model="wizardForm.identity.issued_by" dense outlined label="Кем выдан" />
                <q-input v-model="wizardForm.identity.subdivision_code" dense outlined label="Код подразделения" />
                <q-input v-model="personForm.place_birth" dense outlined label="Место рождения" />
              </div>
              <q-stepper-navigation>
                <q-btn flat @click="wizardStep = 'application'">Назад</q-btn>
                <q-btn color="primary" @click="goWizardStep('education')">Далее</q-btn>
              </q-stepper-navigation>
            </q-step>
            <q-step name="education" title="Образование" :done="isWizardStepDone('education')" :color="wizardStepColor('education')">
              <q-banner v-if="wizardError" rounded class="admissions-foundation-warning q-mb-md">
                <AlertCircle :size="18" /> {{ wizardError }}
              </q-banner>
              <div class="admissions-foundation-form-grid">
                <q-select v-model="wizardForm.education.document_type_id" dense outlined emit-value map-options label="Тип документа" :options="educationTypeOptions" :error="Boolean(fieldError('education_document_type_id'))" :error-message="fieldError('education_document_type_id')" />
                <q-input v-model="wizardForm.education.series" dense outlined label="Серия" />
                <q-input v-model="wizardForm.education.number" dense outlined label="Номер" :error="Boolean(fieldError('education_number'))" :error-message="fieldError('education_number')" />
                <q-input v-model="wizardForm.education.issue_date" dense outlined type="date" label="Дата выдачи" />
                <q-input v-model="wizardForm.education.document_organization" dense outlined label="Выдавшая организация" />
                <q-select v-model="wizardForm.education.education_level_id" dense outlined emit-value map-options label="Уровень образования" :options="educationLevelOptions" />
                <q-input v-model.number="wizardForm.education.average_score" dense outlined type="number" step="0.01" label="Средний балл" :error="Boolean(fieldError('education_average_score'))" :error-message="fieldError('education_average_score')" />
              </div>
              <q-stepper-navigation>
                <q-btn flat @click="wizardStep = 'identity'">Назад</q-btn>
                <q-btn color="primary" @click="goWizardStep('files')">Далее</q-btn>
              </q-stepper-navigation>
            </q-step>
            <q-step name="files" title="Файлы" :done="isWizardStepDone('files')" :color="wizardStepColor('files')">
              <div class="admissions-foundation-form-grid">
                <q-select v-model="uploadCategory" dense outlined emit-value map-options label="Категория" :options="fileCategoryOptions" />
                <q-file v-model="wizardForm.identityFiles" dense outlined multiple label="Файлы паспорта" />
                <q-file v-model="wizardForm.educationFiles" dense outlined multiple label="Файлы образования" />
              </div>
              <q-stepper-navigation>
                <q-btn flat @click="wizardStep = 'education'">Назад</q-btn>
                <q-btn color="primary" @click="goWizardStep('choices')">Далее</q-btn>
              </q-stepper-navigation>
            </q-step>
            <q-step name="choices" title="Специальности" :done="isWizardStepDone('choices')" :color="wizardStepColor('choices')">
              <div class="admissions-foundation-form-grid">
                <q-input v-model.number="wizardForm.choice.priority" dense outlined type="number" label="Приоритет" :error="Boolean(fieldError('choice_priority'))" :error-message="fieldError('choice_priority')" />
                <q-select v-model="wizardForm.choice.base_education_type_id" dense outlined emit-value map-options label="Основание" :options="baseEducationOptions" />
                <q-select v-model="wizardForm.choice.quota_type_id" dense outlined emit-value map-options label="Квота" :options="quotaOptions" />
                <q-select v-model="wizardForm.choice.status_id" dense outlined emit-value map-options label="Статус выбора" :options="choiceStatusOptions" />
              </div>
              <q-stepper-navigation>
                <q-btn flat @click="wizardStep = 'files'">Назад</q-btn>
                <q-btn color="primary" :loading="store.saving" @click="finishWizard">Создать заявление</q-btn>
              </q-stepper-navigation>
            </q-step>
          </q-stepper>
        </q-card-section>
      </q-card>
    </q-dialog>
  </AppPage>
</template>

<style scoped>
.admissions-foundation-note,
.admissions-foundation-warning {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
  background: #eff6ff;
  color: #1e3a8a;
}

.admissions-foundation-warning {
  align-items: flex-start;
  background: #fff7ed;
  color: #9a3412;
}

.admissions-foundation-workspace {
  display: grid;
  gap: 0;
  align-items: start;
  width: 100%;
  max-width: 100%;
  overflow-x: hidden;
}

.admissions-foundation-main,
.admissions-foundation-side {
  min-width: 0;
  max-width: 100%;
}

.admissions-foundation-main {
  padding-right: 10px;
}

.admissions-foundation-side {
  padding-left: 10px;
}

.admissions-foundation-filter-chips,
.admissions-foundation-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.admissions-foundation-row-link {
  border: 0;
  padding: 0;
  background: transparent;
  color: #2563eb;
  font: inherit;
  font-weight: 700;
  cursor: pointer;
}

.admissions-foundation-person-cell {
  display: grid;
  gap: 2px;
}

.admissions-foundation-person-cell small {
  color: #64748b;
  overflow-wrap: anywhere;
}

.admissions-foundation-tabs {
  margin: 12px 0;
  border-bottom: 1px solid #e2e8f0;
}

.admissions-foundation-panels {
  background: transparent;
}

.admissions-foundation-section {
  display: grid;
  gap: 10px;
  margin-bottom: 18px;
}

.admissions-foundation-section-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}

.admissions-foundation-section h3 {
  margin: 0;
  color: #0f172a;
  font-size: 15px;
  font-weight: 800;
}

.admissions-foundation-subsection {
  display: grid;
  gap: 10px;
  margin-top: 4px;
  border-top: 1px solid #e2e8f0;
  padding-top: 12px;
}

.admissions-foundation-section dl {
  display: grid;
  gap: 8px;
  margin: 0;
}

.admissions-foundation-section dl div {
  display: grid;
  grid-template-columns: minmax(130px, 0.8fr) minmax(0, 1.2fr);
  gap: 10px;
}

.admissions-foundation-section dt {
  color: #64748b;
  font-size: 12px;
}

.admissions-foundation-section dd {
  min-width: 0;
  margin: 0;
  color: #0f172a;
  overflow-wrap: anywhere;
}

.admissions-foundation-form-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
}

.admissions-foundation-mode {
  margin-bottom: 14px;
}

.admissions-foundation-duplicates {
  display: grid;
  gap: 8px;
}

.admissions-foundation-duplicate {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  border: 1px solid #dbeafe;
  border-radius: 8px;
  padding: 8px 10px;
}

.admissions-foundation-duplicate div {
  display: grid;
  gap: 2px;
}

.admissions-foundation-duplicate small {
  color: #64748b;
}

.admissions-foundation-checklist,
.admissions-foundation-blockers,
.admissions-foundation-history {
  display: grid;
  gap: 8px;
}

.admissions-foundation-check,
.admissions-foundation-history-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 8px 10px;
}

.admissions-foundation-history-item {
  justify-content: flex-start;
}

.admissions-foundation-history-item div {
  display: grid;
  gap: 2px;
}

.admissions-foundation-history-item span {
  color: #64748b;
  font-size: 12px;
}

.admissions-foundation-check.is-ready {
  background: #f0fdf4;
  border-color: #bbf7d0;
}

.admissions-foundation-check.is-pending {
  background: #fff7ed;
  border-color: #fed7aa;
}

.admissions-foundation-wizard {
  display: grid;
  grid-template-rows: auto auto 1fr;
}

.admissions-foundation-wizard h2 {
  margin: 0 0 4px;
  font-size: 22px;
  font-weight: 800;
}

.admissions-foundation-wizard p {
  max-width: 900px;
  margin: 0;
  color: #64748b;
}

.admissions-foundation-stepper {
  max-width: 1100px;
  margin: 0 auto;
}

.admissions-foundation-stepper :deep(.q-stepper__tab) {
  min-width: 0;
}

.admissions-foundation-stepper :deep(.q-stepper__dot) {
  transition: background-color 0.18s ease, color 0.18s ease;
}

:deep(.admissions-foundation-row--selected) {
  background: #eef6ff;
}

@media (max-width: 1180px) {
  .admissions-foundation-workspace {
    grid-template-columns: 1fr;
    gap: 16px;
  }

  .admissions-foundation-main,
  .admissions-foundation-side {
    padding: 0;
  }
}

@media (max-width: 720px) {
  .admissions-foundation-form-grid,
  .admissions-foundation-section dl div {
    grid-template-columns: 1fr;
  }
}
</style>
