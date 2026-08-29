<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Eye, EyeOff, GripVertical, RotateCcw, Save, SlidersHorizontal, X } from '@lucide/vue'
import AppErrorBanner from '../ui/AppErrorBanner.vue'
// `DASHBOARD_LAYOUT_PROFILE` остаётся прежним: это **хранимое** имя, по нему
// ищется уже сохранённая расстановка. Переименование потеряло бы её у тех,
// кто настроил рабочий стол под себя. Здесь переписаны только надписи.
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
const widgetSpans = ref({})
const widgetElements = new Map()
let resizeObserver = null
const DASHBOARD_LOCAL_LAYOUT_KEY = 'collegePortal.dashboard.layout.v2'

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
const localStorageKey = computed(() => `${DASHBOARD_LOCAL_LAYOUT_KEY}.${props.dashboardType}`)

function clone(value) {
  return JSON.parse(JSON.stringify(value))
}

function canUseLocalStorage() {
  try {
    return typeof window !== 'undefined' && Boolean(window.localStorage)
  } catch {
    return false
  }
}

function normalizeForStorage(widgets) {
  return widgets.map((widget, index) => ({
    id: widget.id,
    order: Number.isInteger(widget.order) ? widget.order : index,
    size: widget.size || widget.defaultSize || 'medium',
    visible: widget.visible !== false,
  }))
}

function loadLocalLayout() {
  if (!canUseLocalStorage()) {
    return null
  }

  try {
    const payload = JSON.parse(window.localStorage.getItem(localStorageKey.value) || 'null')
    if (payload?.version !== 2 || !Array.isArray(payload.widgets)) {
      return null
    }

    return payload.widgets
  } catch {
    return null
  }
}

function saveLocalLayout(widgets) {
  if (!canUseLocalStorage()) {
    return
  }

  window.localStorage.setItem(localStorageKey.value, JSON.stringify({
    version: 2,
    dashboardType: props.dashboardType,
    widgets: normalizeForStorage(widgets),
    savedAt: new Date().toISOString(),
  }))
}

function clearLocalLayout() {
  if (canUseLocalStorage()) {
    window.localStorage.removeItem(localStorageKey.value)
  }
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

    const localLayout = loadLocalLayout()
    if (localLayout) {
      savedCustomWidgets.value = normalizeWidgets(localLayout)
      activeWidgets.value = clone(savedCustomWidgets.value)
      profile.value = 'custom'
    }
  } catch (err) {
    error.value = 'Персональные настройки рабочего стола недоступны. Показан стандартный вид.'
    customLayoutId.value = null
    savedCustomWidgets.value = null
    const localLayout = loadLocalLayout()
    if (localLayout) {
      savedCustomWidgets.value = normalizeWidgets(localLayout)
      activeWidgets.value = clone(savedCustomWidgets.value)
      profile.value = 'custom'
    } else {
      applyStandard()
    }
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
    saveLocalLayout(normalized)
    profile.value = 'custom'
    editing.value = false
  } catch (err) {
    error.value = err.message || 'Не удалось сохранить расположение рабочего стола'
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
    clearLocalLayout()
    applyStandard()
    editing.value = false
  } catch (err) {
    error.value = err.message || 'Не удалось вернуть стандартный вид рабочего стола'
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
  const widget = draftWidgets.value.find((item) => item.id === widgetId)
  const currentIndex = sizeSequence.indexOf(widget?.size)
  const nextSize = sizeSequence[(currentIndex + 1) % sizeSequence.length]
  setWidgetSize(widgetId, nextSize)
}

function setWidgetSize(widgetId, size) {
  draftWidgets.value = draftWidgets.value.map((widget) => {
    if (widget.id !== widgetId) {
      return widget
    }

    return { ...widget, size: sizeSequence.includes(size) ? size : 'medium' }
  })
}

