<script setup>
import { onMounted } from 'vue'
import { Bell, CalendarDays, ChevronRight, IdCard, RefreshCw, Star, UserRound } from '@lucide/vue'
import { useMobileStudentStore, attendanceLabel, formatLessonTime, lessonTitle } from '../../../stores/mobileStudent'

const store = useMobileStudentStore()
onMounted(() => store.load())
</script>

<template>
  <q-page class="mobile-student-page">
    <div v-if="store.loading" class="mobile-student-loading"><q-spinner color="primary" size="32px" /><span>Загрузка кабинета...</span></div>
    <q-banner v-else-if="store.error" class="mobile-student-banner mobile-student-banner--error">{{ store.error }}</q-banner>

    <template v-else>
      <section class="mobile-student-hero">
        <div class="mobile-student-avatar"><UserRound :size="32" /></div>
        <div>
          <p>Добро пожаловать</p>
          <h1>{{ store.studentName }}</h1>
          <span>{{ store.groupName }}</span>
        </div>
      </section>

      <q-banner v-if="!store.hasStudent" class="mobile-student-banner">{{ store.message || 'Текущий пользователь не связан с карточкой студента.' }}</q-banner>

      <RouterLink v-if="store.hasStudent" to="/m/student/pass" class="mobile-student-pass-button">
        <IdCard :size="22" />
        <span>Мой QR-пропуск</span>
        <ChevronRight :size="20" />
      </RouterLink>

      <section class="mobile-student-metrics" aria-label="Краткая сводка">
        <article><span>Средний балл</span><strong>{{ store.gradeAverage }}</strong></article>
        <article><span>Посещения</span><strong>{{ store.attendanceSummary.present }}/{{ store.attendanceTotal }}</strong></article>
      </section>

      <section class="mobile-student-card" id="schedule">
        <header><CalendarDays :size="20" /><h2>Сегодня</h2></header>
        <div v-if="store.nextLesson" class="mobile-student-next-lesson">
          <span>Ближайшее занятие</span>
          <strong>{{ lessonTitle(store.nextLesson) }}</strong>
          <p>{{ formatLessonTime(store.nextLesson) }} · {{ store.nextLesson.classroom?.number || 'Аудитория не указана' }}</p>
        </div>
        <div v-if="store.todaySchedule.length" class="mobile-student-list">
          <article v-for="lesson in store.todaySchedule" :key="lesson.id" class="mobile-student-list-item">
            <time>{{ formatLessonTime(lesson) }}</time>
            <div><strong>{{ lessonTitle(lesson) }}</strong><span>{{ lesson.teacher?.last_name || 'Преподаватель не указан' }} · {{ lesson.classroom?.number || '—' }}</span></div>
          </article>
        </div>
        <p v-else class="mobile-student-empty">На сегодня занятий нет.</p>
      </section>

      <section class="mobile-student-card" id="journal">
        <header><Star :size="20" /><h2>Оценки</h2></header>
        <div v-if="store.grades.length" class="mobile-student-grade-grid">
          <article v-for="grade in store.grades.slice(0, 6)" :key="grade.id"><strong>{{ grade.grade }}</strong><span>{{ grade.schedule_lesson?.subject?.name || 'Дисциплина' }}</span></article>
        </div>
        <p v-else class="mobile-student-empty">Оценок пока нет.</p>
      </section>

      <section class="mobile-student-card">
        <header><RefreshCw :size="20" /><h2>Посещаемость</h2></header>
        <div class="mobile-student-attendance-summary">
          <span>Присутствовал: {{ store.attendanceSummary.present }}</span>
          <span>Отсутствовал: {{ store.attendanceSummary.absent }}</span>
          <span>Опоздал: {{ store.attendanceSummary.late }}</span>
        </div>
        <div v-if="store.attendance.length" class="mobile-student-list mobile-student-list--compact">
          <article v-for="item in store.attendance.slice(0, 4)" :key="item.id" class="mobile-student-list-item"><div><strong>{{ item.schedule_lesson?.subject?.name || 'Занятие' }}</strong><span>{{ attendanceLabel(item.status) }}</span></div></article>
        </div>
      </section>

      <section class="mobile-student-card">
        <header><Bell :size="20" /><h2>Уведомления</h2></header>
        <div class="mobile-student-list mobile-student-list--compact">
          <article v-for="notice in store.notifications" :key="notice.id" class="mobile-student-list-item"><div><strong>{{ notice.title }}</strong><span>{{ notice.text }}</span></div></article>
        </div>
      </section>
    </template>
  </q-page>
</template>
