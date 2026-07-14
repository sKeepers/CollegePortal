import { test, expect } from '@playwright/test'
import { credentialsFor } from '../fixtures/credentials.js'
import { login } from '../helpers/auth.js'

test('@smoke анонимный пользователь перенаправляется на вход', async ({ page }) => {
  await page.goto('/dashboard')
  await expect(page).toHaveURL(/\/login$/)
})

test('@smoke страница входа не создает горизонтальную прокрутку', async ({ page }) => {
  await page.goto('/login')
  const hasOverflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth)
  expect(hasOverflow).toBe(false)
})

test('@smoke авторизованный Dashboard открывается', async ({ page }) => {
  const credentials = credentialsFor('admin')
  test.skip(!credentials, 'Нужны E2E_ADMIN_EMAIL и E2E_ADMIN_PASSWORD')

  await login(page, credentials)
  await expect(page).toHaveURL(/\/dashboard$/)
})
