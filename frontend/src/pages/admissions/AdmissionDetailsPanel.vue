<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { BookOpen, CheckCircle2, ClipboardList, FileText, GraduationCap, History, Mail, Phone, UserPlus } from '@lucide/vue'
import AppCard from '../../components/ui/AppCard.vue'
import AppEmptyState from '../../components/ui/AppEmptyState.vue'
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'
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

const emit = defineEmits(['enroll', 'update-document'])

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

const specialtyText = computed(() => {
  const specialty = selectedSpecialty.value
  return [specialty?.code, specialty?.name].filter(Boolean).join(' · ') || '—'
})

const scheduleLink = computed(() => ({ path: '/schedule', query: { program: props.application?.education_program_id } }))
const journalLink = computed(() => ({ path: '/journal', query: { program: props.application?.education_program_id } }))
const studentsLink = computed(() => ({ path: '/students', query: { search: fullName.value } }))

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
  return document?.is_received ? 'success' : 'warning'
}

function documentLabel(document) {
  return document?.is_received ? 'Получен' : 'Ожидается'
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
  <AppCard class="admission-details-card">
    <AppEmptyState
      v-if="!application"
      title="Заявление не выбрано"
      description="Выберите строку в таблице, чтобы открыть карточку абитуриента."
    />

    <div v-else class="admission-details">
      <div class="admission-details__hero">
        <div class="admission-details__title-row">
          <div class="admission-details__title-block">
            <h2>{{ fullName }}</h2>
            <div class="admission-details__badges">
              <AppStatusBadge :label="statusLabel(application.status)" :tone="statusTone(application.status)" />
              <AppStatusBadge :label="documentsCompletenessLabel(application)" :tone="completeness === 'complete' ? 'success' : 'warning'" />
            </div>
          </div>
        </div>
        <p class="admission-details__program">{{ programLabel(selectedProgram) || 'Программа не указана' }}</p>
      </div>

      <div class="admission-details__metrics">
        <div>
          <span>Дата подачи</span>
          <strong>{{ formatDate(application.submitted_at) }}</strong>
        </div>
        <div>
          <span>База</span>
          <strong>{{ educationBaseLabel(application.education_base) }}</strong>
        </div>
        <div>
          <span>Документы</span>
          <strong>{{ documents.filter((document) => document.is_received).length }}/{{ documents.length }}</strong>
        </div>
        <div>
          <span>Событий</span>
          <strong>{{ events.length }}</strong>
        </div>
      </div>

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
            </dl>
          </section>

          <section class="admission-details__section">
            <h3><Phone :size="16" /> Контакты</h3>
            <dl class="admission-details__list">
              <div>
                <dt>Телефон</dt>
                <dd>{{ application.phone || '—' }}</dd>
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
              <div v-for="document in documents" :key="document.id || document.type" class="admission-documents__item">
                <div>
                  <strong>{{ document.title }}</strong>
                  <span>
                    <template v-if="document.received_at">Дата: {{ formatDate(document.received_at) }}</template>
                    <template v-if="document.number"> · № {{ document.number }}</template>
                    <template v-if="document.comment"> · {{ document.comment }}</template>
                  </span>
                </div>
                <div class="admission-documents__actions">
                  <AppStatusBadge :label="documentLabel(document)" :tone="documentTone(document)" />
                  <q-btn flat dense no-caps :disable="saving" @click="toggleDocument(document)">
                    {{ document.is_received ? 'Снять отметку' : 'Получен' }}
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
  </AppCard>
</template>
