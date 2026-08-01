<script setup>
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { Eye, EyeOff, LogIn } from '@lucide/vue'
import { useAuthStore } from '../../stores/auth'

const router = useRouter()
const auth = useAuthStore()
const showPassword = ref(false)
const form = reactive({
  email: '',
  password: '',
  savePassword: false,
  staySignedIn: true,
})

async function submit() {
  await auth.login({ email: form.email, password: form.password, staySignedIn: form.staySignedIn })
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
            :type="showPassword ? 'text' : 'password'"
            autocomplete="current-password"
            outlined
            dense
            required
          ><template #append><q-btn flat round dense :aria-label="showPassword ? 'Скрыть пароль' : 'Показать пароль'" @click="showPassword = !showPassword"><EyeOff v-if="showPassword" :size="18" /><Eye v-else :size="18" /></q-btn></template></q-input>
          <q-checkbox v-model="form.savePassword" dense label="Разрешить браузеру сохранить пароль" />
          <q-checkbox v-model="form.staySignedIn" dense label="Не выходить из сайта на этом устройстве" />

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
