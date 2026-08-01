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
const deviceProfile = computed(() => {
  if (width.value < 600) return 'phone'
  if (width.value < 1200) return 'tablet'
  if (width.value < 1920) return 'desktop-hd'
  if (width.value < 2560) return 'desktop-fullhd'
  return 'desktop-ultrawide'
})
const isPhone = computed(() => deviceProfile.value === 'phone')
const isTablet = computed(() => deviceProfile.value === 'tablet')
const isMobile = computed(() => isPhone.value || isTablet.value)
const isDesktop = computed(() => !isMobile.value)
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
    deviceProfile,
    isPhone,
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
  deviceProfile,
  isPhone,
  isMobile,
  isTablet,
  isDesktop,
  isUltrawide,
  updateSize,
}
