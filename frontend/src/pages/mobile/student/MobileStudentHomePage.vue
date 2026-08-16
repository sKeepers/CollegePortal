<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { Bell, CalendarDays, ChevronLeft, ChevronRight, IdCard, RefreshCw, Star, UserRound } from '@lucide/vue'
import { useMobileStudentStore, attendanceLabel, formatLessonTime, formatMobileDate, formatMobileScheduleDate, lessonTitle } from '../../../stores/mobileStudent'

const store = useMobileStudentStore()
const route = useRoute()
let refreshTimer = null
let clockTimer = null
const now = ref(Date.now())
const selectedGrade = ref(null)
const gradeDialogOpen = ref(false)

const qrSecondsLeft = computed(() => {
  const expires = new Date(store.qrExpiresAt || '').getTime()
  return Number.isNaN(expires) ? 0 : Math.max(0, Math.ceil((expires - now.value) / 1000))
})

function scrollToSection() {
  const id = route.hash.slice(1)
  if (!id) return
  nextTick(() => document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' }))
}

function openGrade(grade) {
  selectedGrade.value = grade
  gradeDialogOpen.value = true
}

onMounted(async () => {
  await store.load()
  refreshTimer = window.setInterval(store.load, 27_000)
  clockTimer = window.setInterval(() => { now.value = Date.now() }, 1_000)
  scrollToSection()
})

watch(() => route.hash, scrollToSection)

onBeforeUnmount(() => {
  if (refreshTimer) window.clearInterval(refreshTimer)
  if (clockTimer) window.clearInterval(clockTimer)
})
</script>

<template>
  <q-page class="mobile-student-page">
    <div v-if="store.loading" class="mobile-student-loading"><q-spinner color="primary" size="32px" /><span>Загрузка кабинета...</span></div>
    <q-banner v-else-if="store.error" class="mobile-student-banner mobile-student-banner--error">{{ store.error }}</q-banner>

    <template v-else>
      <RouterLink v-if="store.hasActivePass" to="/m/student/pass" class="mobile-student-pass-preview">
        <div v-html="store.qrSvg" />
        <span><IdCard :size="18" /> Мой QR-пропуск <ChevronRight :size="18" /></span>
        <small>Код обновится через {{ qrSecondsLeft }} сек.</small>
      </RouterLink>

      <section class="mobile-student-hero">
        <div class="mobile-student-avatar"><img v-if="store.student?.photo_url" :src="store.student.photo_url" alt="Фото" /><UserRound v-else :size="32" /></div>
        <div>
          <p>Добро пожаловать</p>
          <h1>{{ store.studentName }}</h1>
          <span>{{ store.groupName }}</span>
        </div>
      </section>

      <q-banner v-if="!store.hasStudent" class="mobile-student-banner">{{ store.message || 'Текущий пользователь не связан с карточкой студента.' }}</q-banner>

      <RouterLink v-if="store.hasStudent && !store.hasActivePass" to="/m/student/pass" class="mobile-student-pass-button">
        <IdCard :size="22" />
        <span>Мой QR-пропуск</span>
        <ChevronRight :size="20" />
      </RouterLink>

      <section class="mobile-student-metrics" aria-label="Краткая сводка">
        <article><span>Средний балл</span><strong>{{ store.gradeAverage }}</strong></article>
        <article><span>Посещения</span><strong>{{ store.attendanceSummary.present }}/{{ store.attendanceTotal }}</strong></article>
      </section>

      <section class="mobile-student-card" id="schedule">
        <header class="mobile-student-schedule-header"><div><CalendarDays :size="20" /><h2>{{ formatMobileScheduleDate(store.scheduleDate) }}</h2></div><div><q-btn flat round dense aria-label="Предыдущий день" :disable="store.loading" @click="store.changeScheduleDate(-1)"><ChevronLeft :size="20" /></q-btn><q-btn flat round dense aria-label="Следующий день" :disable="store.loading" @click="store.changeScheduleDate(1)"><ChevronRight :size="20" /></q-btn></div></header>
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
        <p v-else class="mobile-student-empty">На выбранную дату занятий нет.</p>
      </section>

      <section class="mobile-student-card" id="journal">
        <header><Star :size="20" /><h2>Оценки</h2></header>
        <div v-if="store.grades.length" class="mobile-student-grade-grid">
          <button v-for="grade in store.grades.slice(0, 6)" :key="grade.id" type="button" @click="openGrade(grade)"><strong>{{ grade.grade }}</strong><span>{{ grade.lesson?.subject?.name || 'Дисциплина' }}</span></button>
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
          <article v-for="item in store.attendance.slice(0, 4)" :key="item.id" class="mobile-student-list-item"><div><strong>{{ item.lesson?.subject?.name || 'Занятие' }}</strong><span>{{ formatMobileDate(item.lesson?.lesson_date) }} · {{ attendanceLabel(item.status) }}<template v-if="item.status === 'late' && item.minutes_late"> · {{ item.minutes_late }} мин.</template></span></div></article>
        </div>
      </section>

      <section class="mobile-student-card">
        <header><Bell :size="20" /><h2>Уведомления</h2></header>
        <div class="mobile-student-list mobile-student-list--compact">
          <article v-for="notice in store.notifications" :key="notice.id" class="mobile-student-list-item"><div><strong>{{ notice.title }}</strong><span>{{ notice.text }}</span></div></article>
        </div>
      </section>
    </template>

    <q-dialog v-model="gradeDialogOpen" @hide="selectedGrade = null">
      <q-card class="mobile-student-grade-dialog">
        <q-card-section><div class="text-h6">Оценка {{ selectedGrade?.grade || '—' }}</div></q-card-section>
        <q-card-section class="mobile-student-grade-dialog__details">
          <div><span>Дисциплина</span><strong>{{ selectedGrade?.lesson?.subject?.name || 'Не указана' }}</strong></div>
          <div><span>Дата занятия</span><strong>{{ formatMobileDate(selectedGrade?.lesson?.lesson_date) }}</strong></div>
          <div><span>Преподаватель</span><strong>{{ selectedGrade?.lesson?.teacher?.full_name || [selectedGrade?.lesson?.teacher?.last_name, selectedGrade?.lesson?.teacher?.first_name, selectedGrade?.lesson?.teacher?.middle_name].filter(Boolean).join(' ') || 'Не указан' }}</strong></div>
          <div><span>Тип оценки</span><strong>{{ selectedGrade?.grade_type?.name || selectedGrade?.grade_type || 'Не указан' }}</strong></div>
          <div v-if="selectedGrade?.comment"><span>Комментарий</span><strong>{{ selectedGrade.comment }}</strong></div>
        </q-card-section>
        <q-card-actions align="right"><q-btn flat label="Закрыть" v-close-popup /></q-card-actions>
      </q-card>
    </q-dialog>
  </q-page>
</template>
