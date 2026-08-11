<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { Eye, EyeOff, LogIn } from '@lucide/vue'
import { useAuthStore } from '../../stores/auth'
import { api } from '../../services/api'
import TelegramLoginButton from '../../components/auth/TelegramLoginButton.vue'

const router = useRouter()
const auth = useAuthStore()
const providers = ref([])
const telegram = computed(() => providers.value.find((provider) => provider.code === 'telegram'))

// Список открыт без входа: кнопку надо нарисовать до того, как человек опознан.
// Отказ здесь не должен ломать форму — вход паролем работает и без внешних способов.
onMounted(async () => {
  try {
    providers.value = (await api.authProviders())?.data || []
  } catch {
    providers.value = []
  }
})
const showPassword = ref(false)
const form = reactive({
  login: '',
  password: '',
  savePassword: false,
  staySignedIn: true,
})

async function submit() {
  await auth.login({ login: form.login, password: form.password, staySignedIn: form.staySignedIn })
  afterSignIn()
}

async function signInWithTelegram(user) {
  // «Не выходить на этом устройстве» — тот же выбор, что и при входе паролем:
  // галочка стоит рядом, и внешний вход не должен её игнорировать.
  await auth.loginWithProvider('telegram', user, form.staySignedIn)
  afterSignIn()
}

function afterSignIn() {
  // Пароль выдан порталом и напечатан на карточке — ведём человека туда, где он
  // заводит свой. Это предложение, а не запрет: со страницы можно уйти в любой раздел.
  router.push(auth.mustChangePassword ? '/account' : '/dashboard')
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
            v-model="form.login"
            label="Телефон, email или логин"
            type="text"
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

        <!-- Только для тех, кто уже привязал мессенджер в «Моей учётной записи»:
             новую учётную запись этот вход не создаёт никогда. -->
        <q-card-section v-if="telegram" class="column items-center q-gutter-sm q-pt-none">
          <div class="text-caption text-grey-7">Или войдите через Telegram, если привязали его в портале</div>
          <TelegramLoginButton :bot-username="telegram.config.bot_username" @authorized="signInWithTelegram" />
        </q-card-section>
      </q-form>
    </q-card>
  </q-page>
</template>
