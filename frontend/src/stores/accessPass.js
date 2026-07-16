import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'
import { useAuthStore } from './auth'

function secondsLeft(expiresAt) {
  const target = new Date(expiresAt || 0).getTime()
  if (!target) return 0
  return Math.max(0, Math.ceil((target - Date.now()) / 1000))
}

export const useAccessPassStore = defineStore('accessPass', () => {
  const auth = useAuthStore()
  const token = ref('')
  const qrSvg = ref('')
  const issuedAt = ref('')
  const expiresAt = ref('')
  const ttlSeconds = ref(30)
  const loading = ref(false)
  const error = ref('')
  const online = ref(true)
  const tick = ref(Date.now())

  const remainingSeconds = computed(() => {
    tick.value
    return secondsLeft(expiresAt.value)
  })
  const progress = computed(() => ttlSeconds.value ? Math.round((remainingSeconds.value / ttlSeconds.value) * 100) : 0)
  const ownerName = computed(() => auth.user?.person?.name || auth.user?.name || 'Мой пропуск')
  const ownerMeta = computed(() => auth.user?.person?.type === 'student' ? 'Студент' : (auth.user?.person?.type === 'teacher' ? 'Преподаватель' : 'Пользователь'))

  function subjectPayload() {
    const person = auth.user?.person
    if (person?.type && person?.id) {
      return { entity_type: person.type, entity_id: person.id }
    }
    if (auth.user?.person_id && !auth.user?.person_type) {
      return { person_id: auth.user.person_id }
    }
    return {}
  }

  async function issue() {
    loading.value = true
    error.value = ''
    try {
      const response = await api.create('access/token/issue', subjectPayload())
      const data = response?.data || response
      token.value = data.token || ''
      qrSvg.value = data.qr_svg || ''
      issuedAt.value = data.issued_at || ''
      expiresAt.value = data.expires_at || ''
      ttlSeconds.value = Number(data.ttl_seconds || 30)
      online.value = true
      return data
    } catch (caught) {
      online.value = false
      error.value = caught.message || 'Не удалось обновить QR-пропуск'
      throw caught
    } finally {
      loading.value = false
    }
  }

  function pulse() {
    tick.value = Date.now()
  }

  return { token, qrSvg, issuedAt, expiresAt, ttlSeconds, loading, error, online, remainingSeconds, progress, ownerName, ownerMeta, issue, pulse }
})
