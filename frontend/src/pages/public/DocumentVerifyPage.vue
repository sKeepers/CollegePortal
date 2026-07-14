<script setup>
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { api } from '../../services/api'

const route = useRoute()
const payload = ref(null)
const error = ref('')

onMounted(async () => {
  try {
    payload.value = await api.list(`public/documents/${route.params.publicId}/verify`)
  } catch (err) {
    error.value = err.message || 'Документ не найден'
  }
})
</script>

<template>
  <q-page class="verify-page">
    <q-card class="verify-card">
      <q-card-section>
        <div class="text-h5">Проверка документа</div>
        <div class="text-grey-7">CollegePortal</div>
      </q-card-section>
      <q-card-section v-if="error">
        <q-banner class="bg-red-1 text-red-10">{{ error }}</q-banner>
      </q-card-section>
      <q-card-section v-else-if="payload">
        <q-list>
          <q-item><q-item-section>Тип</q-item-section><q-item-section side>{{ payload.type }}</q-item-section></q-item>
          <q-item><q-item-section>Регистрационный номер</q-item-section><q-item-section side>{{ payload.registration_number }}</q-item-section></q-item>
          <q-item><q-item-section>Дата выдачи</q-item-section><q-item-section side>{{ payload.issue_date }}</q-item-section></q-item>
          <q-item><q-item-section>Студент</q-item-section><q-item-section side>{{ payload.subject }}</q-item-section></q-item>
          <q-item><q-item-section>Организация</q-item-section><q-item-section side>{{ payload.organization }}</q-item-section></q-item>
          <q-item><q-item-section>Статус</q-item-section><q-item-section side>{{ payload.status }}</q-item-section></q-item>
          <q-item><q-item-section>Дата проверки</q-item-section><q-item-section side>{{ payload.checked_at }}</q-item-section></q-item>
        </q-list>
      </q-card-section>
      <q-card-section v-else>
        <q-spinner /> Проверяем документ...
      </q-card-section>
    </q-card>
  </q-page>
</template>

<style scoped>
.verify-page {
  min-height: 100vh;
  display: grid;
  place-items: center;
  background: #f4f6f8;
  padding: 24px;
}
.verify-card {
  width: min(680px, 100%);
}
</style>
