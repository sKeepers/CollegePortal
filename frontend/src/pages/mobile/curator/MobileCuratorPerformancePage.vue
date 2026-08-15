<script setup>
import { computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { AlertTriangle, ChevronLeft, GraduationCap } from '@lucide/vue'
import { useCuratorGroupStore } from '../../../stores/curatorGroup'

const store = useCuratorGroupStore()
const route = useRoute()
const router = useRouter()

const groupId = computed(() => Number(route.params.groupId))

/**
 * Экран отвечает на один вопрос: у кого в группе плохо. Поэтому наверху не
 * список из двадцати человек, а те, к кому есть вопрос, — двойки и полное
 * отсутствие оценок. Остальные ниже.
 */
const attention = computed(() => store.needsAttention)
const rest = computed(() => store.rows.filter((row) => !attention.value.includes(row)))

function markTone(row) {
  if (row.failing_count > 0) return 'mobile-cabinet-tag--absent'
  if (!row.has_grades) return 'mobile-cabinet-tag--late'
  return 'mobile-cabinet-tag--present'
}

function markLabel(row) {
  if (!row.has_grades) return 'нет оценок'
  if (row.failing_count > 0) return `двоек: ${row.failing_count}`
  return `балл ${row.average_grade ?? '—'}`
}

onMounted(() => store.loadPerformance(groupId.value))
watch(groupId, (id) => store.loadPerformance(id))
</script>

<template>
  <q-page class="mobile-cabinet-page">
    <button type="button" class="mobile-cabinet-back" @click="router.push(`/m/curator/groups/${groupId}`)">
      <ChevronLeft :size="18" /> К группе
    </button>

    <div v-if="store.performanceLoading" class="mobile-cabinet-loading">
      <q-spinner color="primary" size="32px" /><span>Считаем успеваемость...</span>
    </div>
    <q-banner v-else-if="store.error" class="mobile-cabinet-banner mobile-cabinet-banner--error">{{ store.error }}</q-banner>

    <template v-else-if="store.performance">
      <section class="mobile-cabinet-hero">
        <div class="mobile-cabinet-avatar"><GraduationCap :size="30" /></div>
        <div>
          <p>Успеваемость по журналу</p>
          <h1>{{ store.performance.group.name }}</h1>
          <span>{{ store.summary.grades_count }} оценок за период</span>
        </div>
      </section>

      <section class="mobile-cabinet-card">
        <div class="mobile-cabinet-metrics">
          <article><span>Средний балл</span><strong>{{ store.summary.average_grade ?? '—' }}</strong></article>
          <article><span>С двойками</span><strong>{{ store.summary.with_failing }}</strong></article>
          <article><span>Без оценок</span><strong>{{ store.summary.without_grades }}</strong></article>
          <article><span>Студентов</span><strong>{{ store.summary.students_count }}</strong></article>
        </div>
      </section>

      <section v-if="attention.length" class="mobile-cabinet-card">
        <header><AlertTriangle :size="20" /><h2>Требуют внимания</h2><small>{{ attention.length }}</small></header>
        <ul class="mobile-cabinet-roster">
          <li v-for="row in attention" :key="row.id" class="mobile-cabinet-roster-row">
            <div class="mobile-cabinet-roster-name">
              <strong>{{ row.name }}</strong>
              <small :class="['mobile-cabinet-tag', markTone(row)]">{{ markLabel(row) }}</small>
            </div>
            <span class="mobile-cabinet-roster-note">
              <template v-if="row.recent.length">
                {{ row.recent.map((item) => item.value).join(' · ') }}
              </template>
              <template v-else>Оценок за период нет</template>
            </span>
          </li>
        </ul>
      </section>

      <section class="mobile-cabinet-card">
        <header><GraduationCap :size="20" /><h2>Остальные</h2><small>{{ rest.length }}</small></header>
        <ul v-if="rest.length" class="mobile-cabinet-roster">
          <li v-for="row in rest" :key="row.id" class="mobile-cabinet-roster-row">
            <div class="mobile-cabinet-roster-name">
              <strong>{{ row.name }}</strong>
              <small :class="['mobile-cabinet-tag', markTone(row)]">{{ markLabel(row) }}</small>
            </div>
            <span class="mobile-cabinet-roster-note">{{ row.recent.map((item) => item.value).join(' · ') }}</span>
          </li>
        </ul>
        <p v-else class="mobile-cabinet-empty">Все студенты группы в списке выше.</p>
      </section>

      <section v-if="store.subjects.length" class="mobile-cabinet-card">
        <header><h2>По дисциплинам</h2></header>
        <ul class="mobile-cabinet-roster">
          <li v-for="subject in store.subjects" :key="subject.id || subject.name" class="mobile-cabinet-roster-row">
            <div class="mobile-cabinet-roster-name"><strong>{{ subject.name }}</strong></div>
            <span class="mobile-cabinet-roster-note">
              балл {{ subject.average_grade ?? '—' }} · оценок {{ subject.grades_count }}
              <template v-if="subject.failing_count"> · двоек {{ subject.failing_count }}</template>
            </span>
          </li>
        </ul>
      </section>
    </template>
  </q-page>
</template>
