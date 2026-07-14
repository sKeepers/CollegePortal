export async function waitForApi(page, path, method = 'GET') {
  return page.waitForResponse((response) => {
    const request = response.request()
    return request.method() === method && response.url().includes(path)
  })
}
