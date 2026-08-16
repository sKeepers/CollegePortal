<script setup>
import { computed, onMounted, ref } from 'vue'
import { CalendarDays, GraduationCap, RefreshCw } from '@lucide/vue'
import AppPage from '../../components/ui/AppPage.vue'
import PageHeader from '../../components/ui/PageHeader.vue'
import AppCard from '../../components/ui/AppCard.vue'
import AppLoading from '../../components/ui/AppLoading.vue'
import AppErrorBanner from '../../components/ui/AppErrorBanner.vue'
import { attendanceLabel, formatLessonTime, formatMobileDate, lessonTitle, useMobileStudentStore } from '../../stores/mobileStudent'

const store = useMobileStudentStore()
const selectedGrade = ref(null)
const gradeDialogOpen = ref(false)
const studentName = computed(() => store.studentName)

function openGrade(grade) {
  selectedGrade.value = grade
  gradeDialogOpen.value = true
}

onMounted(store.load)
</script>

<template>
  <AppPage>
    <PageHeader title="Успеваемость" subtitle="Личная учебная информация студента: оценки, посещаемость и ближайшие занятия.">
      <template #actions><q-btn flat :loading="store.loading" @click="store.load"><RefreshCw :size="16" class="q-mr-xs" />Обновить</q-btn></template>
    </PageHeader>
    <AppErrorBanner :message="store.error" />
    <AppLoading v-if="store.loading && !store.hasStudent" label="Загрузка личного кабинета..." />

    <template v-else>
      <section class="student-cabinet-hero"><div><span>Студент</span><h2>{{ studentName }}</h2><p>{{ store.groupName }}</p></div><div><strong>{{ store.gradeAverage }}</strong><span>Средний балл</span></div></section>
      <div class="student-cabinet-grid">
        <AppCard title="Оценки" subtitle="Нажмите оценку, чтобы увидеть подробности.">
          <div v-if="store.grades.length" class="student-cabinet-grades"><button v-for="grade in store.grades" :key="grade.id" type="button" @click="openGrade(grade)"><strong>{{ grade.grade }}</strong><span>{{ grade.lesson?.subject?.name || 'Дисциплина' }}</span><small>{{ formatMobileDate(grade.lesson?.lesson_date) }}</small></button></div>
          <p v-else class="cp-muted">Оценок пока нет.</p>
        </AppCard>
        <AppCard title="Посещаемость" :subtitle="`Присутствие: ${store.attendanceSummary.present}/${store.attendanceTotal}`">
          <div v-if="store.attendance.length" class="student-cabinet-attendance"><div v-for="item in store.attendance" :key="item.id"><strong>{{ item.lesson?.subject?.name || 'Занятие' }}</strong><span>{{ formatMobileDate(item.lesson?.lesson_date) }} · {{ attendanceLabel(item.status) }}<template v-if="item.status === 'late' && item.minutes_late"> · {{ item.minutes_late }} мин.</template></span></div></div>
          <p v-else class="cp-muted">Данных посещаемости пока нет.</p>
        </AppCard>
        <AppCard title="Ближайшее занятие"><div v-if="store.nextLesson" class="student-cabinet-next"><CalendarDays :size="22" /><div><strong>{{ lessonTitle(store.nextLesson) }}</strong><span>{{ formatLessonTime(store.nextLesson) }} · {{ store.nextLesson.classroom?.number || 'Аудитория не указана' }}</span></div></div><p v-else class="cp-muted">Ближайшее занятие не найдено.</p></AppCard>
      </div>
    </template>

    <q-dialog v-model="gradeDialogOpen" @hide="selectedGrade = null"><q-card class="student-cabinet-grade-dialog"><q-card-section><div class="text-h6">Оценка {{ selectedGrade?.grade || '—' }}</div></q-card-section><q-card-section class="student-cabinet-grade-dialog__details"><div><span>Дисциплина</span><strong>{{ selectedGrade?.lesson?.subject?.name || 'Не указана' }}</strong></div><div><span>Дата занятия</span><strong>{{ formatMobileDate(selectedGrade?.lesson?.lesson_date) }}</strong></div><div><span>Преподаватель</span><strong>{{ selectedGrade?.lesson?.teacher?.full_name || [selectedGrade?.lesson?.teacher?.last_name, selectedGrade?.lesson?.teacher?.first_name, selectedGrade?.lesson?.teacher?.middle_name].filter(Boolean).join(' ') || 'Не указан' }}</strong></div><div><span>Тип оценки</span><strong>{{ selectedGrade?.grade_type?.name || selectedGrade?.grade_type || 'Не указан' }}</strong></div><div v-if="selectedGrade?.comment"><span>Комментарий</span><strong>{{ selectedGrade.comment }}</strong></div></q-card-section><q-card-actions align="right"><q-btn flat label="Закрыть" v-close-popup /></q-card-actions></q-card></q-dialog>
  </AppPage>
</template>
