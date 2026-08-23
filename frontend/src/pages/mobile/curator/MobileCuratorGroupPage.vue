<script setup>
import { computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { BookOpen, ChevronLeft, ChevronRight, DoorOpen, GraduationCap, Mail, Phone, Users } from '@lucide/vue'
import { useMobileCuratorStore, eventDirectionLabel, eventTime } from '../../../stores/mobileCurator'
import { formatCabinetDate } from '../../../stores/mobileTeacher'

const store = useMobileCuratorStore()
const route = useRoute()
const router = useRouter()

const groupId = computed(() => Number(route.params.groupId))
const summary = computed(() => store.attendanceSummary || store.summary)
const rangeLabel = computed(() => (store.range === 'week'
  ? `${formatCabinetDate(store.attendanceRange.from)} — ${formatCabinetDate(store.attendanceRange.to)}`
  : formatCabinetDate(store.date)))

function scrollToAccess() {
  document.getElementById('access')?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

function statusTone(row) {
  if (row.status === 'late') return 'mobile-cabinet-tag--late'
  if (row.status === 'absent' || row.status === 'no_events') return 'mobile-cabinet-tag--absent'
  return 'mobile-cabinet-tag--present'
}

onMounted(() => store.openGroup(groupId.value))
watch(groupId, (id) => store.openGroup(id))
</script>

<template>
  <q-page class="mobile-cabinet-page">
    <button type="button" class="mobile-cabinet-back" @click="router.push('/m/curator')">
      <ChevronLeft :size="18" /> К группам
    </button>

    <div v-if="store.groupLoading" class="mobile-cabinet-loading"><q-spinner color="primary" size="32px" /><span>Загрузка группы...</span></div>
    <q-banner v-else-if="store.groupError" class="mobile-cabinet-banner mobile-cabinet-banner--error">{{ store.groupError }}</q-banner>

    <template v-else-if="store.group">
      <section class="mobile-cabinet-hero">
        <div class="mobile-cabinet-avatar"><Users :size="30" /></div>
        <div>
          <p>{{ store.group.course }} курс · {{ store.group.specialty || 'Специальность не указана' }}</p>
          <h1>{{ store.group.name }}</h1>
          <span>{{ store.students.length }} студентов, контакты у {{ store.studentsWithContacts }}</span>
        </div>
      </section>

      <div class="mobile-cabinet-choice">
        <button
          type="button"
          class="mobile-cabinet-choice-item"
          @click="router.push(`/m/curator/groups/${groupId}/performance`)"
        >
          <GraduationCap :size="16" /> Успеваемость
        </button>
        <button
          type="button"
          class="mobile-cabinet-choice-item"
          @click="router.push(`/m/curator/groups/${groupId}/lessons`)"
        >
          <BookOpen :size="16" /> Занятия
        </button>
        <button type="button" class="mobile-cabinet-choice-item" @click="scrollToAccess">
          <DoorOpen :size="16" /> Проходная
        </button>
      </div>

      <section class="mobile-cabinet-card">
        <header class="mobile-cabinet-day-header">
          <div><h2>{{ rangeLabel }}</h2></div>
          <div>
            <q-btn flat round dense aria-label="Предыдущий день" :disable="store.attendanceLoading" @click="store.changeDate(groupId, -1)"><ChevronLeft :size="20" /></q-btn>
            <q-btn flat round dense aria-label="Следующий день" :disable="store.attendanceLoading" @click="store.changeDate(groupId, 1)"><ChevronRight :size="20" /></q-btn>
          </div>
        </header>

        <div class="mobile-cabinet-choice mobile-cabinet-range">
          <button
            type="button"
            :class="['mobile-cabinet-choice-item', { 'mobile-cabinet-choice-item--on': store.range === 'day' }]"
            @click="store.changeRange(groupId, 'day')"
          >День</button>
          <button
            type="button"
            :class="['mobile-cabinet-choice-item', { 'mobile-cabinet-choice-item--on': store.range === 'week' }]"
            @click="store.changeRange(groupId, 'week')"
          >Неделя</button>
        </div>

        <!--
          Эти четыре числа считает проходная, а не журнал: «не пришли» значит «нет
          прохода через турникет», и с отметкой преподавателя оно не совпадает. Пока
          подписи молчали об этом, куратор читал их как посещаемость занятий — а в дни,
          когда проходная молчит, вся группа выглядела не пришедшей.
        -->
        <p v-if="summary" class="mobile-cabinet-empty mobile-cabinet-metrics-source">По данным проходной, не по журналу</p>

        <div v-if="summary" class="mobile-cabinet-metrics">
          <article><span>Вовремя</span><strong>{{ summary.on_time }}</strong></article>
          <article><span>Опоздали</span><strong>{{ summary.late }}</strong></article>
          <article><span>Нет прохода</span><strong>{{ summary.absent }}</strong></article>
          <article><span>Сейчас в здании</span><strong>{{ summary.inside_now }}</strong></article>
        </div>

        <q-banner v-if="summary && !summary.with_events" class="mobile-cabinet-banner">
          За этот день проходная не отметила никого из группы. Числа выше говорят об
          отсутствии прохода, а не о том, был ли студент на занятии, — это видно в журнале.
        </q-banner>

        <div v-if="store.attendanceLoading" class="mobile-cabinet-loading"><q-spinner color="primary" size="24px" /></div>
        <ul v-else-if="store.attendanceRows.length" class="mobile-cabinet-roster">
          <li v-for="row in store.attendanceRows" :key="row.id" class="mobile-cabinet-roster-row">
            <div class="mobile-cabinet-roster-name">
              <strong>{{ row.full_name }}</strong>
              <small :class="['mobile-cabinet-tag', statusTone(row)]">{{ row.status_label }}</small>
            </div>
            <span class="mobile-cabinet-roster-note">
              {{ row.first_entry ? `Вход ${eventTime(row.first_entry)}` : 'Входа не было' }}
              <template v-if="row.late_minutes"> · опоздание {{ row.late_minutes }} мин.</template>
            </span>
          </li>
        </ul>
        <p v-else class="mobile-cabinet-empty">За выбранный период данных нет.</p>
      </section>

      <section class="mobile-cabinet-card">
        <header><Users :size="20" /><h2>Студенты</h2><small>{{ store.students.length }}</small></header>
        <ul class="mobile-cabinet-roster">
          <li v-for="student in store.students" :key="student.id" class="mobile-cabinet-roster-row">
            <div class="mobile-cabinet-roster-name"><strong>{{ student.full_name }}</strong></div>
            <div class="mobile-cabinet-contacts">
              <a v-if="student.phone" :href="`tel:${student.phone}`"><Phone :size="15" /> {{ student.phone }}</a>
              <a v-if="student.email" :href="`mailto:${student.email}`"><Mail :size="15" /> {{ student.email }}</a>
              <span v-if="!student.phone && !student.email">Контактов нет</span>
            </div>
          </li>
        </ul>
        <p v-if="!store.students.length" class="mobile-cabinet-empty">В группе нет студентов.</p>
      </section>

      <section class="mobile-cabinet-card" id="access">
        <header><DoorOpen :size="20" /><h2>Проходная</h2><small>{{ store.accessTotal }} за день</small></header>
        <div v-if="store.accessLoading" class="mobile-cabinet-loading"><q-spinner color="primary" size="24px" /></div>
        <template v-else>
          <ul v-if="store.accessEvents.length" class="mobile-cabinet-roster">
            <li v-for="event in store.accessEvents" :key="event.id" class="mobile-cabinet-roster-row">
              <div class="mobile-cabinet-roster-name">
                <strong>{{ event.full_name }}</strong>
                <small>{{ eventTime(event.event_time) }}</small>
              </div>
              <span class="mobile-cabinet-roster-note">
                {{ eventDirectionLabel(event.direction) }}
                <template v-if="event.result === 'denied'"> · отказ</template>
                <template v-if="event.access_point"> · {{ event.access_point }}</template>
              </span>
            </li>
          </ul>
          <p v-else class="mobile-cabinet-empty">За выбранный день событий проходной нет.</p>
          <p v-if="store.accessTruncated" class="mobile-cabinet-empty">Показаны последние 100 событий из {{ store.accessTotal }}.</p>
        </template>
      </section>
    </template>
  </q-page>
</template>
