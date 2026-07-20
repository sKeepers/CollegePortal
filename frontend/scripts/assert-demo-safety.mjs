import { existsSync, readFileSync, readdirSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = dirname(fileURLToPath(import.meta.url)) + '/..'
const loginPage = readFileSync(join(root, 'src/pages/auth/LoginPage.vue'), 'utf8')
const devHelper = readFileSync(join(root, 'src/components/auth/DevLoginHelper.vue'), 'utf8')
const legacyApp = readFileSync(join(root, 'src/App.vue'), 'utf8')

function fail(message) {
  console.error(message)
  process.exitCode = 1
}

if (!/email:\s*''/.test(loginPage)) fail('Login email must initialize empty.')
if (!/password:\s*''/.test(loginPage)) fail('Login password must initialize empty.')
if (/admin@college-portal\.local|demo12345/.test(loginPage + legacyApp)) fail('Frontend login source contains forbidden demo credentials.')
if (/email:\s*['"][^'"]+['"]|password:\s*['"][^'"]+['"]/.test(loginPage)) fail('Login page contains forbidden default credentials.')
if (!/const loginForm = reactive\(\{\s*email:\s*''\s*,\s*password:\s*''\s*,?\s*\}\)/.test(legacyApp)) fail('Legacy login form must initialize empty.')
if (/autocomplete="username"|autocomplete="current-password"/.test(loginPage + legacyApp)) fail('Login forms must disable browser credential autocomplete.')
if (!/autocomplete="off"/.test(loginPage)) fail('Login form must disable browser autocomplete.')
if (!/import\.meta\.env\.DEV/.test(loginPage)) fail('DEV helper must be guarded by import.meta.env.DEV.')
if (/password|credential/i.test(devHelper) && /@college-portal\.local|demo12345/.test(devHelper)) fail('DEV helper component must not embed credentials.')

const dist = join(root, 'dist')
if (existsSync(dist)) {
  const stack = [dist]
  while (stack.length) {
    const current = stack.pop()
    for (const entry of readdirSync(current, { withFileTypes: true })) {
      const path = join(current, entry.name)
      if (entry.isDirectory()) stack.push(path)
      if (!entry.isFile() || !/\.(js|html|css)$/.test(entry.name)) continue
      const content = readFileSync(path, 'utf8')
      if (/admin@college-portal\.local|demo12345/.test(content)) fail(`Production artifact contains demo credentials: ${path}`)
      if (/dev-login\/login|DEV быстрый вход|DEV helper/.test(content)) fail(`Production artifact contains DEV helper: ${path}`)
    }
  }
}
