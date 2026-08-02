<script setup>
import { computed, onMounted, ref } from 'vue'
import { Info } from '@lucide/vue'
import { getVersionInfo, presentVersionInfo } from '../../services/versionService'

const dialogOpen = ref(false)
const loading = ref(false)
const versionInfo = ref(null)
const presentedVersion = computed(() => presentVersionInfo(versionInfo.value))
const environment = computed(() => presentedVersion.value.environmentInfo)
const environmentLabel = computed(() => presentedVersion.value.environmentLabel)

const rows = computed(() => [
  { label: 'Название', value: presentedVersion.value.name },
  { label: 'Версия', value: presentedVersion.value.version },
  { label: 'Релиз', value: presentedVersion.value.release },
  { label: 'Build', value: presentedVersion.value.build },
  { label: 'Git commit', value: presentedVersion.value.gitCommit || presentedVersion.value.build },
  { label: 'Дата сборки', value: presentedVersion.value.buildDateLabel },
  { label: 'Окружение', value: presentedVersion.value.environmentLabel },
  { label: 'Frontend stack', value: presentedVersion.value.frontendStack || 'unknown' },
  { label: 'Backend stack', value: presentedVersion.value.backendStack || 'unknown' },
  { label: 'API', value: presentedVersion.value.apiVersion || 'unknown' },
])

async function loadVersion() {
  loading.value = true

  try {
    versionInfo.value = await getVersionInfo()
  } finally {
    loading.value = false
  }
}

function openDialog() {
  dialogOpen.value = true
  loadVersion()
}

onMounted(loadVersion)
</script>

<template>
  <div class="system-info-panel">
    <button class="system-info-panel__summary" type="button" @click="openDialog">
      <span class="system-info-panel__icon" aria-hidden="true">
        <Info :size="17" />
      </span>
      <span class="system-info-panel__text">
        <strong>{{ presentedVersion.name }}</strong>
        <span>v{{ presentedVersion.version }}</span>
        <small>Build: {{ presentedVersion.build }}</small>
      </span>
      <span
        class="system-info-panel__env"
        :style="{
          backgroundColor: environment.backgroundColor,
          color: environment.textColor,
          borderColor: environment.color,
        }"
      >
        {{ environmentLabel }}
      </span>
    </button>
  </div>

  <q-dialog v-model="dialogOpen">
    <q-card class="system-info-dialog">
      <q-card-section class="system-info-dialog__header">
        <div>
          <div class="text-h6">О системе</div>
          <div class="text-caption text-grey-7">Информация о версии и сборке CollegePortal</div>
        </div>
        <q-badge
          outline
          :style="{
            color: environment.textColor,
            borderColor: environment.color,
          }"
        >
          {{ environmentLabel }}
        </q-badge>
      </q-card-section>

      <q-separator />

      <q-card-section>
        <q-linear-progress v-if="loading" indeterminate color="primary" class="q-mb-md" />
        <dl class="system-info-dialog__list">
          <template v-for="row in rows" :key="row.label">
            <dt>{{ row.label }}</dt>
            <dd>{{ row.value }}</dd>
          </template>
        </dl>
      </q-card-section>

      <q-card-actions align="right">
        <q-btn v-close-popup flat label="Закрыть" color="primary" />
      </q-card-actions>
    </q-card>
  </q-dialog>
</template>
