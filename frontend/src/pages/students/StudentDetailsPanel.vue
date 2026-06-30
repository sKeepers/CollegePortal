<script setup>
import { computed, ref, watch } from 'vue'
import AppCard from '../../components/ui/AppCard.vue'
import AppEmptyState from '../../components/ui/AppEmptyState.vue'
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'

const props = defineProps({
  student: {
    type: Object,
    default: null,
  },
  statusLabels: {
    type: Object,
    required: true,
  },
  statusTones: {
    type: Object,
    required: true,
  },
  attendanceSummary: {
    type: Object,
    default: () => ({
      total: 0,
      present: 0,
      absent: 0,
      late: 0,
      excused: 0,
    }),
  },
  gradeSummary: {
    type: Object,
    default: () => ({
      total: 0,
      average: null,
      latest: [],
    }),
  },
  loading: {
    type: Boolean,
    default: false,
  },
})

const activeTab = ref('common')

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

watch(
  () => props.student?.id,
  () => {
    activeTab.value = 'common'
  },
)

function fullName(student) {
  return [student?.last_name, student?.first_name, student?.middle_name].filter(Boolean).join(' ')
}
</script>

<template>
  <AppCard class="student-details-card">
    <AppEmptyState
      v-if="!student"
      title="Студент не выбран"
      description="Выберите строку в таблице, чтобы открыть карточку."
    />

    <div v-else class="student-details">
      <div class="student-details__hero">
        <div class="student-details__title-row">
          <h2>{{ fullName(student) }}</h2>
          <AppStatusBadge
            :label="statusLabels[student.status] || student.status"
            :tone="statusTones[student.status] || 'neutral'"
          />
        </div>
        <div class="student-details__subtitle">
          <span>{{ student.group?.name || 'Группа не указана' }}</span>
          <span>{{ programName }}</span>
          <span>{{ specialtyName }}</span>
        </div>
      </div>

      <div class="student-details__metrics">
        <div>
          <span>Группа</span>
          <strong>{{ student.group?.name || '—' }}</strong>
          <q-btn
            v-if="student.group_id"
            flat
            dense
            no-caps
            class="entity-link-action entity-link-action--inline"
            :to="{ path: '/groups', query: { selected: student.group_id } }"
          >
            Открыть группу
          </q-btn>
        </div>
        <div>
          <span>Зачисление</span>
          <strong>{{ student.enrollment_date || '—' }}</strong>
        </div>
        <div>
          <span>Посещаемость</span>
          <strong>{{ attendanceSummary.present }}/{{ attendanceSummary.total }}</strong>
        </div>
        <div>
          <span>Средний балл</span>
          <strong>{{ averageGrade }}</strong>
        </div>
      </div>

      <q-linear-progress v-if="loading" indeterminate color="primary" rounded />

      <q-tabs
        v-model="activeTab"
        dense
        align="left"
        class="student-details__tabs"
        active-color="primary"
        indicator-color="primary"
        mobile-arrows
        outside-arrows
        shrink
      >
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
              <div>
                <dt>Дата рождения</dt>
                <dd>{{ student.birth_date || '—' }}</dd>
              </div>
              <div>
                <dt>Статус</dt>
                <dd>{{ statusLabels[student.status] || student.status }}</dd>
              </div>
              <div>
                <dt>Дата зачисления</dt>
                <dd>{{ student.enrollment_date || '—' }}</dd>
              </div>
            </dl>
          </section>

          <section class="student-details__section">
            <h3>Контакты</h3>
            <dl class="student-details__list">
              <div>
                <dt>Телефон</dt>
                <dd>{{ student.phone || '—' }}</dd>
              </div>
              <div>
                <dt>Email</dt>
                <dd>{{ student.email || '—' }}</dd>
              </div>
            </dl>
          </section>

          <section class="student-details__section">
            <h3>Обучение</h3>
            <dl class="student-details__list">
              <div>
                <dt>Группа</dt>
                <dd>
                  <q-btn
                    v-if="student.group_id"
                    flat
                    dense
                    no-caps
                    class="entity-link-action"
                    :to="{ path: '/groups', query: { selected: student.group_id } }"
                  >
                    {{ student.group?.name || 'Открыть группу' }}
                  </q-btn>
                  <span v-else>—</span>
                </dd>
              </div>
              <div>
                <dt>Программа</dt>
                <dd>{{ programName }}</dd>
              </div>
              <div>
                <dt>Специальность</dt>
                <dd>{{ specialtyName }}</dd>
              </div>
            </dl>
          </section>
        </q-tab-panel>

        <q-tab-panel name="grades">
          <section class="student-details__section">
            <h3>Оценки</h3>
            <div class="student-details__summary-grid">
              <div>
                <span>Всего оценок</span>
                <strong>{{ gradeSummary.total }}</strong>
              </div>
              <div>
                <span>Средний балл</span>
                <strong>{{ averageGrade }}</strong>
              </div>
            </div>
            <ul v-if="gradeSummary.latest?.length" class="student-details__timeline">
              <li v-for="grade in gradeSummary.latest" :key="grade.id">
                <strong>{{ grade.grade }}</strong>
                <span>{{ grade.grade_type || 'Оценка' }}</span>
              </li>
            </ul>
            <p v-else class="student-details__muted">Оценок по студенту пока нет.</p>
          </section>
        </q-tab-panel>

        <q-tab-panel name="attendance">
          <section class="student-details__section">
            <h3>Посещаемость</h3>
            <div class="student-details__summary-grid">
              <div>
                <span>Всего отметок</span>
                <strong>{{ attendanceSummary.total }}</strong>
              </div>
              <div>
                <span>Присутствовал</span>
                <strong>{{ attendanceSummary.present }}</strong>
              </div>
              <div>
                <span>Опоздал</span>
                <strong>{{ attendanceSummary.late }}</strong>
              </div>
              <div>
                <span>Отсутствовал</span>
                <strong>{{ attendanceSummary.absent }}</strong>
              </div>
            </div>
          </section>
        </q-tab-panel>

        <q-tab-panel name="documents">
          <section class="student-details__section">
            <h3>Документы / ФРДО</h3>
            <p class="student-details__muted">
              Раздел зарезервирован для СНИЛС, гражданства, документа об образовании и будущей выгрузки ФРДО.
            </p>
          </section>
        </q-tab-panel>

        <q-tab-panel name="history">
          <section class="student-details__section">
            <h3>История</h3>
            <p class="student-details__muted">
              Здесь будет журнал изменений карточки, переводов, статусов и интеграционных событий.
            </p>
          </section>
        </q-tab-panel>
      </q-tab-panels>
    </div>
  </AppCard>
</template>
