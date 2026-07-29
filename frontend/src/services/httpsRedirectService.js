const LOCAL_HOSTS = new Set(['localhost', '127.0.0.1', '::1'])

export function enforceHttpsRedirect() {
  if (typeof window === 'undefined') return
  if (window.location.protocol === 'https:') return
  if (LOCAL_HOSTS.has(window.location.hostname)) return
  if (import.meta.env.VITE_DISABLE_HTTPS_REDIRECT === 'true') return

  const httpsPort = import.meta.env.VITE_HTTPS_PORT || '5443'
  const target = new URL(window.location.href)
  target.protocol = 'https:'
  target.port = httpsPort
  window.location.replace(target.toString())
}
