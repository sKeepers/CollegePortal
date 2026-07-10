<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { Eye, EyeOff, GripVertical, RotateCcw, Save, SlidersHorizontal, X } from '@lucide/vue'
import AppErrorBanner from '../ui/AppErrorBanner.vue'
import { DASHBOARD_LAYOUT_PROFILE, dashboardLayoutService } from '../../services/dashboardLayoutService'

const props = defineProps({
  dashboardType: {
    type: String,
    required: true,
  },
  widgets: {
    type: Array,
    default: () => [],
  },
})

const loading = ref(false)
const saving = ref(false)
const editing = ref(false)
const error = ref('')
const profile = ref('standard')
const customLayoutId = ref(null)
const savedCustomWidgets = ref(null)
const activeWidgets = ref([])
const draftWidgets = ref([])
const draggedId = ref(null)

const sizeLabels = {
  small: 'S',
  medium: 'M',
  large: 'L',
  full: 'XL',
}
const sizeSequence = ['small', 'medium', 'large', 'full']

const defaultWidgets = computed(() => normalizeWidgets())
const currentWidgets = computed(() => editing.value ? draftWidgets.value : activeWidgets.value)
const visibleWidgets = computed(() => currentWidgets.value.filter((widget) => widget.visible))
const hiddenWidgets = computed(() => draftWidgets.value.filter((widget) => !widget.visible))
const canUseCustom = computed(() => Boolean(customLayoutId.value || savedCustomWidgets.value))

function clone(value) {
  return JSON.parse(JSON.stringify(value))
}

function normalizeWidgets(layoutWidgets = null) {
  const saved = new Map((layoutWidgets || []).map((widget) => [widget.id, widget]))

  return props.widgets.map((widget, index) => {
    const savedWidget = saved.get(widget.id)

    return {
      ...widget,
      order: Number.isInteger(savedWidget?.order) ? savedWidget.order : index,
      size: savedWidget?.size || widget.defaultSize || 'medium',
      visible: savedWidget?.visible ?? true,
    }
  }).sort((left, right) => left.order - right.order)
}

function applyStandard() {
  profile.value = 'standard'
  activeWidgets.value = clone(defaultWidgets.value)
}

function applyCustom() {
  profile.value = 'custom'
  activeWidgets.value = clone(savedCustomWidgets.value || defaultWidgets.value)
}

async function loadLayouts() {
  loading.value = true
  error.value = ''

  try {
    const payload = await dashboardLayoutService.list(props.dashboardType)
    const layouts = Array.isArray(payload?.data) ? payload.data : []
    const custom = layouts.find((layout) => layout.is_default) || layouts.find((layout) => layout.name === DASHBOARD_LAYOUT_PROFILE)

    if (custom) {
      customLayoutId.value = custom.id
      savedCustomWidgets.value = normalizeWidgets(custom.layout?.widgets || [])
      applyCustom()
    } else {
      customLayoutId.value = null
      savedCustomWidgets.value = null
      applyStandard()
    }
  } catch (err) {
    error.value = 'Персональные настройки Dashboard недоступны. Показан стандартный вид.'
    customLayoutId.value = null
    savedCustomWidgets.value = null
    applyStandard()
  } finally {
    loading.value = false
  }
}

function startEditing() {
  draftWidgets.value = clone(activeWidgets.value.length ? activeWidgets.value : defaultWidgets.value)
  editing.value = true
}

function cancelEditing() {
  draftWidgets.value = []
  editing.value = false
}

async function saveEditing() {
  saving.value = true
  error.value = ''

  try {
    const normalized = draftWidgets.value.map((widget, index) => ({ ...widget, order: index }))
    const payload = await dashboardLayoutService.save(customLayoutId.value, props.dashboardType, normalized)
    customLayoutId.value = payload?.data?.id || customLayoutId.value
    savedCustomWidgets.value = clone(normalized)
    activeWidgets.value = clone(normalized)
    profile.value = 'custom'
    editing.value = false
  } catch (err) {
    error.value = err.message || 'Не удалось сохранить расположение Dashboard'
  } finally {
    saving.value = false
  }
}

async function resetToStandard() {
  saving.value = true
  error.value = ''

  try {
    await dashboardLayoutService.reset(props.dashboardType)
    customLayoutId.value = null
    savedCustomWidgets.value = null
    draftWidgets.value = clone(defaultWidgets.value)
    applyStandard()
    editing.value = false
  } catch (err) {
    error.value = err.message || 'Не удалось вернуть стандартный вид Dashboard'
  } finally {
    saving.value = false
  }
}

function switchProfile(value) {
  if (editing.value) {
    return
  }

  if (value === 'custom' && canUseCustom.value) {
    applyCustom()
    return
  }

  applyStandard()
}

