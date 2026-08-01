<script setup>
import { computed, nextTick, onBeforeUnmount, ref } from 'vue'
import jsQR from 'jsqr'
import { Camera, CheckCircle2, FlipHorizontal, Flashlight, Keyboard, Play, ScanLine, XCircle } from '@lucide/vue'
import AppPage from '../../components/ui/AppPage.vue'
import PageHeader from '../../components/ui/PageHeader.vue'
import AppCard from '../../components/ui/AppCard.vue'
import AppErrorBanner from '../../components/ui/AppErrorBanner.vue'
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'
import { usePermissions } from '../../composables/usePermissions'
import { directionLabel, entityTypeLabel, formatEventTime, normalizeQrToken, ownerName, resultLabel, resultTone, useAccessGateStore } from '../../stores/accessGate'

const store = useAccessGateStore()
const { hasPermission } = usePermissions()
const videoRef = ref(null)
const canvasRef = ref(null)
const stream = ref(null)
const cameras = ref([])
const selectedDeviceId = ref('')
const cameraStatus = ref('Камера не запущена')
const cameraError = ref('')
const scanning = ref(false)
const paused = ref(false)
const torchEnabled = ref(false)
const torchSupported = ref(false)
const manualToken = ref('')
const lastScannedValue = ref('')
const lastScanAt = ref(0)
const scanCooldownMs = 2200
const detector = ref(null)
const secureContext = computed(() => typeof window !== 'undefined' && Boolean(window.isSecureContext))
let animationFrame = null
let audioContext = null

const resultClass = computed(() => store.lastEvent?.result === 'allowed' ? 'mobile-scanner-result--allowed' : 'mobile-scanner-result--denied')
const resultIcon = computed(() => store.lastEvent?.result === 'allowed' ? CheckCircle2 : XCircle)
const scannerEngine = computed(() => detector.value ? 'BarcodeDetector' : 'jsQR fallback')
const canTorch = computed(() => torchSupported.value && stream.value)
const canManualOverride = computed(() => hasPermission('access.override'))

function vibrateAllowed() { navigator.vibrate?.(90) }
function vibrateDenied() { navigator.vibrate?.([80, 70, 80]) }
function beep(allowed = true) {
  try {
    const AudioCtor = window.AudioContext || window.webkitAudioContext
    if (!AudioCtor) return
    audioContext ||= new AudioCtor()
    const oscillator = audioContext.createOscillator()
    const gain = audioContext.createGain()
    oscillator.frequency.value = allowed ? 880 : 220
    oscillator.type = 'sine'
    gain.gain.value = 0.05
    oscillator.connect(gain)
    gain.connect(audioContext.destination)
    oscillator.start()
    oscillator.stop(audioContext.currentTime + 0.12)
  } catch {
    // Звук может быть заблокирован браузером до пользовательского действия.
  }
}

function initDetector() {
  if (detector.value || typeof window === 'undefined' || !('BarcodeDetector' in window)) return
  try {
    detector.value = new window.BarcodeDetector({ formats: ['qr_code'] })
  } catch {
    detector.value = null
  }
}

async function loadCameras() {
  if (!navigator.mediaDevices?.enumerateDevices) return
  const devices = await navigator.mediaDevices.enumerateDevices()
  cameras.value = devices.filter((device) => device.kind === 'videoinput')
}

async function startCamera(deviceId = selectedDeviceId.value) {
  cameraError.value = ''
  cameraStatus.value = 'Запрос доступа к камере...'
  stopCamera()

  try {
    if (!navigator.mediaDevices?.getUserMedia) {
      throw new Error('Браузер не предоставляет доступ к камере. Проверьте HTTPS и разрешения.')
    }
    initDetector()
    const constraints = {
      video: deviceId
        ? { deviceId: { exact: deviceId } }
        : { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } },
      audio: false,
    }
    stream.value = await navigator.mediaDevices.getUserMedia(constraints)
    await nextTick()
    videoRef.value.srcObject = stream.value
    await videoRef.value.play()
    const track = stream.value.getVideoTracks()[0]
    selectedDeviceId.value = track.getSettings().deviceId || deviceId || ''
    torchSupported.value = Boolean(track.getCapabilities?.().torch)
    cameraStatus.value = 'Камера подключена'
    scanning.value = true
    paused.value = false
    await loadCameras()
    scanLoop()
  } catch (error) {
    cameraError.value = error?.message || 'Камера недоступна. Проверьте HTTPS и разрешение браузера.'
    cameraStatus.value = 'Камера недоступна'
  }
}

function stopCamera() {
  if (animationFrame) cancelAnimationFrame(animationFrame)
  animationFrame = null
  scanning.value = false
  torchEnabled.value = false
  stream.value?.getTracks().forEach((track) => track.stop())
  stream.value = null
}

async function switchCamera() {
  await loadCameras()
  if (!cameras.value.length) return
  const currentIndex = cameras.value.findIndex((camera) => camera.deviceId === selectedDeviceId.value)
  const next = cameras.value[(currentIndex + 1) % cameras.value.length]
  selectedDeviceId.value = next.deviceId
  await startCamera(next.deviceId)
}

async function toggleTorch() {
  const track = stream.value?.getVideoTracks()[0]
  if (!track?.applyConstraints || !torchSupported.value) return
  torchEnabled.value = !torchEnabled.value
  await track.applyConstraints({ advanced: [{ torch: torchEnabled.value }] })
}

