<script setup>
import { computed } from 'vue'
import { ArrowLeft } from '@lucide/vue'
import { useRoute, useRouter } from 'vue-router'

defineProps({
  label: {
    type: String,
    default: 'Назад к списку',
  },
})

const emit = defineEmits(['back'])

const route = useRoute()
const router = useRouter()

/**
 * Полоса стоит в самой раскладке, а не внутри карточки, и это намеренно.
 * Карточка рисуется только когда запись нашлась; открыв `/students/99999`,
 * человек получил бы пустой экран без единой кнопки. Возврат обязан быть
 * там же, где режим карточки, — иначе из тупика выходят только кнопкой
 * браузера.
 */
const hasAddress = computed(() => Boolean(route.params.id))

/**
 * Уходим на тот же маршрут без `id`. Имя маршрута одно на список и карточку —
 * `students` отдаёт и `/students`, и `/students/955`, — поэтому возврат
 * одинаков во всех разделах и не требует знать, где мы находимся.
 *
 * `replace`, а не `push`: иначе «Назад» браузера возвращало бы в карточку,
 * из которой человек только что вышел.
 *
 * Разделы, где выбор живёт только в памяти страницы, адреса не имеют: там
 * возврат — это снятие выбора, и делает его сама страница.
 */
function goBack() {
  if (!hasAddress.value) {
    emit('back')

    return
  }

  const params = { ...route.params }
  delete params.id

  router.replace({ name: route.name, params, query: route.query })
}
</script>

<template>
  <div class="workspace-back">
    <q-btn flat dense no-caps color="primary" @click="goBack">
      <ArrowLeft :size="16" class="q-mr-xs" />
      {{ label }}
    </q-btn>
  </div>
</template>
