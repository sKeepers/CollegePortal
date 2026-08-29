<script setup>
import { computed } from 'vue'
import { usePermissions } from '../../composables/usePermissions'
import AppEmptyState from '../../components/ui/AppEmptyState.vue'
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'
import PersonPhotoManager from '../../components/person/PersonPhotoManager.vue'
import WorkspacePanel from '../../components/workspace/WorkspacePanel.vue'
import PersonAccountActions from '../../components/identity/PersonAccountActions.vue'
import { formatPhone } from '../../utils/phone'


// Переход к действиям над картой показывается под тем же правом, под которым
// действия живут в реестре: `rfid.cards.manage`. Видеть карту и менять её —
// разные вещи, и обещать кнопкой то, чего человек не сможет, нельзя.
const auth = usePermissions()
const canManageCards = computed(() => auth.can('rfid.cards.manage'))

const props = defineProps({
  teacher: { type: Object, default: null },
  subjects: { type: Array, default: () => [] },
  lessons: { type: Array, default: () => [] },
})

function fullName(teacher) {
  return [teacher?.last_name, teacher?.first_name, teacher?.middle_name].filter(Boolean).join(' ')
}

const teacherName = computed(() => fullName(props.teacher) || 'Преподаватель')
const statusLabel = computed(() => (props.teacher?.is_active ? 'Активен' : 'Неактивен'))
const statusTone = computed(() => (props.teacher?.is_active ? 'success' : 'neutral'))
const scheduleLink = computed(() => ({ path: '/schedule', query: { teacher: props.teacher?.id } }))
const journalLink = computed(() => ({ path: '/journal', query: { teacher: props.teacher?.id } }))
const subjectsLink = computed(() => ({ path: '/subjects', query: { teacher: props.teacher?.id } }))
const loadLink = computed(() => ({ path: '/teaching-load', query: { teacher: props.teacher?.id } }))
const passLink = computed(() => ({ path: '/identity/digital-passes', query: { owner: 'teacher', selected: props.teacher?.id } }))
const accessLink = computed(() => ({ path: '/access/reports', query: { type: 'teacher', q: teacherName.value } }))
const teacherMetrics = computed(() => [
  { label: 'Отделение', value: props.teacher?.department || '—' },
  { label: 'Дисциплин', value: props.subjects.length },
  { label: 'Часов нагрузки', value: '—' },
  { label: 'Занятий', value: props.lessons.length },
])
const teacherActions = computed(() => [
  { label: 'Расписание', to: scheduleLink.value },
  { label: 'Нагрузка', to: loadLink.value },
  { label: 'Журнал', to: journalLink.value },
  { label: 'Цифровой пропуск', to: passLink.value },
  { label: 'История проходов', to: accessLink.value },
])
const teacherEvents = computed(() => props.lessons.slice(0, 3).map((lesson) => ({
  id: lesson.id,
  title: lesson.subject?.name || 'Занятие',
  description: [lesson.group?.name, lesson.date, lesson.start_time].filter(Boolean).join(' · ') || 'Детали занятия не указаны',
})))

function updatePhoto(payload) {
  if (props.teacher) {
    props.teacher.photo_url = payload.photo_url
    props.teacher.photo_path = payload.photo_path
  }
}
function removePhoto() {
  if (props.teacher) {
    props.teacher.photo_url = null
    props.teacher.photo_path = null
  }
}
</script>

<template>
  <AppEmptyState v-if="!teacher" title="Преподаватель не выбран" description="Выберите строку в таблице, чтобы открыть карточку преподавателя." />

  <WorkspacePanel
    v-else
    class="teacher-details-card"
    :title="teacherName"
    :subtitle="teacher.position || 'Должность не указана'"
    :metrics="teacherMetrics"
    :events="teacherEvents"
    :actions="teacherActions"
  >
    <template #photo><PersonPhotoManager type="teachers" :person="teacher" compact @updated="updatePhoto" @removed="removePhoto" /></template>
    <template #status>
      <AppStatusBadge :label="statusLabel" :tone="statusTone" />
      <AppStatusBadge :label="teacher.department || 'Отделение не указано'" tone="info" />
      <PersonAccountActions profile-type="teacher" :profile-id="teacher.id" :has-account="Boolean(teacher.user_id)" />
    </template>

    <div class="teacher-details">
      <section class="teacher-details__section">
        <h3>Контакты</h3>
        <dl class="teacher-details__list">
          <div><dt>Телефон</dt><dd>{{ formatPhone(teacher.phone, "—") }}</dd></div>
          <div><dt>Email</dt><dd>{{ teacher.email || '—' }}</dd></div>
          <!--
            Карта показывается списком, а не одним полем: у четверых людей их
            две-три (замер 29.08.2026), и «номер карты» соврал бы на первой же
            такой карточке. Состояние стоит рядом с номером — у кого карту
            забрали, не должен видеть её действующей.

            Поля нет вовсе, если у смотрящего нет права `rfid.cards.view`:
            отсутствие поля честнее прочерка, который читался бы как «карты
            нет».
          -->
          <div v-if="teacher.rfid_cards"><dt>Карта СКУД</dt><dd>
            <template v-if="teacher.rfid_cards.length">
              <!--
                Номер и состояние — разными строками, и между картами отступ.
                В одну строку они не влезают: колонка значения узкая, и
                «9799887766 (основная) — На руках» рвалось на три строки, а
                три карты подряд читались кашей. Замечено глазами 29.08.2026;
                в разметке этого не видно.
              -->
              <div v-for="card in teacher.rfid_cards" :key="card.id" class="q-mb-xs">
                <div style="white-space: nowrap">{{ card.uid }}</div>
                <div class="text-caption text-grey-7">
                  {{ card.status_label || card.status }}<template v-if="card.label"> · {{ card.label }}</template>
                </div>
                <router-link
                  v-if="canManageCards"
                  class="text-caption"
                  :to="{ path: '/identity/rfid-cards', query: { card: card.uid } }">что сделать</router-link>
              </div>
            </template>
            <template v-else>не привязана</template>
          </dd></div>
          <div><dt>Отделение</dt><dd>{{ teacher.department || '—' }}</dd></div>
          <div><dt>Должность</dt><dd>{{ teacher.position || '—' }}</dd></div>
        </dl>
      </section>

      <section class="teacher-details__section">
        <h3>Дисциплины</h3>
        <div v-if="subjects.length" class="teacher-details__tags">
          <q-chip v-for="subject in subjects.slice(0, 6)" :key="subject.id" dense>{{ subject.name }}</q-chip>
        </div>
        <p v-else class="teacher-details__muted">Связанные дисциплины пока не найдены.</p>
      </section>

      <section class="teacher-details__section">
        <h3>Группы и занятия</h3>
        <div v-if="lessons.length" class="teacher-details__lesson-list">
          <div v-for="lesson in lessons.slice(0, 4)" :key="lesson.id">
            <strong>{{ lesson.subject?.name || 'Занятие' }}</strong>
            <span>{{ lesson.group?.name || 'Группа не указана' }} · {{ lesson.date || 'Дата не указана' }} · {{ lesson.start_time || '—' }}</span>
          </div>
        </div>
        <p v-else class="teacher-details__muted">Связанные занятия пока не найдены.</p>
      </section>
    </div>
  </WorkspacePanel>
</template>
