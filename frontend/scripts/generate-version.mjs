import { execSync } from 'node:child_process'
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, isAbsolute, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = dirname(fileURLToPath(import.meta.url))
const repoPath = resolve(__dirname, '../..')
const publicPath = resolve(__dirname, '../public/version.json')

function gitValue(command) {
  try {
    return execSync(command, { cwd: repoPath, encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim() || null
  } catch {
    return null
  }
}

function readText(path) {
  return existsSync(path) ? readFileSync(path, 'utf8').trim() : null
}

function gitDirectory() {
  const configuredGitDir = process.env.VITE_BUILD_GIT_DIR
  if (configuredGitDir && existsSync(configuredGitDir)) {
    return configuredGitDir
  }

  const gitPath = resolve(repoPath, '.git')

  if (!existsSync(gitPath)) {
    return null
  }

  const gitPointer = readText(gitPath)

  if (gitPointer?.startsWith('gitdir:')) {
    const target = gitPointer.slice('gitdir:'.length).trim()

    return isAbsolute(target) ? target : resolve(repoPath, target)
  }

  return gitPath
}

function packedRef(gitDir, refName) {
  const refs = readText(resolve(gitDir, 'packed-refs'))

  if (!refs) {
    return null
  }

  return refs
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter((line) => line && !line.startsWith('#') && !line.startsWith('^'))
    .map((line) => line.split(/\s+/))
    .find(([, ref]) => ref === refName)?.[0] || null
}

function gitHeadCommit() {
  const gitDir = gitDirectory()

  if (!gitDir) {
    return null
  }

  const head = readText(resolve(gitDir, 'HEAD'))

  if (!head) {
    return null
  }

  if (!head.startsWith('ref:')) {
    return head
  }

  const refName = head.slice('ref:'.length).trim()

  return readText(resolve(gitDir, refName)) || packedRef(gitDir, refName)
}

const detectedCommit = gitValue('git rev-parse HEAD') || gitHeadCommit()
const fullCommit = process.env.VITE_BUILD_FULL_COMMIT || detectedCommit || 'unknown'
const commit = process.env.VITE_BUILD_COMMIT || (fullCommit === 'unknown' ? 'unknown' : fullCommit.slice(0, 12))
const buildDate = process.env.VITE_BUILD_DATE || new Date().toISOString().slice(0, 10)
const environment = process.env.VITE_APP_ENV || process.env.APP_ENV || 'development'
const version = process.env.VITE_APP_VERSION || process.env.APP_VERSION || '0.8.0-rc2'
const release = process.env.VITE_APP_RELEASE || process.env.APP_RELEASE || 'v0.8.0-rc2'

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
