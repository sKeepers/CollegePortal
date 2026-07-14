import { expect } from '@playwright/test'

export async function assertForbidden(page) {
  await expect(page).toHaveURL(/\/forbidden$/)
  await expect(page.getByRole('heading', { name: 'Недостаточно прав' })).toBeVisible()
  await expect(page.getByText('403', { exact: true })).toBeVisible()
}

export async function assertMenuPermission(page, label, allowed) {
  const item = page.getByText(label, { exact: true })
  if (allowed) {
    await expect(item).toBeVisible()
  } else {
    await expect(item).toHaveCount(0)
  }
}
