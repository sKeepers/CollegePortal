<script setup>
import { ref } from 'vue'
import { Trash2 } from '@lucide/vue'
import AppPage from '../../components/ui/AppPage.vue'
import PageHeader from '../../components/ui/PageHeader.vue'
import AppToolbar from '../../components/ui/AppToolbar.vue'
import AppFilterBar from '../../components/ui/AppFilterBar.vue'
import AppTable from '../../components/ui/AppTable.vue'
import AppCard from '../../components/ui/AppCard.vue'
import AppFormSection from '../../components/ui/AppFormSection.vue'
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'
import AppEmptyState from '../../components/ui/AppEmptyState.vue'
import AppLoading from '../../components/ui/AppLoading.vue'
import AppErrorBanner from '../../components/ui/AppErrorBanner.vue'
import AppConfirmDialog from '../../components/ui/AppConfirmDialog.vue'

const confirmOpen = ref(false)

const columns = [
  { name: 'name', label: 'ФИО', field: 'name', align: 'left', sortable: true },
  { name: 'group', label: 'Группа', field: 'group', align: 'left', sortable: true },
  { name: 'status', label: 'Статус', field: 'status', align: 'left' },
  { name: 'actions', label: 'Действия', field: 'actions', align: 'right' },
]

const rows = [
  { id: 1, name: 'Анохин Дмитрий Алексеевич', group: 'ВИ-101', status: 'active' },
  { id: 2, name: 'Борисова Софья Владимировна', group: 'ЖИ-102', status: 'attention' },
  { id: 3, name: 'Соколова Валерия Сергеевна', group: 'ДПИ-103', status: 'archived' },
]

const statusMap = {
  active: { label: 'Обучается', tone: 'success' },
  attention: { label: 'Требует внимания', tone: 'warning' },
  archived: { label: 'Архив', tone: 'neutral' },
}
</script>

<template>
  <AppPage>
    <PageHeader
      title="Библиотека интерфейса"
      subtitle="Демонстрация базовых компонентов нового GUI CollegePortal."
    >
      <template #actions>
        <q-btn color="primary" no-caps label="Основное действие" />
        <q-btn outline color="secondary" no-caps label="Вторичное" />
      </template>
    </PageHeader>

    <AppToolbar>
      <span>Панель действий страницы</span>
      <template #actions>
        <q-btn color="primary" no-caps label="Создать" />
        <q-btn outline color="secondary" no-caps label="Экспорт" />
      </template>
    </AppToolbar>

    <AppFilterBar>
      <q-input dense outlined label="Поиск" model-value="Анохин" />
      <q-select
        dense
        outlined
        label="Группа"
        model-value="Все группы"
        :options="['Все группы', 'ВИ-101', 'ЖИ-102']"
      />
      <q-select
        dense
        outlined
        label="Статус"
        model-value="Все статусы"
        :options="['Все статусы', 'Обучается', 'Архив']"
      />
      <template #actions>
        <q-btn color="primary" no-caps label="Применить" />
        <q-btn flat color="secondary" no-caps label="Сбросить" />
      </template>
    </AppFilterBar>

    <AppErrorBanner message="Пример ошибки: не удалось сохранить запись. Проверьте обязательные поля." />

    <div class="ui-foundation-grid">
      <AppCard title="Кнопки" subtitle="Основные состояния действий">
        <div class="ui-foundation-row">
          <q-btn color="primary" no-caps label="Сохранить" />
          <q-btn outline color="secondary" no-caps label="Отмена" />
          <q-btn color="negative" no-caps label="Удалить" @click="confirmOpen = true">
            <Trash2 :size="16" class="q-ml-xs" />
          </q-btn>
          <q-btn color="primary" loading no-caps label="Загрузка" />
        </div>
      </AppCard>

      <AppCard title="Статусы" subtitle="Текст + цвет">
        <div class="ui-foundation-row">
          <AppStatusBadge label="Активно" tone="success" />
          <AppStatusBadge label="Информация" tone="info" />
          <AppStatusBadge label="Внимание" tone="warning" />
          <AppStatusBadge label="Ошибка" tone="danger" />
          <AppStatusBadge label="Архив" tone="neutral" />
        </div>
      </AppCard>
    </div>

    <AppCard title="Таблица" subtitle="Единый вид реестров">
      <AppTable :rows="rows" :columns="columns">
        <template #body-cell-status="props">
          <q-td :props="props">
            <AppStatusBadge
              :label="statusMap[props.row.status].label"
              :tone="statusMap[props.row.status].tone"
            />
          </q-td>
        </template>
        <template #body-cell-actions="props">
          <q-td :props="props">
            <q-btn flat dense color="primary" no-caps label="Открыть" />
          </q-td>
        </template>
      </AppTable>
    </AppCard>

    <div class="ui-foundation-grid">
      <AppCard title="Форма" subtitle="Секция формы">
        <AppFormSection title="Основные данные" description="Поля группируются по смыслу.">
          <div class="ui-foundation-form">
            <q-input dense outlined label="Фамилия" model-value="Анохин" />
            <q-input dense outlined label="Имя" model-value="Дмитрий" />
            <q-select dense outlined label="Статус" model-value="Обучается" :options="['Обучается', 'Выпущен']" />
          </div>
        </AppFormSection>
      </AppCard>

      <AppCard title="Состояния" subtitle="Пусто и загрузка">
        <div class="ui-foundation-stack">
          <AppLoading />
          <AppEmptyState title="Нет студентов" description="Создайте студента или измените фильтры." />
        </div>
      </AppCard>
    </div>

    <AppConfirmDialog
      v-model="confirmOpen"
      title="Удалить запись?"
      message="Это демонстрационное подтверждение удаления."
      confirm-label="Удалить"
      @confirm="confirmOpen = false"
    />
  </AppPage>
</template>
