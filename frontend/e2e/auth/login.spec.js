import { test, expect } from '@playwright/test'

test('@smoke страница входа открывается', async ({ page }) => {
  await page.goto('/login')

  await expect(page.getByText('CollegePortal', { exact: true })).toBeVisible()
  await expect(page.getByLabel('Email')).toBeVisible()
  await expect(page.getByLabel('Пароль')).toBeVisible()
  await expect(page.getByRole('button', { name: 'Войти' })).toBeVisible()
})

test('@smoke неверный вход показывает русскую ошибку', async ({ page }) => {
  await page.route('**/api/auth/login', async (route) => {
    await route.fulfill({
      status: 422,
      contentType: 'application/json',
      body: JSON.stringify({ message: 'Неверный email или пароль.' }),
    })
  })

  await page.goto('/login')
  await page.getByLabel('Email').fill('invalid@example.test')
  await page.getByLabel('Пароль').fill('invalid-password')
  await page.getByRole('button', { name: 'Войти' }).click()

  await expect(page.getByText('Неверный email или пароль.')).toBeVisible()
})
