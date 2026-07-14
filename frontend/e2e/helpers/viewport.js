export async function useMobileViewport(page) {
  await page.setViewportSize({ width: 390, height: 844 })
}
