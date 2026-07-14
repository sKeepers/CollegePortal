const credentialVariables = {
  admin: ['E2E_ADMIN_EMAIL', 'E2E_ADMIN_PASSWORD'],
  study: ['E2E_STUDY_EMAIL', 'E2E_STUDY_PASSWORD'],
  teacher: ['E2E_TEACHER_EMAIL', 'E2E_TEACHER_PASSWORD'],
  security: ['E2E_SECURITY_EMAIL', 'E2E_SECURITY_PASSWORD'],
  student: ['E2E_STUDENT_EMAIL', 'E2E_STUDENT_PASSWORD'],
}

export function credentialsFor(role) {
  const names = credentialVariables[role]
  if (!names) {
    throw new Error(`Неизвестная E2E-роль: ${role}`)
  }

  const [emailName, passwordName] = names
  const email = process.env[emailName]
  const password = process.env[passwordName]

  return email && password ? { email, password } : null
}