async function scanLoop() {
  if (!scanning.value || paused.value || !videoRef.value || !canvasRef.value) {
    animationFrame = requestAnimationFrame(scanLoop)
    return
  }

  const video = videoRef.value
  if (video.readyState >= 2 && video.videoWidth && video.videoHeight) {
    let value = ''
    if (detector.value) {
      const codes = await detector.value.detect(video).catch(() => [])
      value = codes[0]?.rawValue || ''
    } else {
      const canvas = canvasRef.value
      const context = canvas.getContext('2d', { willReadFrequently: true })
      canvas.width = video.videoWidth
      canvas.height = video.videoHeight
      context.drawImage(video, 0, 0, canvas.width, canvas.height)
      const image = context.getImageData(0, 0, canvas.width, canvas.height)
      value = jsQR(image.data, image.width, image.height)?.data || ''
    }

    if (value) await handleScan(value)
  }

  animationFrame = requestAnimationFrame(scanLoop)
}

async function handleScan(value) {
  const normalized = normalizeQrToken(value)
  const now = Date.now()
  if (!normalized) return
  if (normalized === lastScannedValue.value && now - lastScanAt.value < scanCooldownMs) return
  lastScannedValue.value = normalized
  lastScanAt.value = now
  paused.value = true
  try {
    const event = await store.scan(normalized, { access_point: 'Мобильный сканер', device_name: 'Mobile Camera Scanner', device_type: 'mobile_camera' })
    const allowed = event?.result === 'allowed'
    allowed ? vibrateAllowed() : vibrateDenied()
    beep(allowed)
  } catch {
    vibrateDenied()
    beep(false)
  }
}

async function submitManual() {
  await handleScan(manualToken.value)
  manualToken.value = ''
}

function scanAgain() {
  paused.value = false
  store.warning = ''
}

onBeforeUnmount(stopCamera)
</script>

<template>
  <AppPage>
    <PageHeader title="Мобильный сканер" subtitle="Сканирование QR-пропусков камерой телефона. Камера работает локально в браузере." />

    <AppErrorBanner :message="cameraError || store.error" />
    <q-banner v-if="store.warning" rounded class="access-gate-warning">{{ store.warning }}</q-banner>

    <div class="mobile-scanner-layout">
      <section class="mobile-scanner-camera-card">
        <div class="mobile-scanner-camera">
          <video ref="videoRef" playsinline muted />
          <div class="mobile-scanner-frame"><ScanLine :size="34" /><span>{{ cameraStatus }}</span></div>
          <canvas ref="canvasRef" hidden />
        </div>

        <div class="mobile-scanner-controls">
          <q-btn color="primary" no-caps @click="startCamera()"><Camera :size="17" class="q-mr-xs" /> Запустить камеру</q-btn>
          <q-btn outline no-caps :disable="!stream" @click="switchCamera"><FlipHorizontal :size="17" class="q-mr-xs" /> Сменить</q-btn>
          <q-btn outline no-caps :disable="!canTorch" @click="toggleTorch"><Flashlight :size="17" class="q-mr-xs" /> {{ torchEnabled ? 'Выключить' : 'Фонарик' }}</q-btn>
        </div>

        <div class="mobile-scanner-meta">
          <AppStatusBadge :label="scannerEngine" tone="info" />
          <AppStatusBadge :label="secureContext ? 'Secure context' : 'Нужен HTTPS'" :tone="secureContext ? 'success' : 'warning'" />
          <AppStatusBadge :label="paused ? 'Пауза после скана' : 'Готов к сканированию'" :tone="paused ? 'warning' : 'success'" />
        </div>
      </section>

      <AppCard :class="['mobile-scanner-result', store.lastEvent ? resultClass : 'mobile-scanner-result--idle']">
        <template v-if="store.lastEvent">
          <div class="mobile-scanner-result__status">
            <component :is="resultIcon" :size="54" />
            <div><strong>{{ resultLabel(store.lastEvent.result) }}</strong><span>{{ store.lastEvent.reason || 'Проход зарегистрирован.' }}</span></div>
          </div>
          <h2>{{ ownerName(store.lastEvent) }}</h2>
          <p>{{ entityTypeLabel(store.lastEvent.entity_type, store.lastEvent) }}</p>
          <div class="mobile-scanner-result__badges">
            <AppStatusBadge :label="directionLabel(store.lastEvent.direction)" :tone="(store.lastEvent.direction === 'entry' || store.lastEvent.direction === 'in') ? 'success' : 'warning'" />
            <AppStatusBadge :label="resultLabel(store.lastEvent.result)" :tone="resultTone(store.lastEvent.result)" />
          </div>
          <dl>
            <div><dt>Время</dt><dd>{{ formatEventTime(store.lastEvent.event_time) }}</dd></div>
            <div><dt>Причина отказа</dt><dd>{{ store.lastEvent.reason || '—' }}</dd></div>
          </dl>
          <q-btn color="primary" no-caps @click="scanAgain"><Play :size="17" class="q-mr-xs" /> Сканировать снова</q-btn>
        </template>
        <div v-else class="mobile-scanner-result__empty"><ScanLine :size="48" /><strong>Ожидание QR</strong><span>После распознавания здесь появится результат прохода.</span></div>
      </AppCard>

      <AppCard v-if="canManualOverride" title="Ручной ввод" subtitle="Fallback с отдельным правом access.override">
        <q-form class="mobile-scanner-manual" @submit.prevent="submitManual">
          <q-input v-model="manualToken" outlined label="Token или CP1:<token>" autocomplete="off"><template #prepend><Keyboard :size="20" /></template></q-input>
          <q-btn color="primary" type="submit" :loading="store.scanning" :disable="!manualToken.trim()">Проверить</q-btn>
        </q-form>
      </AppCard>
    </div>
  </AppPage>
</template>
