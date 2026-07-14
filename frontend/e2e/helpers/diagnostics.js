const secretPatterns = [
  [/Bearer\s+[A-Za-z0-9._~-]+/gi, 'Bearer [REDACTED]'],
  [/(["']?(?:password|token|secret)["']?\s*[:=]\s*)[^,\s}]+/gi, '$1[REDACTED]'],
  [/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/gi, '[EMAIL REDACTED]'],
]

export function redactDiagnostics(value) {
  return secretPatterns.reduce(
    (result, [pattern, replacement]) => result.replace(pattern, replacement),
    String(value),
  )
}
