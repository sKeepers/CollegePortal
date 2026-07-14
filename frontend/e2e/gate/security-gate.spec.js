import { test, expect } from '@playwright/test'
import { credentialsFor } from '../fixtures/credentials.js'
import { login } from '../helpers/auth.js'

test('@smoke сотрудник проходной открывает сканер', async ({ page }) => {
  const credentials = credentialsFor('security')
  test.skip(!credentials, 'Нужны E2E_SECURITY_EMAIL и E2E_SECURITY_PASSWORD')

  await login(page, credentials)
  await page.goto('/access/gate')
  await expect(page).toHaveURL(/\/access\/gate$/)
})
