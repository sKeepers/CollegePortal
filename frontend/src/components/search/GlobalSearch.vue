<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import {
  ArrowRight,
  BookOpen,
  Clock3,
  GraduationCap,
  Search,
  UserRound,
  UsersRound,
  X,
} from '@lucide/vue'
import { useSearchStore } from '../../stores/search'

const router = useRouter()
const store = useSearchStore()
const localQuery = ref(store.query)
const menuOpen = ref(false)
const searchInputRef = ref(null)
let debounceTimer = null

const queryText = computed(() => String(localQuery.value || '').trim())
const hasQuery = computed(() => queryText.value.length > 0)
const canSearch = computed(() => queryText.value.length >= 2)
const showHistory = computed(() => !hasQuery.value && store.history.length > 0)
const showEmpty = computed(() => canSearch.value && !store.loading && !store.error && store.results.length === 0)

const resultIcons = {
  student: GraduationCap,
  group: UsersRound,
  teacher: UserRound,
  subject: BookOpen,
}

function iconFor(result) {
  return resultIcons[result.type] || Search
}

function scheduleSearch() {
  window.clearTimeout(debounceTimer)

  if (!canSearch.value) {
    store.clearResults()
    return
  }

  debounceTimer = window.setTimeout(() => {
    store.performSearch(localQuery.value)
  }, 280)
}

function openMenu() {
  menuOpen.value = true

  if (canSearch.value && !store.results.length) {
    scheduleSearch()
  }
}

async function focusInput() {
  await nextTick()
  const focus = () => searchInputRef.value?.focus?.()
  window.requestAnimationFrame(focus)
  window.setTimeout(focus, 50)
}

async function openSearch() {
  openMenu()
  await focusInput()
}

function closeSearch() {
  menuOpen.value = false
}

function clearSearch() {
  localQuery.value = ''
  store.setQuery('')
  store.clearResults()
  closeSearch()
}

async function selectResult(result) {
  store.selectResult(result)
  menuOpen.value = false

  if (result?.route) {
    await router.push(result.route)
  }
}

function useHistoryItem(item) {
  localQuery.value = item
  store.setQuery(item)
  menuOpen.value = true
  scheduleSearch()
}

function openFirstResult() {
  const first = store.results[0]

  if (first) {
    selectResult(first)
  }
}

function handleGlobalKeydown(event) {
  const key = event.key?.toLowerCase()
  const isSearchShortcut = (event.ctrlKey || event.metaKey) && key === 'k'

  if (isSearchShortcut) {
    event.preventDefault()
    event.stopPropagation()
    event.stopImmediatePropagation?.()
    openSearch()
    return
  }

  if (key === 'escape' && menuOpen.value) {
    event.preventDefault()
    event.stopPropagation()
    event.stopImmediatePropagation?.()
    closeSearch()
  }
}

watch(localQuery, (value) => {
  store.setQuery(value)
  menuOpen.value = true
  scheduleSearch()
})

onMounted(() => {
  window.addEventListener('keydown', handleGlobalKeydown, true)
})

onBeforeUnmount(() => {
  window.clearTimeout(debounceTimer)
  window.removeEventListener('keydown', handleGlobalKeydown, true)
})
</script>

<template>
  <div class="global-search">
    <button
      type="button"
      class="global-search__trigger"
      aria-label="Открыть поиск по порталу"
      @click="openSearch"
      @keydown.enter.prevent.stop="openSearch"
      @keydown.space.prevent.stop="openSearch"
    >
      <Search :size="17" />
      <span>{{ localQuery || 'Поиск по порталу' }}</span>
      <kbd>Ctrl K</kbd>
    </button>

    <q-menu
      v-model="menuOpen"
      no-parent-event
      anchor="bottom left"
      self="top left"
      class="global-search__menu"
      :offset="[0, 6]"
    >
      <div class="global-search__panel">
        <q-input
          ref="searchInputRef"
          v-model="localQuery"
          dense
          outlined
          clearable
          debounce="0"
          placeholder="Поиск по порталу"
          class="global-search__input"
          @clear="clearSearch"
          @keydown.enter.prevent="openFirstResult"
          @keydown.esc.prevent.stop="closeSearch"
        >
          <template #prepend>
            <Search :size="17" />
          </template>
        </q-input>

        <div v-if="showHistory" class="global-search__section">
          <div class="global-search__section-title">
            <span>Последние запросы</span>
            <q-btn flat dense round title="Очистить историю" @click="store.clearHistory">
              <X :size="14" />
            </q-btn>
          </div>
          <button
            v-for="item in store.history"
            :key="item"
            type="button"
            class="global-search__history-item"
            @click="useHistoryItem(item)"
          >
            <Clock3 :size="15" />
            <span>{{ item }}</span>
          </button>
        </div>

        <div v-else-if="!canSearch" class="global-search__hint">
          Введите минимум 2 символа для поиска.
        </div>

        <div v-else-if="store.loading" class="global-search__hint">
          Идет поиск...
        </div>

        <div v-else-if="store.error" class="global-search__error">
          {{ store.error }}
        </div>

        <div v-else-if="showEmpty" class="global-search__hint">
          Ничего не найдено.
        </div>

        <template v-else>
          <section
            v-for="group in store.groupedResults"
            :key="group.label"
            class="global-search__section"
          >
            <div class="global-search__section-title">
              <span>{{ group.label }}</span>
            </div>

            <button
              v-for="result in group.items"
              :key="`${result.type}-${result.id}`"
              type="button"
              class="global-search__result"
              @click="selectResult(result)"
            >
              <span class="global-search__result-icon">
                <component :is="iconFor(result)" :size="17" />
              </span>
              <span class="global-search__result-text">
                <strong>{{ result.title }}</strong>
                <small>{{ result.subtitle }}</small>
                <em v-if="result.meta?.length">{{ result.meta.slice(0, 2).join(' · ') }}</em>
              </span>
              <ArrowRight :size="16" />
            </button>
          </section>
        </template>
      </div>
    </q-menu>
  </div>
</template>
