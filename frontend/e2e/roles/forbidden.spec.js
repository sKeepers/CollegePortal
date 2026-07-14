import { test } from '@playwright/test'
import { credentialsFor } from '../fixtures/credentials.js'
import { login } from '../helpers/auth.js'
import { assertForbidden } from '../helpers/assertions.js'

test('@smoke запрещенный маршрут показывает 403', async ({ page }) => {
  const credentials = credentialsFor('student')
  test.skip(!credentials, 'Нужны E2E_STUDENT_EMAIL и E2E_STUDENT_PASSWORD')

  await login(page, credentials)
  await page.goto('/admin/settings')
  await assertForbidden(page)
})
