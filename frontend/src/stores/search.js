import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { searchService } from '../services/searchService'

const HISTORY_KEY = 'collegePortal.globalSearch.history'
const MAX_HISTORY_ITEMS = 8

function readHistory() {
  if (typeof localStorage === 'undefined') {
    return []
  }

  try {
    const parsed = JSON.parse(localStorage.getItem(HISTORY_KEY) || '[]')
    return Array.isArray(parsed) ? parsed.filter(Boolean).slice(0, MAX_HISTORY_ITEMS) : []
  } catch {
    return []
  }
}

function writeHistory(items) {
  if (typeof localStorage === 'undefined') {
    return
  }

  localStorage.setItem(HISTORY_KEY, JSON.stringify(items.slice(0, MAX_HISTORY_ITEMS)))
}

export const useSearchStore = defineStore('search', () => {
  const query = ref('')
  const results = ref([])
  const history = ref(readHistory())
  const selectedResult = ref(null)
  const loading = ref(false)
  const error = ref('')

  const groupedResults = computed(() => {
    const groups = []

    results.value.forEach((result) => {
      let group = groups.find((item) => item.label === result.group)

      if (!group) {
        group = {
          label: result.group,
          items: [],
        }
        groups.push(group)
      }

      group.items.push(result)
    })

    return groups
  })

  function setQuery(value) {
    query.value = value || ''
  }

  function clearResults() {
    results.value = []
    error.value = ''
  }

  function rememberSearch(value = query.value) {
    const text = String(value || '').trim()

    if (text.length < 2) {
      return
    }

    history.value = [
      text,
      ...history.value.filter((item) => item.toLowerCase() !== text.toLowerCase()),
    ].slice(0, MAX_HISTORY_ITEMS)

    writeHistory(history.value)
  }

  function clearHistory() {
    history.value = []
    writeHistory([])
  }

  async function performSearch(value = query.value) {
    const text = String(value || '').trim()
    query.value = value || ''

    if (text.length < 2) {
      clearResults()
      return []
    }

    loading.value = true
    error.value = ''

    try {
      const found = await searchService.search(text)
      results.value = found
      rememberSearch(text)
      return found
    } catch (err) {
      error.value = err.message || 'Не удалось выполнить поиск'
      results.value = []
      return []
    } finally {
      loading.value = false
    }
  }

  function selectResult(result) {
    selectedResult.value = result || null
    rememberSearch()
  }

  return {
    query,
    results,
    groupedResults,
    history,
    selectedResult,
    loading,
    error,
    setQuery,
    clearResults,
    clearHistory,
    performSearch,
    selectResult,
  }
})
