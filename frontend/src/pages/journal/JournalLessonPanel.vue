<script setup>
import { computed } from 'vue'
import { BookOpen, Building2, CalendarDays, Clock, Users } from '@lucide/vue'
import AppCard from '../../components/ui/AppCard.vue'
import AppEmptyState from '../../components/ui/AppEmptyState.vue'
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'
import { lessonTypeLabels, lessonTypeTones, teacherName } from '../../stores/schedule'

const props = defineProps({
  lesson: {
    type: Object,
    default: null,
  },
  student: {
    type: Object,
    default: null,
  },
})

const lessonTypeText = computed(() => (
  lessonTypeLabels[props.lesson?.lesson_type] || props.lesson?.lesson_type || 'Тип не указан'
))

const lessonTypeTone = computed(() => lessonTypeTones[props.lesson?.lesson_type] || 'neutral')
const teacherText = computed(() => teacherName(props.lesson?.teacher) || 'Преподаватель не указан')
const groupText = computed(() => props.lesson?.group?.name || 'Группа не указана')
const classroomText = computed(() => props.lesson?.classroom?.number || 'Аудитория не указана')
const homeworkText = computed(() => props.lesson?.homework || props.lesson?.homework_text || 'Домашнее задание не указано')
</script>

<template>
  <AppCard class="journal-lesson-card">
    <AppEmptyState
      v-if="!lesson"
      title="Занятие не выбрано"
      description="Выберите колонку занятия в журнале, чтобы открыть подробности."
    />

    <div v-else class="journal-lesson">
      <div class="journal-lesson__hero">
        <div class="journal-lesson__title-row">
          <div>
            <h2>{{ lesson.subject?.name || 'Дисциплина не указана' }}</h2>
            <p>{{ lesson.topic || 'Тема занятия не указана' }}</p>
          </div>
          <AppStatusBadge :label="lessonTypeText" :tone="lessonTypeTone" />
        </div>

        <div class="journal-lesson__time">
          <CalendarDays :size="16" />
          <span>{{ lesson.lesson_date || 'Дата не указана' }}</span>
          <Clock :size="16" />
          <span>{{ lesson.starts_at || '—' }}–{{ lesson.ends_at || '—' }}</span>
        </div>
      </div>

      <section class="journal-lesson__metrics">
        <div>
          <Users :size="16" />
          <span>Группа</span>
          <strong>{{ groupText }}</strong>
        </div>
        <div>
          <BookOpen :size="16" />
          <span>Преподаватель</span>
          <strong>{{ teacherText }}</strong>
        </div>
        <div>
          <Building2 :size="16" />
          <span>Аудитория</span>
          <strong>{{ classroomText }}</strong>
        </div>
        <div>
          <Clock :size="16" />
          <span>Время</span>
          <strong>{{ lesson.starts_at || '—' }}–{{ lesson.ends_at || '—' }}</strong>
        </div>
      </section>

      <section class="journal-lesson__section">
        <h3>Занятие</h3>
        <dl class="journal-lesson__list">
          <div>
            <dt>Дисциплина</dt>
            <dd>{{ lesson.subject?.name || '—' }}</dd>
          </div>
          <div>
            <dt>Преподаватель</dt>
            <dd>{{ teacherText }}</dd>
          </div>
          <div>
            <dt>Группа</dt>
            <dd>{{ groupText }}</dd>
          </div>
          <div>
            <dt>Аудитория</dt>
            <dd>{{ classroomText }}</dd>
          </div>
          <div>
            <dt>Дата</dt>
            <dd>{{ lesson.lesson_date || '—' }}</dd>
          </div>
          <div>
            <dt>Тема</dt>
            <dd>{{ lesson.topic || '—' }}</dd>
          </div>
          <div>
            <dt>Домашнее задание</dt>
            <dd>{{ homeworkText }}</dd>
          </div>
        </dl>
      </section>

      <section class="journal-lesson__section">
        <h3>Переходы</h3>
        <div class="journal-lesson__actions">
          <q-btn
            unelevated
            no-caps
            class="entity-link-action"
            :to="student?.id
              ? { path: '/students', query: { group: lesson.group_id, selected: student.id } }
              : { path: '/students', query: { group: lesson.group_id } }"
            :disable="!lesson.group_id"
          >
            {{ student?.id ? 'Открыть студента' : 'Открыть студентов группы' }}
          </q-btn>
          <q-btn
            unelevated
            no-caps
            class="entity-link-action"
            :to="{ path: '/groups', query: { selected: lesson.group_id } }"
            :disable="!lesson.group_id"
          >
            Открыть группу
          </q-btn>
          <q-btn
            unelevated
            no-caps
            class="entity-link-action"
            :to="{ path: '/schedule', query: { selected: lesson.id, date: lesson.lesson_date } }"
          >
            Открыть расписание
          </q-btn>
        </div>
      </section>
    </div>
  </AppCard>
</template>
