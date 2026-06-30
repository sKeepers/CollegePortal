<script setup>
import { computed } from 'vue'
import AppCard from '../../components/ui/AppCard.vue'
import AppEmptyState from '../../components/ui/AppEmptyState.vue'
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'

const props = defineProps({
  teacher: {
    type: Object,
    default: null,
  },
  subjects: {
    type: Array,
    default: () => [],
  },
  lessons: {
    type: Array,
    default: () => [],
  },
})

function fullName(teacher) {
  return [teacher?.last_name, teacher?.first_name, teacher?.middle_name].filter(Boolean).join(' ')
}

const teacherName = computed(() => fullName(props.teacher) || 'Преподаватель')
const statusLabel = computed(() => (props.teacher?.is_active ? 'Активен' : 'Неактивен'))
const statusTone = computed(() => (props.teacher?.is_active ? 'success' : 'neutral'))
const scheduleLink = computed(() => ({ path: '/schedule', query: { teacher: props.teacher?.id } }))
const journalLink = computed(() => ({ path: '/journal', query: { teacher: props.teacher?.id } }))
const subjectsLink = computed(() => ({ path: '/subjects', query: { teacher: props.teacher?.id } }))
</script>

<template>
  <AppCard class="teacher-details-card">
    <AppEmptyState
      v-if="!teacher"
      title="Преподаватель не выбран"
      description="Выберите строку в таблице, чтобы открыть карточку преподавателя."
    />

    <div v-else class="teacher-details">
      <div class="teacher-details__hero">
        <div class="teacher-details__title-row">
          <div class="teacher-details__title-block">
            <h2>{{ teacherName }}</h2>
            <div class="teacher-details__badges">
              <AppStatusBadge :label="statusLabel" :tone="statusTone" />
              <AppStatusBadge :label="teacher.department || 'Отделение не указано'" tone="info" />
            </div>
          </div>
        </div>
        <p>{{ teacher.position || 'Должность не указана' }}</p>
      </div>

      <div class="teacher-details__metrics">
        <div>
          <span>Дисциплин</span>
          <strong>{{ subjects.length }}</strong>
        </div>
        <div>
          <span>Занятий</span>
          <strong>{{ lessons.length }}</strong>
        </div>
        <div>
          <span>Телефон</span>
          <strong>{{ teacher.phone || '—' }}</strong>
        </div>
        <div>
          <span>Email</span>
          <strong>{{ teacher.email || '—' }}</strong>
        </div>
      </div>

      <section class="teacher-details__section">
        <h3>Контакты</h3>
        <dl class="teacher-details__list">
          <div>
            <dt>Телефон</dt>
            <dd>{{ teacher.phone || '—' }}</dd>
          </div>
          <div>
            <dt>Email</dt>
            <dd>{{ teacher.email || '—' }}</dd>
          </div>
          <div>
            <dt>Отделение</dt>
            <dd>{{ teacher.department || '—' }}</dd>
          </div>
          <div>
            <dt>Должность</dt>
            <dd>{{ teacher.position || '—' }}</dd>
          </div>
        </dl>
      </section>

      <section class="teacher-details__section">
        <h3>Дисциплины</h3>
        <div v-if="subjects.length" class="teacher-details__tags">
          <q-chip v-for="subject in subjects.slice(0, 6)" :key="subject.id" dense>
            {{ subject.name }}
          </q-chip>
        </div>
        <p v-else class="teacher-details__muted">Связанные дисциплины пока не найдены.</p>
      </section>

      <section class="teacher-details__section">
        <h3>Группы и занятия</h3>
        <div class="teacher-details__lesson-list" v-if="lessons.length">
          <div v-for="lesson in lessons.slice(0, 4)" :key="lesson.id">
            <strong>{{ lesson.subject?.name || 'Занятие' }}</strong>
            <span>
              {{ lesson.group?.name || 'Группа не указана' }} ·
              {{ lesson.date || 'Дата не указана' }} ·
              {{ lesson.start_time || '—' }}
            </span>
          </div>
        </div>
        <p v-else class="teacher-details__muted">Связанные занятия пока не найдены.</p>
      </section>

      <section class="teacher-details__section">
        <h3>Быстрые переходы</h3>
        <div class="teacher-details__actions">
          <q-btn unelevated no-caps class="entity-link-action" :to="scheduleLink">
            Открыть расписание преподавателя
          </q-btn>
          <q-btn flat no-caps class="entity-link-action" :to="journalLink">
            Открыть журнал
          </q-btn>
          <q-btn flat no-caps class="entity-link-action" :to="subjectsLink">
            Открыть связанные дисциплины
          </q-btn>
        </div>
      </section>
    </div>
  </AppCard>
</template>
