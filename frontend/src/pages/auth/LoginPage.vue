<script setup>
import { computed, defineAsyncComponent, reactive } from 'vue'
import { useRouter } from 'vue-router'
import { LogIn } from '@lucide/vue'
import { useAuthStore } from '../../stores/auth'

const DevLoginHelper = import.meta.env.DEV
  ? defineAsyncComponent(() => import('../../components/auth/DevLoginHelper.vue'))
  : null
const router = useRouter()
const auth = useAuthStore()
const form = reactive({
  email: '',
  password: '',
})
const allowedDevHosts = (import.meta.env.VITE_DEV_LOGIN_HELPER_HOSTS || '')
  .split(',')
  .map((host) => host.trim())
  .filter(Boolean)
const showDevLoginHelper = computed(() => (
  import.meta.env.DEV
  && import.meta.env.VITE_DEV_LOGIN_HELPER === 'true'
  && allowedDevHosts.includes(window.location.hostname)
))

async function submit() {
  await auth.login(form)
  router.push('/dashboard')
}

function showDevError(message) {
  auth.error = message
}
</script>

<template>
  <q-page class="cp-login-page">
    <q-card flat bordered class="cp-login-card">
      <q-card-section>
        <div class="cp-login-brand">
          <strong>CollegePortal</strong>
          <span>Вход в рабочую систему колледжа</span>
        </div>
      </q-card-section>

      <q-form class="cp-login-form" autocomplete="off" @submit.prevent="submit">
        <q-card-section class="q-gutter-md">
          <q-input
            v-model="form.email"
            name="collegeportal-login-email"
            label="Email"
            type="email"
            autocomplete="off"
            outlined
            dense
            required
          />
          <q-input
            v-model="form.password"
            name="collegeportal-login-secret"
            label="Пароль"
            type="password"
            autocomplete="off"
            outlined
            dense
            required
          />

          <q-banner v-if="auth.error" rounded class="bg-red-1 text-red-9">
            {{ auth.error }}
          </q-banner>
          <DevLoginHelper v-if="DevLoginHelper && showDevLoginHelper" @error="showDevError" />
        </q-card-section>

        <q-card-actions vertical align="stretch">
          <q-btn color="primary" type="submit" :loading="auth.loading" no-caps>
            <LogIn :size="18" class="q-mr-sm" />
            Войти
          </q-btn>
          <q-btn flat color="primary" to="/public/applicant" no-caps>
            Абитуриенту
          </q-btn>
        </q-card-actions>
      </q-form>
    </q-card>
  </q-page>
</template>
