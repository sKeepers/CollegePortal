import { computed, ref, watch } from 'vue'
import { defineStore } from 'pinia'
import { layoutService } from '../services/layoutService'

const WORKSPACE_SETTINGS_KEY = 'collegePortal.workspace.settings'

const workspaceModes = ['default', 'focus', 'wide', 'presentation']
const densities = ['comfortable', 'compact']

const defaultSettings = {
  sidebarWidth: 286,
  menuCollapsed: false,
  rightPanelVisible: true,
  density: 'comfortable',
  workspaceMode: 'default',
}

function canUseLocalStorage() {
  return typeof window !== 'undefined' && Boolean(window.localStorage)
}

function loadSettings() {
  if (!canUseLocalStorage()) {
    return { ...defaultSettings }
  }

  try {
    return {
      ...defaultSettings,
      ...JSON.parse(window.localStorage.getItem(WORKSPACE_SETTINGS_KEY) || '{}'),
    }
  } catch {
    return { ...defaultSettings }
  }
}

function saveSettings(settings) {
  if (!canUseLocalStorage()) {
    return
  }

  window.localStorage.setItem(WORKSPACE_SETTINGS_KEY, JSON.stringify(settings))
}

export const useWorkspaceStore = defineStore('workspace', () => {
  const settings = ref(loadSettings())

  const breakpoint = computed(() => layoutService.breakpoint.value)
  const viewportWidth = computed(() => layoutService.width.value)
  const viewportHeight = computed(() => layoutService.height.value)
  const isMobile = computed(() => layoutService.isMobile.value)
  const isUltrawide = computed(() => layoutService.isUltrawide.value)

  const sidebarWidth = computed(() => {
    if (settings.value.workspaceMode === 'presentation') {
      return 0
    }

    if (settings.value.menuCollapsed) {
      return 78
    }

    return Number(settings.value.sidebarWidth) || defaultSettings.sidebarWidth
  })

  const rightPanelVisible = computed(() => {
    if (settings.value.workspaceMode === 'focus') {
      return false
    }

    return Boolean(settings.value.rightPanelVisible)
  })

  const workspaceClass = computed(() => [
    `workspace-bp-${breakpoint.value}`,
    `workspace-density-${settings.value.density}`,
    `workspace-mode-${settings.value.workspaceMode}`,
    {
      'workspace-mobile': isMobile.value,
      'workspace-ultrawide': isUltrawide.value,
      'workspace-menu-collapsed': settings.value.menuCollapsed,
      'workspace-right-panel-hidden': !rightPanelVisible.value,
    },
  ])

  function setSidebarWidth(width) {
    settings.value.sidebarWidth = Math.min(Math.max(Number(width) || defaultSettings.sidebarWidth, 240), 360)
  }

  function setMenuCollapsed(collapsed) {
    settings.value.menuCollapsed = Boolean(collapsed)
  }

  function toggleMenuCollapsed() {
    settings.value.menuCollapsed = !settings.value.menuCollapsed
  }

  function setRightPanelVisible(visible) {
    settings.value.rightPanelVisible = Boolean(visible)
  }

  function setDensity(density) {
    settings.value.density = densities.includes(density) ? density : defaultSettings.density
  }

  function setWorkspaceMode(mode) {
    settings.value.workspaceMode = workspaceModes.includes(mode) ? mode : defaultSettings.workspaceMode
  }

  function resetWorkspace() {
    settings.value = { ...defaultSettings }
  }

  watch(settings, (value) => saveSettings(value), { deep: true })

  return {
    settings,
    breakpoint,
    viewportWidth,
    viewportHeight,
    isMobile,
    isUltrawide,
    sidebarWidth,
    rightPanelVisible,
    workspaceClass,
    setSidebarWidth,
    setMenuCollapsed,
    toggleMenuCollapsed,
    setRightPanelVisible,
    setDensity,
    setWorkspaceMode,
    resetWorkspace,
  }
})
