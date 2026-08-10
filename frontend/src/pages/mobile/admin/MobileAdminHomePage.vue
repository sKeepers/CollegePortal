<script setup>
import { nextTick, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { Bell, Check, ChevronRight, Search, ShieldCheck, UserRound, X } from '@lucide/vue'
import { useMobileAdminStore } from '../../../stores/mobileAdmin'

const store = useMobileAdminStore()
const route = useRoute()
const comment = ref({})

function personName(person) {
  return [person.last_name, person.first_name, person.middle_name].filter(Boolean).join(' ') || 'Без имени'
}

async function decide(request, approved) {
  const ok = await store.decideJournalRequest(request.requestId, approved, comment.value[request.requestId] || '')
  if (ok) delete comment.value[request.requestId]
}

function scrollToSection() {
  const id = route.hash.slice(1)
  if (!id) return
  nextTick(() => document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' }))
}

onMounted(async () => {
  await store.load()
  scrollToSection()
})

watch(() => route.hash, scrollToSection)
</script>

<template>
  <q-page class="mobile-cabinet-page">
    <div v-if="store.loading" class="mobile-cabinet-loading"><q-spinner color="primary" size="32px" /><span>Загрузка кабинета...</span></div>

    <template v-else>
      <section class="mobile-cabinet-hero">
        <div class="mobile-cabinet-avatar"><ShieldCheck :size="30" /></div>
        <div>
          <p>Кабинет администратора</p>
          <h1>Сводка колледжа</h1>
          <span>{{ store.inboxTotal }} входящих</span>
        </div>
      </section>

      <q-banner v-if="store.error" class="mobile-cabinet-banner mobile-cabinet-banner--error">{{ store.error }}</q-banner>

      <section class="mobile-cabinet-card">
        <header><h2>Показатели</h2></header>
        <div class="mobile-cabinet-metrics">
          <article><span>Студентов</span><strong>{{ store.counts.students }}</strong></article>
          <article><span>Преподавателей</span><strong>{{ store.counts.teachers }}</strong></article>
          <article><span>Групп</span><strong>{{ store.counts.groups }}</strong></article>
          <article><span>Учётных записей</span><strong>{{ store.counts.users }}</strong></article>
        </div>

        <template v-if="store.today">
          <header><h2>Сегодня</h2></header>
          <div class="mobile-cabinet-metrics">
            <article><span>Студентов в здании</span><strong>{{ store.today.students.inside_now }}</strong></article>
            <article><span>Студентов опоздало</span><strong>{{ store.today.students.late }}</strong></article>
            <article><span>Преподавателей в здании</span><strong>{{ store.today.teachers.inside_now }}</strong></article>
            <article><span>Преподавателей нет</span><strong>{{ store.today.teachers.absent }}</strong></article>
          </div>
        </template>
      </section>

      <section class="mobile-cabinet-card" id="inbox">
        <header><Bell :size="20" /><h2>Входящие</h2><small>{{ store.inboxTotal }}</small></header>

        <p v-if="!store.inboxTotal" class="mobile-cabinet-empty">Ничего не ждёт решения.</p>

        <!-- Запрос на переоткрытие журнала решается прямо здесь: критерий
             задачи — чтобы с телефона он закрывался целиком, а не открывался
             для просмотра. -->
        <div v-for="request in store.journalRequests" :key="request.id" class="mobile-cabinet-request">
          <strong>{{ request.title }}</strong>
          <span>{{ request.description }}</span>
          <em v-if="request.reason">«{{ request.reason }}»<template v-if="request.requestedBy"> — {{ request.requestedBy }}</template></em>
          <q-input v-model="comment[request.requestId]" outlined dense autogrow placeholder="Комментарий к решению (необязательно)" />
          <div class="mobile-cabinet-decision">
            <q-btn color="positive" no-caps :loading="store.deciding === request.requestId" @click="decide(request, true)">
              <Check :size="16" class="q-mr-xs" /> Разрешить
            </q-btn>
            <q-btn outline color="negative" no-caps :loading="store.deciding === request.requestId" @click="decide(request, false)">
              <X :size="16" class="q-mr-xs" /> Отклонить
            </q-btn>
          </div>
        </div>

        <div v-if="store.otherInbox.length" class="mobile-cabinet-lessons">
          <RouterLink v-for="item in store.otherInbox" :key="item.id" :to="item.to" class="mobile-cabinet-lesson">
            <div class="mobile-cabinet-lesson-body">
              <strong>{{ item.title }}</strong>
              <span>{{ item.description }}</span>
            </div>
            <ChevronRight :size="18" />
          </RouterLink>
        </div>
      </section>

      <section v-if="store.abilities.search_people" class="mobile-cabinet-card" id="search">
        <header><Search :size="20" /><h2>Поиск человека</h2></header>
        <q-input
          v-model="store.search"
          outlined
          dense
          clearable
          placeholder="Фамилия, телефон или почта"
          @keyup.enter="store.findPeople"
          @clear="store.people = []"
        >
          <template #append>
            <q-btn flat dense round :loading="store.searching" aria-label="Найти" @click="store.findPeople"><Search :size="18" /></q-btn>
          </template>
        </q-input>

        <ul v-if="store.people.length" class="mobile-cabinet-roster">
          <li v-for="person in store.people" :key="person.id" class="mobile-cabinet-roster-row">
            <div class="mobile-cabinet-roster-name"><UserRound :size="16" /><strong>{{ personName(person) }}</strong></div>
            <div class="mobile-cabinet-contacts">
              <a v-if="person.phone" :href="`tel:${person.phone}`">{{ person.phone }}</a>
              <a v-if="person.email" :href="`mailto:${person.email}`">{{ person.email }}</a>
              <!-- `selected` — тот же параметр, которым карточку открывает
                   раздел «Люди»: свой способ ссылаться на человека заводить
                   незачем. -->
              <RouterLink :to="{ path: '/people', query: { selected: person.id } }">Карточка</RouterLink>
            </div>
          </li>
        </ul>
        <p v-else-if="store.searched && !store.searching" class="mobile-cabinet-empty">Никого не нашлось.</p>
      </section>
    </template>
  </q-page>
</template>
