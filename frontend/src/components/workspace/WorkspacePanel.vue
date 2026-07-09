<script setup>
import AppCard from '../ui/AppCard.vue'

const props = defineProps({
  title: {
    type: String,
    required: true,
  },
  subtitle: {
    type: [String, Array],
    default: '',
  },
  metrics: {
    type: Array,
    default: () => [],
  },
  actions: {
    type: Array,
    default: () => [],
  },
  events: {
    type: Array,
    default: () => [],
  },
})
</script>

<template>
  <AppCard class="workspace-panel">
    <div class="workspace-panel__hero" :class="{ 'workspace-panel__hero--with-photo': $slots.photo }">
      <div v-if="$slots.photo" class="workspace-panel__photo">
        <slot name="photo" />
      </div>

      <div class="workspace-panel__hero-main">
        <div class="workspace-panel__title-row">
          <h2>{{ title }}</h2>
          <div v-if="$slots.status" class="workspace-panel__status">
            <slot name="status" />
          </div>
        </div>

        <div v-if="subtitle" class="workspace-panel__subtitle">
          <template v-if="Array.isArray(subtitle)">
            <span v-for="item in subtitle.filter(Boolean)" :key="item">{{ item }}</span>
          </template>
          <span v-else>{{ subtitle }}</span>
        </div>
      </div>
    </div>

    <div v-if="metrics.length" class="workspace-panel__metrics">
      <div v-for="metric in metrics" :key="metric.label" class="workspace-panel__metric">
        <span>{{ metric.label }}</span>
        <q-btn
          v-if="metric.to"
          flat
          dense
          no-caps
          class="workspace-panel__metric-link"
          :to="metric.to"
        >
          {{ metric.value ?? '—' }}
        </q-btn>
        <strong v-else>{{ metric.value ?? '—' }}</strong>
      </div>
    </div>

    <section v-if="events.length || $slots.events" class="workspace-panel__section">
      <h3>Ближайшие события</h3>
      <slot name="events">
        <div class="workspace-panel__event-list">
          <div v-for="event in events" :key="event.id || event.title" class="workspace-panel__event">
            <strong>{{ event.title }}</strong>
            <span>{{ event.description }}</span>
          </div>
        </div>
      </slot>
    </section>

    <section v-if="actions.length || $slots.actions" class="workspace-panel__section">
      <h3>Быстрые действия</h3>
      <slot name="actions">
        <div class="workspace-panel__actions">
          <q-btn
            v-for="action in actions"
            :key="action.label"
            no-caps
            unelevated
            class="workspace-panel__action"
            :to="action.to"
            :disable="action.disabled"
          >
            {{ action.label }}
          </q-btn>
        </div>
      </slot>
    </section>

    <div class="workspace-panel__content">
      <slot />
    </div>
  </AppCard>
</template>
