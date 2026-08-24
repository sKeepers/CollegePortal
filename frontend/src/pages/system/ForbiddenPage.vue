<script setup>
import { useRouter } from 'vue-router'
import AppPage from '../../components/ui/AppPage.vue'
import AppCard from '../../components/ui/AppCard.vue'

const router = useRouter()
</script>

<template>
  <AppPage class="forbidden-page">
    <AppCard class="forbidden-card">
      <div class="forbidden-content">
        <strong>403</strong>
        <h1>Недостаточно прав</h1>
        <p>У вашей учётной записи нет разрешения на открытие этого раздела или выполнение действия.</p>
        <div class="forbidden-actions">
          <q-btn flat label="Вернуться" @click="router.back()" />
          <q-btn color="primary" label="На главную" to="/dashboard" />
        </div>
      </div>
    </AppCard>
  </AppPage>
</template>

<style scoped>
.forbidden-page {
  align-items: center;
  display: flex;
  justify-content: center;
  min-height: 70vh;
}
.forbidden-card {
  max-width: 520px;
  width: 100%;
}
.forbidden-content {
  display: grid;
  gap: 12px;
  justify-items: center;
  padding: 28px;
  text-align: center;
}
.forbidden-content strong {
  color: #b91c1c;
  font-size: 56px;
  line-height: 1;
}
.forbidden-content h1 {
  font-size: 24px;
  font-weight: 700;
  margin: 0;
}
.forbidden-content p {
  color: #64748b;
  margin: 0;
}
.forbidden-actions {
  display: flex;
  gap: 10px;
  margin-top: 8px;
}
</style>
