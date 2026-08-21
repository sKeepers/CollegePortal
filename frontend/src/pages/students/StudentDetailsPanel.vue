<script setup>
import { computed, reactive, ref, watch } from 'vue'
import AppEmptyState from '../../components/ui/AppEmptyState.vue'
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'
import PersonPhotoManager from '../../components/person/PersonPhotoManager.vue'
import WorkspacePanel from '../../components/workspace/WorkspacePanel.vue'
import PersonAccountActions from '../../components/identity/PersonAccountActions.vue'
import { formatPhone } from '../../utils/phone'

const props = defineProps({
  student: { type: Object, default: null },
  documents: { type: Object, default: null },
  documentsLoading: { type: Boolean, default: false },
  canEditDocuments: { type: Boolean, default: false },
  savingDocument: { type: Boolean, default: false },
  statusLabels: { type: Object, required: true },
  statusTones: { type: Object, required: true },
  attendanceSummary: {
    type: Object,
    default: () => ({ total: 0, present: 0, absent: 0, late: 0, excused: 0 }),
  },
  gradeSummary: {
    type: Object,
    default: () => ({ total: 0, average: null, latest: [] }),
  },
  loading: { type: Boolean, default: false },
})

const emit = defineEmits(['save-education-document'])

const activeTab = ref('common')

const completeness = computed(() => props.student?.card_completeness || props.documents?.completeness || null)
const missingReasons = computed(() => completeness.value?.blocking_reasons || [])
const identityDocuments = computed(() => props.documents?.identity_documents || [])
const educationDocuments = computed(() => props.documents?.education_documents || [])
const educationFormVisible = ref(false)
const educationForm = reactive({
  series: '',
  number: '',
  issue_date: '',
  document_organization: '',
  graduation_year: '',
})

function submitEducationDocument() {
  emit('save-education-document', {
    series: educationForm.series?.trim() || null,
    number: educationForm.number?.trim() || null,
    issue_date: educationForm.issue_date || null,
    document_organization: educationForm.document_organization?.trim() || null,
    graduation_year: educationForm.graduation_year ? Number(educationForm.graduation_year) : null,
  })
  educationFormVisible.value = false
  Object.assign(educationForm, { series: '', number: '', issue_date: '', document_organization: '', graduation_year: '' })
}

const programName = computed(() => props.student?.group?.education_program?.name || '—')
const specialtyName = computed(() => (
  props.student?.group?.specialty
  || props.student?.group?.education_program?.specialty?.name
  || '—'
))
const averageGrade = computed(() => (
  props.gradeSummary.average === null || props.gradeSummary.average === undefined
    ? '—'
    : props.gradeSummary.average.toFixed(2)
))
const studentName = computed(() => fullName(props.student) || 'Студент')
const studentSubtitle = computed(() => [
  props.student?.group?.name || 'Группа не указана',
  programName.value,
  specialtyName.value,
])
const studentMetrics = computed(() => [
  { label: 'Группа', value: props.student?.group?.name || '—', to: props.student?.group_id ? { path: '/groups', query: { selected: props.student.group_id } } : null },
  { label: 'Курс', value: props.student?.course || props.student?.group?.course || '—' },
  { label: 'Статус', value: props.statusLabels[props.student?.status] || props.student?.status || '—' },
  { label: 'Средний балл', value: averageGrade.value },
])
const studentActions = computed(() => [
  { label: 'Расписание', to: props.student?.group_id ? { path: '/schedule', query: { group: props.student.group_id } } : '/schedule' },
  { label: 'Журнал', to: props.student?.group_id ? { path: '/journal', query: { group: props.student.group_id } } : '/journal' },
  { label: 'Учебный план', to: '/curricula' },
  { label: 'Цифровой пропуск', to: { path: '/identity/digital-passes', query: { owner: 'student', selected: props.student?.id } } },
  { label: 'История проходов', to: { path: '/access/reports', query: { type: 'student', q: studentName.value } } },
  { label: 'Документы', to: { path: '/students', query: { selected: props.student?.id, tab: 'documents' } } },
  { label: 'Выпуск', to: { path: '/graduation', query: { student: props.student?.id } } },
])

watch(() => props.student?.id, () => { activeTab.value = 'common' })

function fullName(student) {
  return [student?.last_name, student?.first_name, student?.middle_name].filter(Boolean).join(' ')
}
function updatePhoto(payload) {
  if (props.student) {
    props.student.photo_url = payload.photo_url
    props.student.photo_path = payload.photo_path
  }
}
function removePhoto() {
  if (props.student) {
    props.student.photo_url = null
    props.student.photo_path = null
  }
}
</script>

