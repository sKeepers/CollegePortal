<script setup>
import { computed } from 'vue'
import { getCurrentEnvironment } from '../../services/environmentService'

const environment = computed(() => getCurrentEnvironment())
</script>

<template>
  <q-badge
    class="environment-badge"
    :style="{
      backgroundColor: environment.backgroundColor,
      color: environment.textColor,
      borderColor: environment.color,
    }"
  >
    {{ environment.label }}
    <q-tooltip anchor="bottom middle" self="top middle">
      {{ environment.tooltip }}
    </q-tooltip>
  </q-badge>
</template>
