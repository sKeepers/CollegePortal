<script setup>
import { reactive } from 'vue'
import { useRouter } from 'vue-router'
import { LogIn } from '@lucide/vue'
import { useAuthStore } from '../../stores/auth'

const router = useRouter()
const auth = useAuthStore()
const form = reactive({
  email: 'admin@college-portal.local',
  password: 'password',
})

async function submit() {
  await auth.login(form)
  router.push('/dashboard')
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

      <q-form class="cp-login-form" @submit.prevent="submit">
        <q-card-section class="q-gutter-md">
          <q-input
            v-model="form.email"
            label="Email"
            type="email"
            autocomplete="username"
            outlined
            dense
            required
          />
          <q-input
            v-model="form.password"
            label="Пароль"
            type="password"
            autocomplete="current-password"
            outlined
            dense
            required
          />

          <q-banner v-if="auth.error" rounded class="bg-red-1 text-red-9">
            {{ auth.error }}
          </q-banner>
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
