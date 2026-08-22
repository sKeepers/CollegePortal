<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { formatPhone } from '../../utils/phone'
import { BookOpen, CheckCircle2, ClipboardList, Download, FileText, GraduationCap, History, Mail, Phone, Upload, UserPlus, XCircle } from '@lucide/vue'
import AppEmptyState from '../../components/ui/AppEmptyState.vue'
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'
import WorkspacePanel from '../../components/workspace/WorkspacePanel.vue'
import {
  applicantName,
  documentsCompleteness,
  documentsCompletenessLabel,
  educationBaseLabel,
  formatDate,
  formatDateTime,
  programLabel,
  statusLabel,
  statusTone,
} from '../../stores/admissions'

const props = defineProps({
  application: {
    type: Object,
    default: null,
  },
  documents: {
    type: Array,
    default: () => [],
  },
  events: {
    type: Array,
    default: () => [],
  },
  groupOptions: {
    type: Array,
    default: () => [],
  },
  saving: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['enroll', 'update-document', 'receive-document', 'upload-document', 'verify-document', 'reject-document', 'download-file', 'delete-file'])

const activeTab = ref('overview')
const enrollDialogVisible = ref(false)
const enrollForm = reactive({
  group_id: '',
  enrollment_date: new Date().toISOString().slice(0, 10),
})

const fullName = computed(() => applicantName(props.application))
const selectedProgram = computed(() => props.application?.education_program || null)
const selectedSpecialty = computed(() => selectedProgram.value?.specialty || null)
const completeness = computed(() => documentsCompleteness(props.application))
const canEnroll = computed(() => props.application?.status !== 'enrolled' && completeness.value === 'complete')
const completenessTone = computed(() => {
  if (completeness.value === 'complete') return 'success'
  if (completeness.value === 'no_documents') return 'danger'
  return 'warning'
})

const specialtyText = computed(() => {
  const specialty = selectedSpecialty.value
  return [specialty?.code, specialty?.name].filter(Boolean).join(' · ') || '—'
})

const scheduleLink = computed(() => ({ path: '/schedule', query: { program: props.application?.education_program_id } }))
const journalLink = computed(() => ({ path: '/journal', query: { program: props.application?.education_program_id } }))
const studentsLink = computed(() => ({ path: '/students', query: { search: fullName.value } }))

const admissionSubtitle = computed(() => [
  programLabel(selectedProgram.value) || 'Программа не указана',
  specialtyText.value,
])
const documentsReceivedCount = computed(() => Number(props.application?.documents_count ?? props.documents.filter((document) => document.is_received).length ?? 0))
const documentsRequiredCount = computed(() => Number(props.application?.required_documents_count ?? props.documents.length ?? 0))
const documentsProvidedLabel = computed(() => props.application?.documents_provided ? 'Да' : 'Нет')
const completenessText = computed(() => `${documentsReceivedCount.value}/${documentsRequiredCount.value}`)
const completenessStatusLabel = computed(() => {
  if (completeness.value === 'complete') return 'Полный'
  if (completeness.value === 'no_documents') return 'Без документов'
  return 'Неполный'
})

const admissionMetrics = computed(() => [
  { label: 'Дата подачи', value: formatDate(props.application?.submitted_at) },
  { label: 'База', value: educationBaseLabel(props.application?.education_base) },
  { label: 'Комплектность', value: completenessText.value },
  { label: 'Получение подтверждено', value: documentsProvidedLabel.value },
])
const admissionActions = computed(() => [
  { label: 'Документы', to: { path: `/admissions/${props.application?.id}`, query: { tab: 'documents' } } },
  { label: 'Зачислить', disabled: !canEnroll.value || props.saving },
  { label: 'Студент', to: studentsLink.value },
  { label: 'История', to: { path: `/admissions/${props.application?.id}`, query: { tab: 'history' } } },
])

watch(
  () => props.application?.id,
  () => {
    activeTab.value = 'overview'
    Object.assign(enrollForm, {
      group_id: '',
      enrollment_date: new Date().toISOString().slice(0, 10),
    })
  },
)

function openEnrollDialog() {
  enrollDialogVisible.value = true
}

function submitEnroll() {
  emit('enroll', { ...enrollForm })
  enrollDialogVisible.value = false
}

function documentTone(document) {
  const status = document?.status || (document?.is_received ? 'received' : 'missing')
  if (status === 'verified') return 'success'
  if (status === 'received' || status === 'under_review') return 'info'
  if (status === 'rejected') return 'danger'
  return 'warning'
}

function documentLabel(document) {
  const labels = {
    missing: 'Не получен',
    received: 'Получен',
    under_review: 'На проверке',
    verified: 'Подтвержден',
    rejected: 'Отклонен',
  }
  return labels[document?.status] || (document?.is_received ? 'Получен' : 'Не получен')
}

function fileAccept(document) {
  return (document?.allowed_extensions || ['pdf', 'jpg', 'jpeg', 'png', 'webp']).map((extension) => `.${extension}`).join(',')
}

function uploadFile(document, file) {
  if (file) {
    emit('upload-document', document, file)
  }
}

function rejectDocument(document) {
  const reason = window.prompt('Причина отклонения документа')
  if (reason) {
    emit('reject-document', document, reason)
  }
}

function toggleDocument(document) {
  emit('update-document', document, {
    is_received: !document.is_received,
    received_at: document.is_received ? null : new Date().toISOString().slice(0, 10),
    number: document.number || null,
    comment: document.comment || null,
  })
}

</script>

<template>
  <AppEmptyState
    v-if="!application"
    title="Заявление не выбрано"
    description="Выберите строку в таблице, чтобы открыть карточку абитуриента."
  />

  <WorkspacePanel
    v-else
    class="admission-details-card"
    :title="fullName"
    :subtitle="admissionSubtitle"
    :metrics="admissionMetrics"
    :actions="admissionActions"
  >
    <template #status>
      <AppStatusBadge :label="statusLabel(application.status)" :tone="statusTone(application.status)" />
      <AppStatusBadge :label="documentsCompletenessLabel(application)" :tone="completenessTone" />
    </template>

    <template #actions>
      <div class="workspace-panel__actions">
        <q-btn no-caps unelevated class="workspace-panel__action" @click="activeTab = 'documents'">Документы</q-btn>
        <q-btn no-caps unelevated class="workspace-panel__action" :disable="!canEnroll || saving" @click="openEnrollDialog">Зачислить</q-btn>
        <q-btn no-caps unelevated class="workspace-panel__action" :to="studentsLink">Студент</q-btn>
        <q-btn no-caps unelevated class="workspace-panel__action" @click="activeTab = 'history'">История</q-btn>
      </div>
    </template>

    <div class="admission-details">

      <q-tabs v-model="activeTab" dense no-caps outside-arrows mobile-arrows class="admission-details__tabs">
        <q-tab name="overview" label="Сведения" />
        <q-tab name="documents" label="Документы" />
        <q-tab name="history" label="История" />
        <q-tab name="actions" label="Действия" />
      </q-tabs>

      <q-tab-panels v-model="activeTab" animated class="admission-details__panels">
        <q-tab-panel name="overview">
          <section class="admission-details__section">
            <h3><ClipboardList :size="16" /> Основное</h3>
            <dl class="admission-details__list">
              <div>
                <dt>ФИО</dt>
                <dd>{{ fullName }}</dd>
              </div>
              <div>
                <dt>Специальность</dt>
                <dd>{{ specialtyText }}</dd>
              </div>
              <div>
                <dt>Программа</dt>
                <dd>{{ selectedProgram?.name || '—' }}</dd>
              </div>
              <div>
                <dt>Форма обучения</dt>
                <dd>{{ selectedProgram?.study_form || '—' }}</dd>
              </div>
              <div>
                <dt>Дата рождения</dt>
                <dd>{{ formatDate(application.birth_date) }}</dd>
              </div>
              <div>
                <dt>Получение подтверждено</dt>
                <dd>{{ documentsProvidedLabel }}</dd>
              </div>
              <div>
                <dt>Комплектность</dt>
                <dd>{{ completenessText }}</dd>
              </div>
              <div>
                <dt>Статус комплекта</dt>
                <dd>{{ completenessStatusLabel }}</dd>
              </div>
            </dl>
          </section>

          <section class="admission-details__section">
            <h3><Phone :size="16" /> Контакты</h3>
            <dl class="admission-details__list">
              <div>
                <dt>Телефон</dt>
                <dd>{{ formatPhone(application.phone, "—") }}</dd>
              </div>
              <div>
                <dt>Email</dt>
                <dd>{{ application.email || '—' }}</dd>
              </div>
            </dl>
          </section>

          <section class="admission-details__section">
            <h3><Mail :size="16" /> Комментарий</h3>
            <p class="admission-details__muted">{{ application.comment || 'Комментарий приемной комиссии пока не указан.' }}</p>
          </section>
        </q-tab-panel>

        <q-tab-panel name="documents">
          <section class="admission-details__section">
            <h3><FileText :size="16" /> Документы</h3>
            <div v-if="documents.length" class="admission-documents">
              <div v-for="document in documents" :key="document.id || document.type" class="admission-documents__item admission-documents__item--registry">
                <div class="admission-documents__main">
                  <div class="admission-documents__title">
                    <strong>{{ document.title }}</strong>
                    <AppStatusBadge :label="document.required ? 'Обязательный' : 'Дополнительный'" tone="neutral" />
                    <AppStatusBadge :label="documentLabel(document)" :tone="documentTone(document)" />
                  </div>
                  <span>
                    Получен: {{ formatDate(document.received_at) }}
                    <template v-if="document.verified_at"> · Проверен: {{ formatDateTime(document.verified_at) }}</template>
                    <template v-if="document.received_by"> · Принял: {{ document.received_by }}</template>
                    <template v-if="document.verified_by"> · Проверил: {{ document.verified_by }}</template>
                    · Файлов: {{ document.files_count || document.files?.length || 0 }}
                  </span>
                  <span v-if="document.rejection_reason" class="admission-documents__reject">Причина: {{ document.rejection_reason }}</span>
                  <span v-if="document.comment">Комментарий: {{ document.comment }}</span>
                  <div v-if="document.files?.length" class="admission-documents__files">
                    <button v-for="file in document.files" :key="file.id" type="button" class="admission-documents__file" @click="emit('download-file', document, file)">
                      <Download :size="13" /> {{ file.original_name }}
                    </button>
                  </div>
                </div>
                <div class="admission-documents__actions">
                  <q-file
                    dense
                    outlined
                    :disable="saving"
                    :accept="fileAccept(document)"
                    label="Загрузить"
                    class="admission-documents__upload"
                    @update:model-value="(file) => uploadFile(document, file)"
                  >
                    <template #prepend><Upload :size="14" /></template>
                  </q-file>
                  <q-btn flat dense no-caps :disable="saving" @click="emit('receive-document', document)">Получен</q-btn>
                  <q-btn flat dense no-caps :disable="saving || document.status === 'verified'" @click="emit('verify-document', document)">
                    <CheckCircle2 :size="14" /> Подтвердить
                  </q-btn>
                  <q-btn flat dense no-caps color="negative" :disable="saving" @click="rejectDocument(document)">
                    <XCircle :size="14" /> Отклонить
                  </q-btn>
                </div>
              </div>
            </div>
            <p v-else class="admission-details__muted">Документы пока не заведены.</p>
          </section>
        </q-tab-panel>

        <q-tab-panel name="history">
          <section class="admission-details__section">
            <h3><History :size="16" /> События</h3>
            <div v-if="events.length" class="admission-events">
              <div v-for="event in events" :key="event.id" class="admission-events__item">
                <time>{{ formatDateTime(event.created_at) }}</time>
                <strong>{{ event.title }}</strong>
                <span>{{ event.description || '—' }}</span>
              </div>
            </div>
            <p v-else class="admission-details__muted">История заявления пока пуста.</p>
          </section>
        </q-tab-panel>

        <q-tab-panel name="actions">
          <section class="admission-details__section">
            <h3><CheckCircle2 :size="16" /> Быстрые действия</h3>
            <div class="admission-details__actions">
              <q-btn flat no-caps class="entity-link-action" :to="scheduleLink">
                <BookOpen :size="15" /> Открыть расписание по программе
              </q-btn>
              <q-btn flat no-caps class="entity-link-action" :to="journalLink">
                <ClipboardList :size="15" /> Открыть журнал
              </q-btn>
              <q-btn flat no-caps class="entity-link-action" :to="studentsLink">
                <GraduationCap :size="15" /> Найти студента
              </q-btn>
              <q-btn color="primary" no-caps :disable="!canEnroll || saving" @click="openEnrollDialog">
                <UserPlus :size="16" /> Зачислить в студенты
              </q-btn>
            </div>
            <p v-if="!canEnroll && application.status !== 'enrolled'" class="admission-details__muted">
              Зачисление доступно после получения полного комплекта документов.
            </p>
            <p v-if="application.status === 'enrolled'" class="admission-details__muted">
              Абитуриент уже зачислен. Проверьте карточку студента через быстрый поиск.
            </p>
          </section>
        </q-tab-panel>
      </q-tab-panels>

      <q-dialog v-model="enrollDialogVisible" persistent>
        <q-card class="admission-enroll-dialog">
          <q-card-section>
            <div class="text-h6">Зачислить абитуриента</div>
            <p class="admission-details__muted">Будет создана карточка студента на основе заявления.</p>
          </q-card-section>
          <q-card-section class="admission-enroll-dialog__body">
            <q-select
              v-model="enrollForm.group_id"
              dense
              outlined
              emit-value
              map-options
              label="Группа"
              :options="groupOptions"
              required
            />
            <q-input v-model="enrollForm.enrollment_date" dense outlined type="date" label="Дата зачисления" required />
          </q-card-section>
          <q-card-actions align="right">
            <q-btn flat label="Отмена" :disable="saving" @click="enrollDialogVisible = false" />
            <q-btn color="primary" label="Зачислить" :loading="saving" :disable="!enrollForm.group_id" @click="submitEnroll" />
          </q-card-actions>
        </q-card>
      </q-dialog>
    </div>
  </WorkspacePanel>
</template>

<style scoped>
.admission-documents {
  display: grid;
  gap: 10px;
}

.admission-documents__item--registry {
  align-items: flex-start;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  display: grid;
  gap: 12px;
  grid-template-columns: minmax(0, 1fr);
  padding: 12px;
}

.admission-documents__main {
  display: grid;
  gap: 6px;
  min-width: 0;
}

.admission-documents__title,
.admission-documents__actions,
.admission-documents__files {
  align-items: center;
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.admission-documents__actions {
  justify-content: flex-start;
}

.admission-documents__upload {
  max-width: 170px;
  min-width: 150px;
}

.admission-documents__file {
  align-items: center;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  color: #0f172a;
  cursor: pointer;
  display: inline-flex;
  font-size: 12px;
  gap: 5px;
  max-width: 100%;
  padding: 5px 8px;
}

.admission-documents__reject {
  color: #b91c1c;
}
</style>