function setVisible(widgetId, visible) {
  draftWidgets.value = draftWidgets.value.map((widget) => widget.id === widgetId ? { ...widget, visible } : widget)
}

function updateWidgetSpan(widgetId, element) {
  // Высоту строки считаем и в режиме редактирования: без этого grid выравнивает
  // строку по самому высокому виджету и раскладка перестает быть компактной.
  if (!element) return
  const rowHeight = 8
  const gap = 12
  const span = Math.max(1, Math.ceil((element.getBoundingClientRect().height + gap) / (rowHeight + gap)))
  if (widgetSpans.value[widgetId] !== span) widgetSpans.value = { ...widgetSpans.value, [widgetId]: span }
}

function setWidgetElement(widgetId, element) {
  const previous = widgetElements.get(widgetId)
  if (previous && resizeObserver) resizeObserver.unobserve(previous)
  if (!element) {
    widgetElements.delete(widgetId)
    return
  }
  widgetElements.set(widgetId, element)
  resizeObserver?.observe(element)
  updateWidgetSpan(widgetId, element)
}

async function refreshWidgetSpans() {
  await nextTick()
  widgetElements.forEach((element, widgetId) => updateWidgetSpan(widgetId, element))
}

watch(() => props.dashboardType, loadLayouts)
watch(() => props.widgets, () => {
  if (!savedCustomWidgets.value) {
    activeWidgets.value = clone(defaultWidgets.value)
  }
}, { deep: true })
watch([visibleWidgets, editing], refreshWidgetSpans, { deep: true })

onMounted(async () => {
  if (typeof ResizeObserver !== 'undefined') {
    resizeObserver = new ResizeObserver((entries) => entries.forEach((entry) => {
      const widgetId = [...widgetElements.entries()].find(([, element]) => element === entry.target)?.[0]
      if (widgetId) updateWidgetSpan(widgetId, entry.target)
    }))
  }
  await loadLayouts()
  await refreshWidgetSpans()
})
onBeforeUnmount(() => resizeObserver?.disconnect())
</script>

<template>
  <div class="personal-dashboard">
    <div class="personal-dashboard__bar">
      <div class="personal-dashboard__profiles" aria-label="Профиль рабочего стола">
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
          Мой вид
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
        <span>Настроить вид</span>
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
      Перетащите виджет мышью, выберите размер S/M/L/XL или скройте ненужный блок. Сохранение применит layout и запишет его в локальное хранилище браузера.
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
        :ref="(element) => setWidgetElement(widget.id, element)"
        :class="['personal-dashboard__widget', `personal-dashboard__widget--${widget.size || 'medium'}`]"
        :style="{ gridRowEnd: `span ${widgetSpans[widget.id] || 1}` }"
        :draggable="editing"
        @dragstart="onDragStart(widget.id)"
        @dragover.prevent
        @drop.prevent="onDrop(widget.id)"
      >
        <div v-if="editing" class="personal-dashboard__widget-toolbar">
          <span><GripVertical :size="16" /> {{ widget.title }}</span>
          <div class="personal-dashboard__widget-tools">
            <div class="personal-dashboard__size-controls" role="group" :aria-label="`Размер виджета ${widget.title}`">
              <button
                v-for="size in sizeSequence"
                :key="`${widget.id}-${size}`"
                type="button"
                :class="{ 'personal-dashboard__size-control--active': widget.size === size }"
                :title="`Размер ${sizeLabels[size]}`"
                @click="setWidgetSize(widget.id, size)"
              >
                {{ sizeLabels[size] }}
              </button>
            </div>
            <button type="button" title="Следующий размер" @click="cycleSize(widget.id)">Размер</button>
            <button type="button" title="Скрыть виджет" @click="setVisible(widget.id, false)"><EyeOff :size="14" /> Скрыть</button>
          </div>
        </div>
        <slot :name="widget.id" :widget="widget" />
      </section>
    </div>
  </div>
</template>
