import { execSync } from 'node:child_process'
import { mkdirSync, writeFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = dirname(fileURLToPath(import.meta.url))
const publicPath = resolve(__dirname, '../public/version.json')

function gitValue(command, fallback = 'unknown') {
  try {
    return execSync(command, { cwd: resolve(__dirname, '../..'), encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim() || fallback
  } catch {
    return fallback
  }
}

const commit = process.env.VITE_BUILD_COMMIT || gitValue('git rev-parse --short=12 HEAD')
const fullCommit = process.env.VITE_BUILD_FULL_COMMIT || gitValue('git rev-parse HEAD', commit)
const buildDate = process.env.VITE_BUILD_DATE || new Date().toISOString().slice(0, 10)
const environment = process.env.VITE_APP_ENV || process.env.APP_ENV || 'development'
const version = process.env.VITE_APP_VERSION || process.env.APP_VERSION || 'unknown'
const release = process.env.VITE_APP_RELEASE || process.env.APP_RELEASE || 'unknown'

const payload = {
  name: 'CollegePortal',
  version,
  release,
  build: commit,
  gitCommit: fullCommit,
  buildDate,
  environment,
  frontendStack: 'Vue 3 + Quasar + Vite',
  backendStack: 'Laravel 12 + PHP 8.4',
  apiVersion: 'v1',
}

mkdirSync(dirname(publicPath), { recursive: true })
writeFileSync(publicPath, `${JSON.stringify(payload, null, 2)}\n`, 'utf8')
