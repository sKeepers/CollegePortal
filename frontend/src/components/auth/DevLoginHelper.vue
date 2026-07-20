<script setup>
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '../../services/api'
import { useAuthStore } from '../../stores/auth'

const emit = defineEmits(['error'])

const router = useRouter()
const auth = useAuthStore()
const roles = ref([])
const loading = ref(false)
const failed = ref(false)

async function loadRoles() {
  try {
    const payload = await api.list('dev-login/options')
    roles.value = Array.isArray(payload?.data) ? payload.data : []
  } catch {
    failed.value = true
  }
}

async function loginAs(roleCode) {
  loading.value = true
  try {
    const payload = await api.post('dev-login/login', { role: roleCode })
    auth.acceptSession(payload)
    await router.push('/dashboard')
  } catch (error) {
    emit('error', error.message || 'DEV helper недоступен')
  } finally {
    loading.value = false
  }
}

onMounted(loadRoles)
</script>

<template>
  <q-banner v-if="!failed && roles.length" rounded class="bg-blue-1 text-blue-10 cp-dev-helper">
    <div class="cp-dev-helper__title">DEV быстрый вход</div>
    <div class="cp-dev-helper__roles">
      <q-btn
        v-for="role in roles"
        :key="role.code"
        outline
        dense
        no-caps
        color="primary"
        :disable="loading"
        :label="role.label"
        @click="loginAs(role.code)"
      />
    </div>
  </q-banner>
</template>

<style scoped>
.cp-dev-helper {
  margin-top: 12px;
}

.cp-dev-helper__title {
  font-weight: 700;
  margin-bottom: 8px;
}

.cp-dev-helper__roles {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
</style>
