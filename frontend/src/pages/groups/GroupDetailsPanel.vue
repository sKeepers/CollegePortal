<script setup>
import { computed } from 'vue'
import AppCard from '../../components/ui/AppCard.vue'
import AppEmptyState from '../../components/ui/AppEmptyState.vue'
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'

const props = defineProps({
  group: {
    type: Object,
    default: null,
  },
})

function teacherName(teacher) {
  return [teacher?.last_name, teacher?.first_name, teacher?.middle_name].filter(Boolean).join(' ')
}

function programLabel(program) {
  return [
    program?.name,
    program?.specialty?.code,
    program?.year_start,
    program?.study_form,
  ].filter(Boolean).join(' · ')
}

const curatorText = computed(() => teacherName(props.group?.curator) || '—')
const specialtyText = computed(() => props.group?.specialty || 'Специальность не указана')
const studyFormText = computed(() => props.group?.education_program?.study_form || 'Форма не указана')
const programText = computed(() => programLabel(props.group?.education_program) || 'Образовательная программа не указана')
</script>

<template>
  <AppCard class="group-details-card">
    <AppEmptyState
      v-if="!group"
      title="Группа не выбрана"
      description="Выберите строку в таблице, чтобы открыть карточку группы."
    />

    <div v-else class="group-details">
      <div class="group-details__hero">
        <div class="group-details__title-row">
          <div class="group-details__title-block">
            <h2>{{ group.name }}</h2>
            <div class="group-details__badges">
              <AppStatusBadge :label="`${group.course || '—'} курс`" tone="info" />
              <AppStatusBadge :label="studyFormText" tone="neutral" />
            </div>
          </div>
        </div>
        <div class="group-details__subtitle">
          <span class="group-details__subtitle-item">{{ specialtyText }}</span>
          <span class="group-details__subtitle-item">{{ programText }}</span>
        </div>
      </div>

      <div class="group-details__metrics">
        <div>
          <span>Студентов</span>
          <q-btn
            flat
            dense
            no-caps
            class="entity-link-action entity-link-action--metric"
            :to="{ path: '/students', query: { group: group.id } }"
          >
            {{ group.students_count ?? '—' }}
          </q-btn>
        </div>
        <div>
          <span>Год набора</span>
          <strong>{{ group.year_start || '—' }}</strong>
        </div>
        <div>
          <span>Курс</span>
          <strong>{{ group.course || '—' }}</strong>
        </div>
        <div>
          <span>Куратор</span>
          <strong>{{ curatorText }}</strong>
        </div>
      </div>

      <section class="group-details__section">
        <h3>Основное</h3>
        <dl class="group-details__list">
          <div>
            <dt>Название</dt>
            <dd>{{ group.name }}</dd>
          </div>
          <div>
            <dt>Специальность</dt>
            <dd>{{ specialtyText }}</dd>
          </div>
          <div>
            <dt>Образовательная программа</dt>
            <dd>{{ programText }}</dd>
          </div>
          <div>
            <dt>Форма обучения</dt>
            <dd>{{ studyFormText }}</dd>
          </div>
          <div>
            <dt>Год набора</dt>
            <dd>{{ group.year_start || '—' }}</dd>
          </div>
          <div>
            <dt>Курс</dt>
            <dd>{{ group.course || '—' }}</dd>
          </div>
        </dl>
      </section>

      <section class="group-details__section">
        <h3>Связанные данные</h3>
        <dl class="group-details__list">
          <div>
            <dt>Куратор</dt>
            <dd>{{ curatorText }}</dd>
          </div>
          <div>
            <dt>Количество студентов</dt>
            <dd>{{ group.students_count ?? '—' }}</dd>
          </div>
        </dl>

        <div class="group-details__actions">
          <q-btn
            unelevated
            no-caps
            class="entity-link-action"
            :to="{ path: '/students', query: { group: group.id } }"
          >
            Показать студентов
          </q-btn>
        </div>
      </section>
    </div>
  </AppCard>
</template>
