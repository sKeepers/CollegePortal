<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ChevronLeft, Lock, Save } from '@lucide/vue'
import {
  useMobileTeacherStore,
  ATTENDANCE_STATUSES,
  GRADE_VALUES,
  formatCabinetDate,
  lessonTime,
  lessonTitle,
  studentName,
} from '../../../stores/mobileTeacher'

const store = useMobileTeacherStore()
const route = useRoute()
const router = useRouter()
const topic = ref('')
const topicSaving = ref(false)

const lessonId = computed(() => Number(route.params.lessonId))
const lesson = computed(() => store.journal)
const topicChanged = computed(() => topic.value.trim() !== (lesson.value?.topic || '').trim())

async function reload() {
  await store.loadJournal(lessonId.value)
  topic.value = lesson.value?.topic || ''
}

async function saveTopic() {
  topicSaving.value = true
  try {
    await store.saveTopic(topic.value.trim())
    topic.value = lesson.value?.topic || ''
  } finally {
    topicSaving.value = false
  }
}

function attendanceOf(row) {
  return ATTENDANCE_STATUSES.find((status) => status.value === row.status) || null
}

onMounted(reload)
watch(lessonId, reload)
</script>

<template>
  <q-page class="mobile-cabinet-page">
    <button type="button" class="mobile-cabinet-back" @click="router.push('/m/teacher')">
      <ChevronLeft :size="18" /> К занятиям
    </button>

    <div v-if="store.journalLoading" class="mobile-cabinet-loading"><q-spinner color="primary" size="32px" /><span>Загрузка журнала...</span></div>
    <q-banner v-else-if="!lesson" class="mobile-cabinet-banner mobile-cabinet-banner--error">{{ store.journalError || 'Журнал занятия недоступен.' }}</q-banner>

    <template v-else>
      <section class="mobile-cabinet-lesson-hero">
        <h1>{{ lessonTitle(lesson) }}</h1>
        <p>{{ lesson.group?.name || 'Группа не указана' }} · {{ lessonTime(lesson) }}</p>
        <p>{{ formatCabinetDate(lesson.lesson_date) }}</p>
        <span v-if="store.journalIsSigned" class="mobile-cabinet-signed"><Lock :size="14" /> Журнал подписан, правка закрыта</span>
      </section>

      <q-banner v-if="store.journalError" class="mobile-cabinet-banner mobile-cabinet-banner--error">{{ store.journalError }}</q-banner>

      <section v-if="!store.journalIsSigned && store.abilities.open_journal" class="mobile-cabinet-card">
        <header><h2>Тема занятия</h2></header>
        <q-input v-model="topic" outlined dense autogrow placeholder="О чём было занятие" :disable="topicSaving" />
        <q-btn v-if="topicChanged" color="primary" no-caps class="full-width q-mt-sm" :loading="topicSaving" @click="saveTopic">
          <Save :size="16" class="q-mr-xs" /> Сохранить тему
        </q-btn>
      </section>

      <section class="mobile-cabinet-card">
        <header>
          <h2>Посещаемость и оценки</h2>
          <small>{{ store.journalStudents.length }} чел.</small>
        </header>

        <p v-if="!store.canMarkAttendance && !store.canSetGrades" class="mobile-cabinet-empty">
          Журнал доступен только для просмотра.
        </p>

        <ul class="mobile-cabinet-roster">
          <li v-for="row in store.journalStudents" :key="row.student_id" class="mobile-cabinet-roster-row">
            <div class="mobile-cabinet-roster-name">
              <strong>{{ studentName(row.student) }}</strong>
              <q-spinner v-if="store.savingStudentId === row.student_id" color="primary" size="16px" />
              <small v-else-if="row.status">{{ attendanceOf(row)?.label || row.status }}</small>
            </div>

            <div v-if="store.canMarkAttendance" class="mobile-cabinet-choice" role="group" aria-label="Посещаемость">
              <button
                v-for="status in ATTENDANCE_STATUSES"
                :key="status.value"
                type="button"
                :class="['mobile-cabinet-choice-item', `mobile-cabinet-choice-item--${status.value}`, { 'mobile-cabinet-choice-item--on': row.status === status.value }]"
                :aria-label="status.label"
                :aria-pressed="row.status === status.value"
                @click="store.markAttendance(row.student_id, status.value)"
              >{{ status.short }}</button>
            </div>

            <div v-if="store.canSetGrades" class="mobile-cabinet-choice mobile-cabinet-choice--grades" role="group" aria-label="Оценка">
              <button
                v-for="value in GRADE_VALUES"
                :key="value"
                type="button"
                :class="['mobile-cabinet-choice-item', { 'mobile-cabinet-choice-item--on': store.gradeFor(row.student_id) === value }]"
                :aria-label="`Оценка ${value}`"
                :aria-pressed="store.gradeFor(row.student_id) === value"
                @click="store.setGrade(row.student_id, value)"
              >{{ value }}</button>
              <button
                type="button"
                class="mobile-cabinet-choice-item mobile-cabinet-choice-item--clear"
                aria-label="Убрать оценку"
                @click="store.setGrade(row.student_id, '')"
              >—</button>
            </div>
          </li>
        </ul>

        <p v-if="!store.journalStudents.length" class="mobile-cabinet-empty">В группе нет студентов.</p>
      </section>
    </template>
  </q-page>
</template>
