import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

function canUseLocalStorage() {
  try {
    return typeof window !== 'undefined' && Boolean(window.localStorage)
  } catch {
    return false
  }
}

export function useResizableWorkspace({
  storageKey,
  defaultDetailsWidth = 400,
  minDetailsWidth = 340,
  maxDetailsWidth = 640,
  minListWidth = 520,
  splitterWidth = 10,
  resizeBodyClass = 'workspace-splitter-resizing',
  mobileBreakpoint = 1100,
} = {}) {
  const workspaceRef = ref(null)
  const detailsWidth = ref(loadWidth())
  const resizing = ref(false)
  const viewportWidth = ref(typeof window !== 'undefined' ? window.innerWidth : 1440)

  const workspaceStyle = computed(() => {
    if (viewportWidth.value <= mobileBreakpoint) {
      return {}
    }

    return {
      gridTemplateColumns: `minmax(0, 1fr) ${splitterWidth}px minmax(${minDetailsWidth}px, ${detailsWidth.value}px)`,
    }
  })

  function loadWidth() {
    if (!storageKey || !canUseLocalStorage()) return defaultDetailsWidth

    const value = Number(window.localStorage.getItem(storageKey))
    return Number.isFinite(value) ? Math.min(Math.max(value, minDetailsWidth), maxDetailsWidth) : defaultDetailsWidth
  }

  function saveWidth(width) {
    if (storageKey && canUseLocalStorage()) {
      window.localStorage.setItem(storageKey, String(width))
    }
  }

  function resetSplitter() {
    detailsWidth.value = defaultDetailsWidth
    saveWidth(defaultDetailsWidth)
  }

  function stopResize() {
    if (!resizing.value) return
    resizing.value = false
    window.removeEventListener('pointermove', onResize)
    window.removeEventListener('pointerup', stopResize)
    document.body.classList.remove(resizeBodyClass)
  }

  function updateViewportWidth() {
    if (typeof window === 'undefined') return
    viewportWidth.value = window.innerWidth
    if (!workspaceRef.value || viewportWidth.value <= mobileBreakpoint) return

    const rect = workspaceRef.value.getBoundingClientRect()
    const allowedMaxWidth = Math.max(minDetailsWidth, Math.min(maxDetailsWidth, rect.width - minListWidth - splitterWidth))
    if (detailsWidth.value > allowedMaxWidth) {
      detailsWidth.value = allowedMaxWidth
      saveWidth(detailsWidth.value)
    }
  }

  function onResize(event) {
    if (!resizing.value || !workspaceRef.value) return

    const rect = workspaceRef.value.getBoundingClientRect()
    const allowedMaxWidth = Math.max(minDetailsWidth, Math.min(maxDetailsWidth, rect.width - minListWidth - splitterWidth))
    const nextWidth = Math.min(Math.max(rect.right - event.clientX, minDetailsWidth), allowedMaxWidth)
    detailsWidth.value = Math.round(nextWidth)
    saveWidth(detailsWidth.value)
  }

  function startResize(event) {
    if (typeof window !== 'undefined' && window.innerWidth <= mobileBreakpoint) return
    resizing.value = true
    event.currentTarget.setPointerCapture?.(event.pointerId)
    document.body.classList.add(resizeBodyClass)
    window.addEventListener('pointermove', onResize)
    window.addEventListener('pointerup', stopResize)
  }

  onMounted(() => {
    updateViewportWidth()
    window.addEventListener('resize', updateViewportWidth)
  })

  onBeforeUnmount(() => {
    stopResize()
    if (typeof window !== 'undefined') {
      window.removeEventListener('resize', updateViewportWidth)
    }
  })

  return {
    detailsWidth,
    resizing,
    resetSplitter,
    startResize,
    stopResize,
    workspaceRef,
    workspaceStyle,
  }
}
