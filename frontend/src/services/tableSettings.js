export const TABLE_ROWS_PER_PAGE_OPTIONS = [10, 20, 50, 0]

function canUseLocalStorage() {
  return typeof window !== 'undefined' && Boolean(window.localStorage)
}

export function loadRowsPerPage(key, fallback = 20) {
  if (!canUseLocalStorage()) {
    return fallback
  }

  const storedValue = window.localStorage.getItem(key)

  if (storedValue === 'all') {
    return 0
  }

  const value = Number(storedValue)

  return TABLE_ROWS_PER_PAGE_OPTIONS.includes(value) ? value : fallback
}

export function saveRowsPerPage(key, rowsPerPage) {
  if (!canUseLocalStorage()) {
    return
  }

  window.localStorage.setItem(key, Number(rowsPerPage) === 0 ? 'all' : String(rowsPerPage))
}

export function createTablePagination(key, options = {}) {
  return {
    sortBy: options.sortBy || null,
    descending: Boolean(options.descending),
    page: options.page || 1,
    rowsPerPage: loadRowsPerPage(key, options.rowsPerPage || 20),
  }
}

export function persistTablePagination(key, pagination) {
  saveRowsPerPage(key, pagination?.rowsPerPage ?? 20)
}
