import { getCurrentEnvironment } from './environmentService'

const DEFAULT_VERSION_INFO = {
  name: 'CollegePortal',
  version: '0.7.0-dev',
  release: 'Release 0.7',
  build: 'unknown',
  buildDate: null,
  environment: 'development',
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
