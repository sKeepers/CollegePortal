<script setup>
import { computed } from 'vue'
import AppCard from '../../components/ui/AppCard.vue'
import AppEmptyState from '../../components/ui/AppEmptyState.vue'
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'

const props = defineProps({
  subject: {
    type: Object,
    default: null,
  },
  teachers: {
    type: Array,
    default: () => [],
  },
  lessons: {
    type: Array,
    default: () => [],
  },
})

function teacherName(teacher) {
  return [teacher?.last_name, teacher?.first_name, teacher?.middle_name].filter(Boolean).join(' ')
}

function lessonDate(lesson) {
  return [lesson?.lesson_date, lesson?.starts_at].filter(Boolean).join(' · ')
}

const scheduleLink = computed(() => ({ path: '/schedule', query: { subject: props.subject?.id } }))
const journalLink = computed(() => ({ path: '/journal', query: { subject: props.subject?.id } }))
const teachersLink = computed(() => ({ path: '/teachers', query: { subject: props.subject?.id } }))
</script>

<template>
  <AppCard class="subject-details-card">
    <AppEmptyState
      v-if="!subject"
      title="Дисциплина не выбрана"
      description="Выберите строку в таблице, чтобы открыть карточку дисциплины."
    />

    <div v-else class="subject-details">
      <div class="subject-details__hero">
        <div class="subject-details__title-row">
          <div class="subject-details__title-block">
            <h2>{{ subject.name }}</h2>
            <div class="subject-details__badges">
              <AppStatusBadge label="Активна" tone="success" />
              <AppStatusBadge v-if="subject.department" :label="subject.department" tone="info" />
            </div>
          </div>
        </div>
        <p v-if="subject.description" class="subject-details__description">{{ subject.description }}</p>
      </div>

      <div class="subject-details__metrics">
        <div>
          <span>Код</span>
          <strong>{{ subject.code || '—' }}</strong>
        </div>
        <div>
          <span>Преподавателей</span>
          <strong>{{ teachers.length }}</strong>
        </div>
        <div>
          <span>Занятий</span>
          <strong>{{ lessons.length }}</strong>
        </div>
        <div>
          <span>Отделение</span>
          <strong>{{ subject.department || '—' }}</strong>
        </div>
      </div>

      <section class="subject-details__section">
        <h3>Основное</h3>
        <dl class="subject-details__list">
          <div>
            <dt>Название</dt>
            <dd>{{ subject.name }}</dd>
          </div>
          <div>
            <dt>Код</dt>
            <dd>{{ subject.code || '—' }}</dd>
          </div>
          <div>
            <dt>Отделение</dt>
            <dd>{{ subject.department || '—' }}</dd>
          </div>
        </dl>
      </section>

      <section class="subject-details__section">
        <h3>Преподаватели</h3>
        <div v-if="teachers.length" class="subject-details__tags">
          <q-chip v-for="teacher in teachers.slice(0, 8)" :key="teacher.id" dense>
            {{ teacherName(teacher) }}
          </q-chip>
        </div>
        <p v-else class="subject-details__muted">Связанные преподаватели пока не указаны.</p>
      </section>

      <section class="subject-details__section">
        <h3>Группы и занятия</h3>
        <div v-if="lessons.length" class="subject-details__lesson-list">
          <div v-for="lesson in lessons.slice(0, 5)" :key="lesson.id">
            <strong>{{ lesson.group?.name || 'Группа не указана' }}</strong>
            <span>
              {{ lessonDate(lesson) || 'Дата не указана' }}
              <template v-if="lesson.teacher">
                · {{ teacherName(lesson.teacher) }}
              </template>
            </span>
          </div>
        </div>
        <p v-else class="subject-details__muted">Связанные занятия пока не найдены.</p>
      </section>

      <section class="subject-details__section">
        <h3>Быстрые переходы</h3>
        <div class="subject-details__actions">
          <q-btn flat no-caps class="entity-link-action" :to="scheduleLink">
            Открыть расписание по дисциплине
          </q-btn>
          <q-btn flat no-caps class="entity-link-action" :to="journalLink">
            Открыть журнал
          </q-btn>
          <q-btn flat no-caps class="entity-link-action" :to="teachersLink">
            Открыть связанных преподавателей
          </q-btn>
        </div>
      </section>
    </div>
  </AppCard>
</template>
