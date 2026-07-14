export async function login(page, credentials) {
  await page.goto('/login')
  await page.getByLabel('Email').fill(credentials.email)
  await page.getByLabel('Пароль').fill(credentials.password)

  await Promise.all([
    page.waitForURL('**/dashboard'),
    page.getByRole('button', { name: 'Войти' }).click(),
  ])
}

export async function logout(page) {
  await page.evaluate(() => localStorage.clear())
  await page.goto('/login')
}
