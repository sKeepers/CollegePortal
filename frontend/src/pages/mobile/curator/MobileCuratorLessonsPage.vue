<script setup>
import { computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { BookOpen, ChevronLeft } from '@lucide/vue'
import { useCuratorGroupStore } from '../../../stores/curatorGroup'
import { formatCabinetDate } from '../../../stores/mobileTeacher'

const store = useCuratorGroupStore()
const route = useRoute()
const router = useRouter()

const groupId = computed(() => Number(route.params.groupId))

/**
 * Занятия группы — только просмотр. Ни одной кнопки правки здесь нет и быть не
 * может: правит тот, кто ведёт занятие, и сервер отвечает отказом всем
 * остальным. Экран показывает то, ради чего куратор сюда и приходит: заполнен
 * ли журнал и сколько человек отмечено.
 */
const byDate = computed(() => {
  const groups = new Map()
  for (const lesson of store.lessons) {
    const key = lesson.lesson_date || 'без даты'
    if (!groups.has(key)) groups.set(key, [])
    groups.get(key).push(lesson)
  }
  return [...groups.entries()].map(([date, lessons]) => ({ date, lessons }))
})

const statusLabels = {
  draft: 'черновик',
  planned: 'запланировано',
  opened: 'открыт',
  in_progress: 'заполняется',
  completed: 'завершено',
  signed: 'подписан',
  reopened: 'переоткрыт',
  cancelled: 'отменено',
}

function statusTone(lesson) {
  if (lesson.status === 'signed' || lesson.status === 'completed') return 'mobile-cabinet-tag--present'
  if (lesson.status === 'cancelled') return 'mobile-cabinet-tag--absent'
  return 'mobile-cabinet-tag--late'
}

function teacherName(lesson) {
  const teacher = lesson.teacher
  if (!teacher) return 'Преподаватель не указан'
  return [teacher.last_name, teacher.first_name].filter(Boolean).join(' ') || 'Преподаватель'
}

onMounted(() => store.loadLessons(groupId.value))
watch(groupId, (id) => store.loadLessons(id))
</script>

<template>
  <q-page class="mobile-cabinet-page">
    <button type="button" class="mobile-cabinet-back" @click="router.push(`/m/curator/groups/${groupId}`)">
      <ChevronLeft :size="18" /> К группе
    </button>

    <div v-if="store.lessonsLoading" class="mobile-cabinet-loading">
      <q-spinner color="primary" size="32px" /><span>Загрузка занятий...</span>
    </div>
    <q-banner v-else-if="store.error" class="mobile-cabinet-banner mobile-cabinet-banner--error">{{ store.error }}</q-banner>

    <template v-else>
      <section class="mobile-cabinet-hero">
        <div class="mobile-cabinet-avatar"><BookOpen :size="30" /></div>
        <div>
          <p>Журнал группы, только просмотр</p>
          <h1>Занятия</h1>
          <span>{{ store.lessons.length }} за период</span>
        </div>
      </section>

      <section v-for="day in byDate" :key="day.date" class="mobile-cabinet-card">
        <header><h2>{{ formatCabinetDate(day.date) }}</h2><small>{{ day.lessons.length }}</small></header>
        <ul class="mobile-cabinet-roster">
          <li v-for="lesson in day.lessons" :key="lesson.id" class="mobile-cabinet-roster-row">
            <div class="mobile-cabinet-roster-name">
              <strong>{{ lesson.subject?.name || 'Дисциплина не указана' }}</strong>
              <small :class="['mobile-cabinet-tag', statusTone(lesson)]">{{ statusLabels[lesson.status] || lesson.status }}</small>
            </div>
            <span class="mobile-cabinet-roster-note">
              {{ lesson.starts_at || '—' }} · {{ teacherName(lesson) }}
              <template v-if="lesson.metrics?.students">
                · отмечено {{ lesson.metrics.present + lesson.metrics.late + lesson.metrics.absent }} из {{ lesson.metrics.students }}
              </template>
              <template v-if="lesson.metrics?.grades"> · оценок {{ lesson.metrics.grades }}</template>
            </span>
            <span v-if="lesson.topic" class="mobile-cabinet-roster-note">{{ lesson.topic }}</span>
          </li>
        </ul>
      </section>

      <p v-if="!store.lessons.length" class="mobile-cabinet-empty">Занятий за период нет.</p>
    </template>
  </q-page>
</template>
