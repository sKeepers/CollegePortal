<script setup>
defineProps({
  label: {
    type: String,
    default: 'Изменить ширину панели',
  },
})

const emit = defineEmits(['resize-start', 'reset'])
</script>

<template>
  <button
    type="button"
    class="workspace-splitter"
    :aria-label="label"
    title="Перетащите, чтобы изменить ширину панели. Двойной клик сбрасывает размер."
    @pointerdown.prevent="emit('resize-start', $event)"
    @dblclick="emit('reset')"
  />
</template>

<style scoped>
.workspace-splitter {
  width: 10px;
  min-height: 360px;
  align-self: stretch;
  border: 0;
  border-radius: 8px;
  background: linear-gradient(90deg, transparent 0 3px, #cbd5e1 3px 7px, transparent 7px 10px);
  cursor: col-resize;
}

.workspace-splitter:hover,
.workspace-splitter:focus-visible {
  background: linear-gradient(90deg, transparent 0 2px, #2563eb 2px 8px, transparent 8px 10px);
  outline: none;
}

@media (max-width: 1100px) {
  .workspace-splitter {
    display: none;
  }
}
</style>
