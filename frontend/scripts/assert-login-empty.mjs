import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'

const root = join(dirname(fileURLToPath(import.meta.url)), '..')
const files = [
  join(root, 'src/pages/auth/LoginPage.vue'),
  join(root, 'src/App.vue'),
]

const forbiddenPatterns = [
  { pattern: /admin@college-portal\.local/i, message: 'hardcoded admin email' },
  { pattern: /password:\s*['"]password['"]/i, message: 'hardcoded default password' },
  { pattern: /email:\s*['"][^'"]+@[^'"]+['"]/i, message: 'non-empty default email' },
  { pattern: /autocomplete=['"](?:username|current-password)['"]/i, message: 'browser credential autocomplete enabled' },
  { pattern: /localStorage\.(?:getItem|setItem)\([^)]*(?:login|email|password|credential)/i, message: 'credential value in localStorage' },
  { pattern: /console\.log\([^\n]*(?:login|email|password|credential)/i, message: 'credential value written to console' },
]

let failed = false

for (const file of files) {
  const source = readFileSync(file, 'utf8')
  for (const { pattern, message } of forbiddenPatterns) {
    if (pattern.test(source)) {
      console.error(`[login-regression] ${message}: ${file}`)
      failed = true
    }
  }

  if (!/email:\s*['"]['"]/.test(source) || !/password:\s*['"]['"]/.test(source)) {
    console.error(`[login-regression] login form must initialize email and password as empty strings: ${file}`)
    failed = true
  }
}

if (failed) {
  process.exit(1)
}

console.log('[login-regression] Login forms initialize empty and do not enable credential autofill.')
