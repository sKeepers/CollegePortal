<script setup>
import { computed } from 'vue'
import { AlertTriangle, BookOpen, Building2, CalendarDays, Clock, DoorOpen, Users } from '@lucide/vue'
import AppCard from '../../components/ui/AppCard.vue'
import AppEmptyState from '../../components/ui/AppEmptyState.vue'
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'
import {
  classroomLabel,
  lessonTypeLabels,
  lessonTypeTones,
  teacherName,
} from '../../stores/schedule'

const props = defineProps({
  lesson: {
    type: Object,
    default: null,
  },
  conflicts: {
    type: Array,
    default: () => [],
  },
})

const lessonTypeText = computed(() => (
  lessonTypeLabels[props.lesson?.lesson_type] || props.lesson?.lesson_type || 'Тип не указан'
))

const lessonTypeTone = computed(() => lessonTypeTones[props.lesson?.lesson_type] || 'neutral')
const teacherText = computed(() => teacherName(props.lesson?.teacher) || 'Преподаватель не указан')
const classroomText = computed(() => classroomLabel(props.lesson?.classroom) || 'Аудитория не указана')
const groupText = computed(() => props.lesson?.group?.name || 'Группа не указана')
const subjectText = computed(() => props.lesson?.subject?.name || 'Дисциплина не указана')
</script>

<template>
  <AppCard class="schedule-details-card">
    <AppEmptyState
      v-if="!lesson"
      title="Занятие не выбрано"
      description="Выберите занятие в расписании, чтобы открыть подробности."
    />

    <div v-else class="schedule-details">
      <div class="schedule-details__hero">
        <div class="schedule-details__title-row">
          <div>
            <h2>{{ subjectText }}</h2>
            <p>{{ lesson.topic || 'Тема занятия не указана' }}</p>
          </div>
          <AppStatusBadge :label="lessonTypeText" :tone="lessonTypeTone" />
        </div>

        <div class="schedule-details__time">
          <CalendarDays :size="16" />
          <span>{{ lesson.lesson_date || 'Дата не указана' }}</span>
          <Clock :size="16" />
          <span>{{ lesson.starts_at || '—' }}–{{ lesson.ends_at || '—' }}</span>
        </div>
      </div>

      <q-banner v-if="conflicts.length" rounded class="schedule-details__warning">
        <template #avatar>
          <AlertTriangle :size="18" />
        </template>
        <strong>Возможный конфликт</strong>
        <ul>
          <li v-for="conflict in conflicts" :key="conflict">{{ conflict }}</li>
        </ul>
      </q-banner>

      <section class="schedule-details__metrics">
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
          <DoorOpen :size="16" />
          <span>Тип</span>
          <strong>{{ lessonTypeText }}</strong>
        </div>
      </section>

      <section class="schedule-details__section">
        <h3>Занятие</h3>
        <dl class="schedule-details__list">
          <div>
            <dt>Дисциплина</dt>
            <dd>{{ subjectText }}</dd>
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
            <dt>Время</dt>
            <dd>{{ lesson.starts_at || '—' }}–{{ lesson.ends_at || '—' }}</dd>
          </div>
        </dl>
      </section>

      <section class="schedule-details__section">
        <h3>Переходы</h3>
        <div class="schedule-details__actions">
          <q-btn
            unelevated
            no-caps
            class="entity-link-action"
            :to="{ path: '/journal', query: { lesson: lesson.id } }"
          >
            Открыть журнал
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
            :to="{ path: '/teachers', query: { selected: lesson.teacher_id } }"
            :disable="!lesson.teacher_id"
          >
            Открыть преподавателя
          </q-btn>
          <q-btn
            unelevated
            no-caps
            class="entity-link-action"
            :to="{ path: '/classrooms', query: { selected: lesson.classroom_id } }"
            :disable="!lesson.classroom_id"
          >
            Открыть аудиторию
          </q-btn>
        </div>
      </section>
    </div>
  </AppCard>
</template>