<template>
  <AppEmptyState
    v-if="!student"
    title="Студент не выбран"
    description="Выберите строку в таблице, чтобы открыть карточку."
  />

  <WorkspacePanel
    v-else
    class="student-details-card"
    :title="studentName"
    :subtitle="studentSubtitle"
    :metrics="studentMetrics"
    :actions="studentActions"
  >
    <template #photo>
      <PersonPhotoManager type="students" :person="student" compact @updated="updatePhoto" @removed="removePhoto" />
    </template>

    <template #status>
      <AppStatusBadge :label="statusLabels[student.status] || student.status" :tone="statusTones[student.status] || 'neutral'" />
      <PersonAccountActions profile-type="student" :profile-id="student.id" :has-account="Boolean(student.user_id)" />
    </template>

    <div class="student-details">
      <q-linear-progress v-if="loading" indeterminate color="primary" rounded />

      <q-tabs v-model="activeTab" dense align="left" class="student-details__tabs" active-color="primary" indicator-color="primary" mobile-arrows outside-arrows shrink>
        <q-tab name="common" label="Общие" />
        <q-tab name="grades" label="Успеваемость" />
        <q-tab name="attendance" label="Посещаемость" />
        <q-tab name="documents" label="Документы" />
        <q-tab name="history" label="История" />
      </q-tabs>

      <q-tab-panels v-model="activeTab" animated class="student-details__panels">
        <q-tab-panel name="common">
          <section class="student-details__section">
            <h3>Основное</h3>
            <dl class="student-details__list">
              <div><dt>Дата рождения</dt><dd>{{ student.birth_date || '—' }}</dd></div>
              <div><dt>Статус</dt><dd>{{ statusLabels[student.status] || student.status }}</dd></div>
              <div><dt>Дата зачисления</dt><dd>{{ student.enrollment_date || '—' }}</dd></div>
              <div><dt>Приказ о зачислении</dt><dd>{{ student.enrollment_order_number || '—' }}<template v-if="student.enrollment_order_date"> от {{ student.enrollment_order_date }}</template></dd></div>
              <div><dt>Личное дело</dt><dd>{{ student.personal_file_number ? `${student.personal_file_letter || ''} ${student.personal_file_number}`.trim() : '—' }}</dd></div>
            </dl>
          </section>

          <section class="student-details__section">
            <h3>Контакты</h3>
            <dl class="student-details__list">
              <div><dt>Телефон</dt><dd>{{ formatPhone(student.phone, "—") }}</dd></div>
              <div><dt>Email</dt><dd>{{ student.email || '—' }}</dd></div>
              <div><dt>Адрес</dt><dd>{{ student.address || '—' }}</dd></div>
            </dl>
          </section>

          <section class="student-details__section">
            <h3>Обучение</h3>
            <dl class="student-details__list">
              <div>
                <dt>Группа</dt>
                <dd>
                  <q-btn v-if="student.group_id" flat dense no-caps class="entity-link-action" :to="{ path: '/groups', query: { selected: student.group_id } }">
                    {{ student.group?.name || 'Открыть группу' }}
                  </q-btn>
                  <span v-else>—</span>
                </dd>
              </div>
              <div><dt>Программа</dt><dd>{{ programName }}</dd></div>
              <div><dt>Специальность</dt><dd>{{ specialtyName }}</dd></div>
              <div><dt>Курс</dt><dd>{{ student.course || student.group?.course || '—' }}</dd></div>
              <div><dt>Форма обучения</dt><dd>{{ student.education_form || '—' }}</dd></div>
            </dl>
          </section>
        </q-tab-panel>

        <q-tab-panel name="grades">
          <section class="student-details__section">
            <h3>Оценки</h3>
            <div class="student-details__summary-grid">
              <div><span>Всего оценок</span><strong>{{ gradeSummary.total }}</strong></div>
              <div><span>Средний балл</span><strong>{{ averageGrade }}</strong></div>
            </div>
            <ul v-if="gradeSummary.latest?.length" class="student-details__timeline">
              <li v-for="grade in gradeSummary.latest" :key="grade.id"><strong>{{ grade.grade }}</strong><span>{{ grade.grade_type || 'Оценка' }}</span></li>
            </ul>
            <p v-else class="student-details__muted">Оценок по студенту пока нет.</p>
          </section>
        </q-tab-panel>

        <q-tab-panel name="attendance">
          <section class="student-details__section">
            <h3>Посещаемость</h3>
            <div class="student-details__summary-grid">
              <div><span>Всего отметок</span><strong>{{ attendanceSummary.total }}</strong></div>
              <div><span>Присутствовал</span><strong>{{ attendanceSummary.present }}</strong></div>
              <div><span>Опоздал</span><strong>{{ attendanceSummary.late }}</strong></div>
              <div><span>Отсутствовал</span><strong>{{ attendanceSummary.absent }}</strong></div>
            </div>
          </section>
        </q-tab-panel>

        <q-tab-panel name="documents">
          <section class="student-details__section">
            <h3>Полнота карточки</h3>
            <q-linear-progress v-if="documentsLoading" indeterminate color="primary" rounded />
            <AppStatusBadge
              v-if="completeness"
              :label="completeness.complete ? 'Карточка полная' : 'Карточка неполная'"
              :tone="completeness.complete ? 'success' : 'warning'"
            />
            <ul v-if="missingReasons.length" class="student-card-flag__list">
              <li v-for="reason in missingReasons" :key="reason">{{ reason }}</li>
            </ul>
            <p v-else-if="completeness" class="student-details__muted">
              Паспорт, документ об образовании и СНИЛС на месте.
            </p>
          </section>

          <section class="student-details__section">
            <h3>Документ, удостоверяющий личность</h3>
            <dl v-for="document in identityDocuments" :key="document.id" class="student-details__list">
              <div><dt>Документ</dt><dd>{{ document.document_type?.name || 'Документ личности' }}</dd></div>
              <div><dt>Серия и номер</dt><dd>{{ [document.series || document.series_masked, document.number || document.number_masked].filter(Boolean).join(' ') || '—' }}</dd></div>
              <div><dt>Дата выдачи</dt><dd>{{ document.issue_date || '—' }}</dd></div>
              <div><dt>Кем выдан</dt><dd>{{ document.issued_by || '—' }}</dd></div>
              <div><dt>Происхождение</dt><dd>{{ document.applicant_id ? 'Приёмная комиссия' : 'Заведён в контингенте' }}</dd></div>
            </dl>
            <p v-if="!identityDocuments.length" class="student-details__muted">
              Документа нет. Реквизиты паспорта из формы студента сохраняются как документ человека.
            </p>
          </section>

          <section class="student-details__section">
            <h3>Документ об образовании</h3>
            <dl v-for="document in educationDocuments" :key="document.id" class="student-details__list">
              <div><dt>Документ</dt><dd>{{ document.document_type?.name || 'Документ об образовании' }}</dd></div>
              <div><dt>Серия и номер</dt><dd>{{ [document.series || document.series_masked, document.number || document.number_masked].filter(Boolean).join(' ') || '—' }}</dd></div>
              <div><dt>Дата выдачи</dt><dd>{{ document.issue_date || '—' }}</dd></div>
              <div><dt>Учебное заведение</dt><dd>{{ document.document_organization || '—' }}</dd></div>
              <div><dt>Год окончания</dt><dd>{{ document.graduation_year || '—' }}</dd></div>
            </dl>
            <p v-if="!educationDocuments.length" class="student-details__muted">Документа об образовании нет.</p>

            <div v-if="canEditDocuments" class="student-documents__actions">
              <q-btn
                flat
                dense
                no-caps
                :label="educationFormVisible ? 'Отмена' : 'Добавить документ об образовании'"
                @click="educationFormVisible = !educationFormVisible"
              />
            </div>

            <form v-if="educationFormVisible" class="student-form__grid" @submit.prevent="submitEducationDocument">
              <q-input v-model="educationForm.series" dense outlined label="Серия" />
              <q-input v-model="educationForm.number" dense outlined label="Номер" />
              <q-input v-model="educationForm.issue_date" dense outlined type="date" label="Дата выдачи" stack-label />
              <q-input v-model="educationForm.document_organization" dense outlined label="Учебное заведение" />
              <q-input v-model="educationForm.graduation_year" dense outlined type="number" label="Год окончания" />
              <q-btn color="primary" type="submit" label="Сохранить документ" :loading="savingDocument" />
            </form>
          </section>

          <section class="student-details__section">
            <h3>СНИЛС</h3>
            <dl class="student-details__list">
              <div><dt>СНИЛС</dt><dd>{{ student.snils || completeness?.snils?.masked || '—' }}</dd></div>
            </dl>
          </section>
        </q-tab-panel>

        <q-tab-panel name="history">
          <section class="student-details__section"><h3>История</h3><p class="student-details__muted">Здесь будет журнал изменений карточки, переводов, статусов и интеграционных событий.</p></section>
        </q-tab-panel>
      </q-tab-panels>
    </div>
  </WorkspacePanel>
</template>
