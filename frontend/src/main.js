import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { Quasar, Notify, Dialog, Loading } from 'quasar'
import langRu from 'quasar/lang/ru'
import '@quasar/extras/material-icons/material-icons.css'
import 'quasar/src/css/index.sass'
import './styles/main.css'
import App from './app/App.vue'
import { router } from './router'

createApp(App)
  .use(createPinia())
  .use(router)
  .use(Quasar, {
    plugins: {
      Notify,
      Dialog,
      Loading,
    },
    lang: langRu,
    config: {
      brand: {
        primary: '#2563eb',
        secondary: '#475569',
        accent: '#f59e0b',
        dark: '#111827',
        positive: '#047857',
        negative: '#991b1b',
        info: '#1d4ed8',
        warning: '#c2410c',
      },
    },
  })
  .mount('#app')
