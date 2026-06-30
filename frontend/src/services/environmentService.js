const ENVIRONMENTS = {
  development: {
    key: 'development',
    label: 'DEV',
    titlePrefix: '[DEV]',
    tooltip: 'Development Environment',
    color: '#f59e0b',
    backgroundColor: '#fffbeb',
    textColor: '#92400e',
  },
  test: {
    key: 'test',
    label: 'TEST',
    titlePrefix: '[TEST]',
    tooltip: 'Test Environment',
    color: '#2563eb',
    backgroundColor: '#eff6ff',
    textColor: '#1d4ed8',
  },
  production: {
    key: 'production',
    label: 'PROD',
    titlePrefix: '',
    tooltip: 'Production Environment',
    color: '#16a34a',
    backgroundColor: '#ecfdf5',
    textColor: '#047857',
  },
}

const DEFAULT_ENVIRONMENT = 'production'

function normalizeEnvironment(value) {
  if (!value || !ENVIRONMENTS[value]) {
    return DEFAULT_ENVIRONMENT
  }

  return value
}

export function getCurrentEnvironment() {
  return ENVIRONMENTS[normalizeEnvironment(import.meta.env.VITE_APP_ENV)]
}

export function getEnvironmentTitle(baseTitle = 'CollegePortal') {
  const environment = getCurrentEnvironment()

  if (!environment.titlePrefix) {
    return baseTitle
  }

  return `${environment.titlePrefix} ${baseTitle}`
}

export function applyEnvironmentTitle(baseTitle = 'CollegePortal') {
  if (typeof document === 'undefined') {
    return
  }

  document.title = getEnvironmentTitle(baseTitle)
}

export function getEnvironmentCssVars() {
  const environment = getCurrentEnvironment()

  return {
    '--cp-environment-color': environment.color,
    '--cp-environment-bg': environment.backgroundColor,
    '--cp-environment-text': environment.textColor,
  }
}

export { ENVIRONMENTS }
