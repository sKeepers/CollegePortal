import { getCurrentEnvironment } from './environmentService'

const DEFAULT_VERSION_INFO = {
  name: 'CollegePortal',
  version: '0.8.0-rc2',
  release: 'v0.8.0-rc2',
  build: 'unknown',
  buildDate: null,
  environment: 'development',
  gitCommit: 'unknown',
  frontendStack: 'Vue 3 + Quasar + Vite',
  backendStack: 'Laravel 12 + PHP 8.4',
  apiVersion: 'v1',
}

let cachedVersion = null

export async function getVersionInfo() {
  if (cachedVersion) {
    return cachedVersion
  }

  try {
    const response = await fetch('/version.json', {
      headers: { Accept: 'application/json' },
      cache: 'no-store',
    })

    if (!response.ok) {
      throw new Error(`Version request failed: ${response.status}`)
    }

    cachedVersion = {
      ...DEFAULT_VERSION_INFO,
      ...(await response.json()),
    }
  } catch (error) {
    cachedVersion = { ...DEFAULT_VERSION_INFO }
  }

  return cachedVersion
}

export function getRuntimeEnvironmentInfo() {
  return getCurrentEnvironment()
}

export function formatVersionDate(value) {
  if (!value) {
    return 'Не указана'
  }

  const date = new Date(`${value}T00:00:00`)

  if (Number.isNaN(date.getTime())) {
    return value
  }

  return new Intl.DateTimeFormat('ru-RU').format(date)
}

export function presentVersionInfo(value) {
  const version = { ...DEFAULT_VERSION_INFO, ...(value || {}) }
  const environment = getRuntimeEnvironmentInfo()

  return {
    ...version,
    environmentInfo: environment,
    buildDateLabel: formatVersionDate(version.buildDate),
    environmentLabel: environment.label,
  }
}
