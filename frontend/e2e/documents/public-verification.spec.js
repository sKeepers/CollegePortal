import { test, expect } from '@playwright/test'

test('@smoke публичная проверка документа открывается анонимно', async ({ page }) => {
  const path = process.env.E2E_PUBLIC_DOCUMENT_PATH
  test.skip(!path, 'Document Engine еще не в develop; задайте E2E_PUBLIC_DOCUMENT_PATH после merge')

  await page.goto(path)
  await expect(page).not.toHaveURL(/\/login$/)
})
