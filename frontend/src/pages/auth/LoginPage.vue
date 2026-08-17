<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { Eye, EyeOff, LogIn, MessageSquare } from '@lucide/vue'
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
  code: '',
  savePassword: false,
  staySignedIn: true,
})

// Вход по коду — второй способ на той же форме, а не отдельная страница: логин
// человек уже набрал, и уводить его на другой экран значило бы просить набрать снова.
const codeMode = ref(false)
const codeSent = ref(false)
const codeNotice = ref('')

async function submit() {
  if (codeMode.value) {
    await auth.loginWithCode(form.login, form.code, form.staySignedIn)
    afterSignIn()

    return
  }

  await auth.login({ login: form.login, password: form.password, staySignedIn: form.staySignedIn })
  afterSignIn()
}

function useCode() {
  codeMode.value = true
  auth.error = ''
}

function usePassword() {
  codeMode.value = false
  codeSent.value = false
  codeNotice.value = ''
  form.code = ''
  auth.error = ''
}

/**
 * Ответ сервера одинаков и для несуществующего логина, и для непривязанного
 * мессенджера — намеренно. Поэтому экран показывает его как есть и переходит ко
 * второму шагу в любом случае: гадать ему не по чему, а подсказывать подбирающему,
 * существует ли логин, нельзя.
 */
async function requestCode() {
  auth.error = ''

  try {
    const answer = await api.requestLoginCode(form.login)
    codeNotice.value = answer?.message || 'Если такая учётная запись есть и к ней привязан мессенджер, код отправлен в него.'
    codeSent.value = true
  } catch (caught) {
    auth.error = caught.message
  }
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
            v-if="!codeMode"
            v-model="form.password"
            label="Пароль"
            :type="showPassword ? 'text' : 'password'"
            autocomplete="current-password"
            outlined
            dense
            required
          ><template #append><q-btn flat round dense :aria-label="showPassword ? 'Скрыть пароль' : 'Показать пароль'" @click="showPassword = !showPassword"><EyeOff v-if="showPassword" :size="18" /><Eye v-else :size="18" /></q-btn></template></q-input>

          <template v-if="codeMode">
            <q-btn
              flat
              color="primary"
              no-caps
              :disable="!form.login"
              :loading="auth.loading"
              @click="requestCode"
            >
              <MessageSquare :size="18" class="q-mr-sm" />
              {{ codeSent ? 'Прислать код ещё раз' : 'Получить код' }}
            </q-btn>
            <div v-if="codeNotice" class="text-caption text-grey-7">{{ codeNotice }}</div>
            <q-input
              v-if="codeSent"
              v-model="form.code"
              label="Код из сообщения"
              type="text"
              inputmode="numeric"
              autocomplete="one-time-code"
              maxlength="6"
              outlined
              dense
              required
            />
          </template>

          <q-checkbox v-if="!codeMode" v-model="form.savePassword" dense label="Разрешить браузеру сохранить пароль" />
          <q-checkbox v-model="form.staySignedIn" dense label="Не выходить из сайта на этом устройстве" />

          <q-banner v-if="auth.error" rounded class="bg-red-1 text-red-9">
            {{ auth.error }}
          </q-banner>
        </q-card-section>

        <q-card-actions vertical align="stretch">
          <q-btn color="primary" type="submit" :loading="auth.loading" :disable="codeMode && !codeSent" no-caps>
            <LogIn :size="18" class="q-mr-sm" />
            Войти
          </q-btn>
          <q-btn v-if="!codeMode" flat color="primary" no-caps @click="useCode">
            Войти по коду из бота
          </q-btn>
          <q-btn v-else flat color="primary" no-caps @click="usePassword">
            Войти паролем
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
