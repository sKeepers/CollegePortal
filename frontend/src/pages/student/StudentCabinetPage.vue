<script setup>
import { computed, onMounted, ref } from 'vue'
import { BedDouble, CalendarDays, GraduationCap, RefreshCw } from '@lucide/vue'
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
const dormAddress = computed(() => (store.dorm ? 'Общежитие на Серова, 277' : ''))

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
        <AppCard title="Посещаемость" :subtitle="store.attendanceLine">
          <div v-if="store.attendance.length" class="student-cabinet-attendance"><div v-for="item in store.attendance" :key="item.id"><strong>{{ item.lesson?.subject?.name || 'Занятие' }}</strong><span>{{ formatMobileDate(item.lesson?.lesson_date) }} · {{ attendanceLabel(item.status) }}<template v-if="item.status === 'late' && item.minutes_late"> · {{ item.minutes_late }} мин.</template></span></div></div>
          <p v-else class="cp-muted">Данных посещаемости пока нет.</p>
        </AppCard>
        <!--
          Общежитие показывается только проживающему: у 574 студентов из 596
          заселения нет, и пустая карточка была бы шумом, а не заботой. Отказ
          запроса сюда не доходит — при нём `store.error` рисует полосу выше, и
          «не живёт» с «не удалось спросить» не путаются.

          Соседей и занятости тут нет намеренно, и не потому, что их не
          нарисовали: сервер их не отдаёт вовсе. Решение владельца 01.09.2026 —
          «студенту не нужно показывать соседей, он должен видеть только своё».
        -->
        <AppCard v-if="store.dorm" title="Общежитие" :subtitle="dormAddress">
          <div class="student-cabinet-next">
            <BedDouble :size="22" />
            <div>
              <strong>Блок {{ store.dorm.room.number }}</strong>
              <span>
                {{ store.dorm.room.floor }} этаж<template v-if="store.dorm.room.capacity"> · мест {{ store.dorm.room.capacity }}</template>
                <template v-if="store.dorm.moved_in_at"> · живёте с {{ formatMobileDate(store.dorm.moved_in_at) }}</template>
              </span>
            </div>
          </div>
          <!--
            Про оплату говорится ровно то, что знает база: «оплачено по такое-то
            число». Ни задолженности, ни суммы к оплате в портале нет ни полем,
            ни правилом, поэтому они здесь не считаются и не показываются:
            «задолженность 0» при отсутствии учёта — это не ноль, а неизвестность.
          -->
          <div v-if="store.dorm.paid_through" class="student-cabinet-attendance">
            <div>
              <strong>Оплачено по {{ formatMobileDate(store.dorm.paid_through) }}</strong>
              <span v-for="payment in store.dorm.payments" :key="payment.id">
                {{ formatMobileDate(payment.paid_through) }}<template v-if="payment.amount"> · {{ payment.amount }} ₽</template>
              </span>
            </div>
          </div>
          <p v-else class="cp-muted">Отметок об оплате пока нет.</p>
        </AppCard>

        <AppCard title="Ближайшее занятие"><div v-if="store.nextLesson" class="student-cabinet-next"><CalendarDays :size="22" /><div><strong>{{ lessonTitle(store.nextLesson) }}</strong><span>{{ formatLessonTime(store.nextLesson) }} · {{ store.nextLesson.classroom?.number || 'Аудитория не указана' }}</span></div></div><p v-else class="cp-muted">Ближайшее занятие не найдено.</p></AppCard>
      </div>
    </template>

    <q-dialog v-model="gradeDialogOpen" @hide="selectedGrade = null"><q-card class="student-cabinet-grade-dialog"><q-card-section><div class="text-h6">Оценка {{ selectedGrade?.grade || '—' }}</div></q-card-section><q-card-section class="student-cabinet-grade-dialog__details"><div><span>Дисциплина</span><strong>{{ selectedGrade?.lesson?.subject?.name || 'Не указана' }}</strong></div><div><span>Дата занятия</span><strong>{{ formatMobileDate(selectedGrade?.lesson?.lesson_date) }}</strong></div><div><span>Преподаватель</span><strong>{{ selectedGrade?.lesson?.teacher?.full_name || [selectedGrade?.lesson?.teacher?.last_name, selectedGrade?.lesson?.teacher?.first_name, selectedGrade?.lesson?.teacher?.middle_name].filter(Boolean).join(' ') || 'Не указан' }}</strong></div><div><span>Тип оценки</span><strong>{{ selectedGrade?.grade_type?.name || selectedGrade?.grade_type || 'Не указан' }}</strong></div><div v-if="selectedGrade?.comment"><span>Комментарий</span><strong>{{ selectedGrade.comment }}</strong></div></q-card-section><q-card-actions align="right"><q-btn flat label="Закрыть" v-close-popup /></q-card-actions></q-card></q-dialog>
  </AppPage>
</template>
