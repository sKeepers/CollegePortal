import { computed, onMounted, onUnmounted, ref } from 'vue'

export const BREAKPOINTS = {
  xs: { min: 0, max: 599 },
  sm: { min: 600, max: 1023 },
  md: { min: 1024, max: 1439 },
  lg: { min: 1440, max: 1919 },
  xl: { min: 1920, max: 2559 },
  xxl: { min: 2560, max: Infinity },
}

const width = ref(typeof window !== 'undefined' ? window.innerWidth : 1440)
const height = ref(typeof window !== 'undefined' ? window.innerHeight : 900)

function resolveBreakpoint(value) {
  if (value < 600) {
    return 'xs'
  }

  if (value < 1024) {
    return 'sm'
  }

  if (value < 1440) {
    return 'md'
  }

  if (value < 1920) {
    return 'lg'
  }

  if (value < 2560) {
    return 'xl'
  }

  return 'xxl'
}

function updateSize() {
  if (typeof window === 'undefined') {
    return
  }

  width.value = window.innerWidth
  height.value = window.innerHeight
}

const breakpoint = computed(() => resolveBreakpoint(width.value))
const isMobile = computed(() => ['xs', 'sm'].includes(breakpoint.value))
const isTablet = computed(() => breakpoint.value === 'md')
const isDesktop = computed(() => ['lg', 'xl', 'xxl'].includes(breakpoint.value))
const isUltrawide = computed(() => width.value >= 2560)

export function useLayoutService() {
  onMounted(() => {
    updateSize()
    window.addEventListener('resize', updateSize, { passive: true })
  })

  onUnmounted(() => {
    window.removeEventListener('resize', updateSize)
  })

  return {
    width,
    height,
    breakpoint,
    isMobile,
    isTablet,
    isDesktop,
    isUltrawide,
  }
}

export const layoutService = {
  width,
  height,
  breakpoint,
  isMobile,
  isTablet,
  isDesktop,
  isUltrawide,
  updateSize,
}
