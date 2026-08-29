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
  unknown: {
    key: 'unknown',
    label: 'СРЕДА?',
    titlePrefix: '[?]',
    tooltip: 'Окружение не задано: VITE_APP_ENV пуста или незнакома',
    color: '#6b7280',
    backgroundColor: '#f3f4f6',
    textColor: '#374151',
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

/**
 * Не зная, где он работает, портал говорит «не знаю», а не «боевой».
 *
 * Метка берётся из `VITE_APP_ENV`, заданной при сборке. Умолчанием стояло
 * `production`, и портал, запущенный без этой переменной, показывал зелёное
 * `PROD`: замерено 30.08.2026 — одноразовая база на своём Vite объявила себя
 * боевой. Стенд честен, потому что переменную ему задают, но умолчание
 * страхует ровно тот случай, когда её забыли.
 *
 * Ошибиться можно в обе стороны, и они не равны. «Боевой» на стенде делает
 * человека осторожным там, где осторожность не нужна; «стенд» на боевом делает
 * его беспечным там, где она нужна. Поэтому умолчание не `development`, а
 * отдельное «неизвестно»: оно ни к чему не приглашает и заметно глазом.
 */
const DEFAULT_ENVIRONMENT = 'unknown'

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
