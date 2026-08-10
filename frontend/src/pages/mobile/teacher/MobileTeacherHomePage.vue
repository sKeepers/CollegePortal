<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { CalendarDays, ChevronLeft, ChevronRight, ClipboardCheck, IdCard, Lock, UserRound } from '@lucide/vue'
import {
  useMobileTeacherStore,
  dayNumber,
  formatCabinetDate,
  lessonTime,
  lessonTitle,
  weekdayShort,
} from '../../../stores/mobileTeacher'

const store = useMobileTeacherStore()
const route = useRoute()
const router = useRouter()
const opening = ref(null)
const now = ref(Date.now())
let clockTimer = null

const qrSecondsLeft = computed(() => {
  const expires = new Date(store.qrExpiresAt || '').getTime()
  return Number.isNaN(expires) ? 0 : Math.max(0, Math.ceil((expires - now.value) / 1000))
})

function journalHint(lesson) {
  const journal = lesson.journal
  if (journal.is_signed) return 'Журнал подписан'
  if (journal.lesson_id === null) return journal.can_open ? 'Журнал не открыт' : 'Журнал не открыт — нет прав на правку'
  if (journal.students === 0) return 'Журнал открыт'
  return `Отмечено ${journal.marked} из ${journal.students}`
}

/** Занятие кликабельно, только если с ним есть что сделать. */
function lessonIsActionable(lesson) {
  return lesson.journal.lesson_id !== null || lesson.journal.can_open
}

async function openLesson(lesson) {
  if (!lessonIsActionable(lesson) || opening.value) return

  if (lesson.journal.lesson_id !== null) {
    await router.push(`/m/teacher/journal/${lesson.journal.lesson_id}`)
    return
  }

  opening.value = lesson.id
  try {
    const lessonId = await store.openJournal(lesson.id)
    if (lessonId) await router.push(`/m/teacher/journal/${lessonId}`)
  } finally {
    opening.value = null
  }
}

function scrollToSection() {
  const id = route.hash.slice(1)
  if (!id) return
  nextTick(() => document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' }))
}

onMounted(async () => {
  await store.load()
  clockTimer = window.setInterval(() => { now.value = Date.now() }, 1_000)
  scrollToSection()
})

watch(() => route.hash, scrollToSection)

onBeforeUnmount(() => {
  if (clockTimer) window.clearInterval(clockTimer)
})
</script>

<template>
  <q-page class="mobile-cabinet-page">
    <div v-if="store.loading" class="mobile-cabinet-loading"><q-spinner color="primary" size="32px" /><span>Загрузка кабинета...</span></div>
    <q-banner v-else-if="store.error" class="mobile-cabinet-banner mobile-cabinet-banner--error">{{ store.error }}</q-banner>

    <template v-else>
      <section class="mobile-cabinet-hero">
        <div class="mobile-cabinet-avatar"><UserRound :size="32" /></div>
        <div>
          <p>Кабинет преподавателя</p>
          <h1>{{ store.teacherName }}</h1>
          <span v-if="store.hasTeacher">{{ store.daySummary.lessons }} занятий на выбранный день</span>
        </div>
      </section>

      <q-banner v-if="!store.hasTeacher" class="mobile-cabinet-banner">{{ store.message || 'Текущий пользователь не связан с карточкой преподавателя.' }}</q-banner>

      <template v-else>
        <RouterLink v-if="store.hasActivePass" to="/m/teacher/pass" class="mobile-cabinet-pass-preview">
          <div v-html="store.qrSvg" />
          <span><IdCard :size="18" /> Мой QR-пропуск <ChevronRight :size="18" /></span>
          <small>Код обновится через {{ qrSecondsLeft }} сек.</small>
        </RouterLink>

        <section class="mobile-cabinet-card" id="week">
          <header class="mobile-cabinet-day-header">
            <div><CalendarDays :size="20" /><h2>{{ formatCabinetDate(store.scheduleDate) }}</h2></div>
            <div>
              <q-btn flat round dense aria-label="Предыдущий день" :disable="store.loading" @click="store.changeScheduleDate(-1)"><ChevronLeft :size="20" /></q-btn>
              <q-btn flat round dense aria-label="Следующий день" :disable="store.loading" @click="store.changeScheduleDate(1)"><ChevronRight :size="20" /></q-btn>
            </div>
          </header>

          <div class="mobile-cabinet-week">
            <button
              v-for="day in store.week"
              :key="day.date"
              type="button"
              :class="['mobile-cabinet-week-day', {
                'mobile-cabinet-week-day--selected': day.is_selected,
                'mobile-cabinet-week-day--today': day.is_today,
                'mobile-cabinet-week-day--empty': day.lessons === 0,
              }]"
              :aria-label="`${formatCabinetDate(day.date)}, занятий: ${day.lessons}`"
              @click="store.selectDate(day.date)"
            >
              <span>{{ weekdayShort(day.date) }}</span>
              <strong>{{ dayNumber(day.date) }}</strong>
              <small>{{ day.lessons || '—' }}</small>
            </button>
          </div>
        </section>

        <section class="mobile-cabinet-card">
          <header><ClipboardCheck :size="20" /><h2>Занятия</h2></header>

          <div v-if="store.lessons.length" class="mobile-cabinet-lessons">
            <component
              :is="lessonIsActionable(lesson) ? 'button' : 'div'"
              v-for="lesson in store.lessons"
              :key="lesson.id"
              :type="lessonIsActionable(lesson) ? 'button' : null"
              :class="['mobile-cabinet-lesson', {
                'mobile-cabinet-lesson--next': lesson.id === store.nextLessonId,
                'mobile-cabinet-lesson--static': !lessonIsActionable(lesson),
              }]"
              :disabled="opening === lesson.id"
              @click="lessonIsActionable(lesson) ? openLesson(lesson) : null"
            >
              <time>{{ lessonTime(lesson) }}</time>
              <div class="mobile-cabinet-lesson-body">
                <strong>{{ lessonTitle(lesson) }}</strong>
                <span>{{ lesson.group?.name || 'Группа не указана' }} · {{ lesson.classroom?.number || 'Аудитория не указана' }}</span>
                <em :class="{ 'mobile-cabinet-lesson-hint--signed': lesson.journal.is_signed }">
                  <Lock v-if="lesson.journal.is_signed" :size="13" />
                  {{ journalHint(lesson) }}
                </em>
              </div>
              <q-spinner v-if="opening === lesson.id" color="primary" size="18px" />
              <ChevronRight v-else-if="lessonIsActionable(lesson)" :size="18" />
            </component>
          </div>
          <p v-else class="mobile-cabinet-empty">На выбранную дату занятий нет.</p>

          <q-banner v-if="store.journalError" class="mobile-cabinet-banner mobile-cabinet-banner--error">{{ store.journalError }}</q-banner>
        </section>

        <RouterLink v-if="!store.hasActivePass" to="/m/teacher/pass" class="mobile-cabinet-pass-button">
          <IdCard :size="22" />
          <span>Мой QR-пропуск</span>
          <ChevronRight :size="20" />
        </RouterLink>
      </template>
    </template>
  </q-page>
</template>
