<template>
  <q-toolbar class="app-toolbar">
    <div class="app-toolbar__content">
      <slot />
    </div>
    <q-space />
    <div v-if="$slots.actions" class="app-toolbar__actions">
      <slot name="actions" />
    </div>
  </q-toolbar>
</template>