function moveWidget(sourceId, targetId) {
  if (!sourceId || sourceId === targetId) {
    return
  }

  const items = [...draftWidgets.value]
  const fromIndex = items.findIndex((widget) => widget.id === sourceId)
  const toIndex = items.findIndex((widget) => widget.id === targetId)

  if (fromIndex < 0 || toIndex < 0) {
    return
  }

  const [moved] = items.splice(fromIndex, 1)
  items.splice(toIndex, 0, moved)
  draftWidgets.value = items.map((widget, index) => ({ ...widget, order: index }))
}

function onDragStart(widgetId) {
  if (!editing.value) {
    return
  }

  draggedId.value = widgetId
}

function onDrop(widgetId) {
  moveWidget(draggedId.value, widgetId)
  draggedId.value = null
}

function cycleSize(widgetId) {
  draftWidgets.value = draftWidgets.value.map((widget) => {
    if (widget.id !== widgetId) {
      return widget
    }

    const currentIndex = sizeSequence.indexOf(widget.size)
    const nextSize = sizeSequence[(currentIndex + 1) % sizeSequence.length]
    return { ...widget, size: nextSize }
  })
}

function setVisible(widgetId, visible) {
  draftWidgets.value = draftWidgets.value.map((widget) => widget.id === widgetId ? { ...widget, visible } : widget)
}

watch(() => props.dashboardType, loadLayouts)
watch(() => props.widgets, () => {
  if (!savedCustomWidgets.value) {
    activeWidgets.value = clone(defaultWidgets.value)
  }
}, { deep: true })

onMounted(loadLayouts)
</script>

<template>
  <div class="personal-dashboard">
    <div class="personal-dashboard__bar">
      <div class="personal-dashboard__profiles" aria-label="Профиль Dashboard">
        <button
          type="button"
          :class="['personal-dashboard__profile', { 'personal-dashboard__profile--active': profile === 'standard' }]"
          :disabled="editing"
          @click="switchProfile('standard')"
        >
          Стандартный
        </button>
        <button
          type="button"
          :class="['personal-dashboard__profile', { 'personal-dashboard__profile--active': profile === 'custom' }]"
          :disabled="editing || !canUseCustom"
          @click="switchProfile('custom')"
        >
          Мой Dashboard
        </button>
      </div>

      <q-btn
        v-if="!editing"
        outline
        color="primary"
        :loading="loading"
        @click="startEditing"
      >
        <SlidersHorizontal :size="16" />
        <span>Настроить Dashboard</span>
      </q-btn>

      <div v-else class="personal-dashboard__actions">
        <q-btn color="primary" :loading="saving" @click="saveEditing">
          <Save :size="16" />
          <span>Сохранить</span>
        </q-btn>
        <q-btn flat :disable="saving" @click="cancelEditing">
          <X :size="16" />
          <span>Отменить</span>
        </q-btn>
        <q-btn flat color="negative" :loading="saving" @click="resetToStandard">
          <RotateCcw :size="16" />
          <span>Стандартный вид</span>
        </q-btn>
      </div>
    </div>

    <AppErrorBanner :message="error" />

    <div v-if="editing" class="personal-dashboard__hint">
      Перетащите виджет мышью, нажмите размер S/M/L/XL для изменения ширины или скройте ненужный блок.
    </div>

    <div v-if="editing && hiddenWidgets.length" class="personal-dashboard__hidden">
      <span>Скрытые виджеты:</span>
      <button v-for="widget in hiddenWidgets" :key="widget.id" type="button" @click="setVisible(widget.id, true)">
        <Eye :size="14" /> {{ widget.title }}
      </button>
    </div>

    <div class="dashboard-grid personal-dashboard__grid" :class="{ 'personal-dashboard__grid--editing': editing }">
      <section
        v-for="widget in visibleWidgets"
        :key="widget.id"
        :class="['personal-dashboard__widget', `personal-dashboard__widget--${widget.size || 'medium'}`]"
        :draggable="editing"
        @dragstart="onDragStart(widget.id)"
        @dragover.prevent
        @drop.prevent="onDrop(widget.id)"
      >
        <div v-if="editing" class="personal-dashboard__widget-toolbar">
          <span><GripVertical :size="16" /> {{ widget.title }}</span>
          <div>
            <button type="button" @click="cycleSize(widget.id)">{{ sizeLabels[widget.size] || 'M' }}</button>
            <button type="button" @click="setVisible(widget.id, false)"><EyeOff :size="14" /> Скрыть</button>
          </div>
        </div>
        <slot :name="widget.id" :widget="widget" />
      </section>
    </div>
  </div>
</template>
