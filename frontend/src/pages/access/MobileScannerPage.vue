<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import jsQR from 'jsqr'
import { Camera, FlipHorizontal, Flashlight, Keyboard, LogIn, LogOut, ScanLine, XCircle } from '@lucide/vue'
import AppPage from '../../components/ui/AppPage.vue'
import PageHeader from '../../components/ui/PageHeader.vue'
import AppCard from '../../components/ui/AppCard.vue'
import AppErrorBanner from '../../components/ui/AppErrorBanner.vue'
import AppStatusBadge from '../../components/ui/AppStatusBadge.vue'
import { directionLabel, entityTypeLabel, formatEventTime, normalizeQrToken, outcomeDetail, outcomeHeadline, ownerName, resultLabel, resultTone, useAccessGateStore } from '../../stores/accessGate'

const store = useAccessGateStore()
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
const lastSentPayload = ref('')
const repeatHint = ref('')
const scanIntervalMs = 350
/**
 * Пауза после отправленного скана — чтобы оператор успел прочитать результат.
 * Дубли она не сторожит: их отсекает проверка по самому коду, см. handleScan.
 */
const resumeDelayMs = 1000
const detector = createBarcodeDetector()
let scanTimer = null
let resumeTimer = null
let audioContext = null

const resultClass = computed(() => store.lastEvent?.result === 'allowed' ? 'mobile-scanner-result--allowed' : 'mobile-scanner-result--denied')
const directionIcon = computed(() => store.lastEvent?.direction === 'out' ? LogOut : LogIn)
const resultIcon = computed(() => store.lastEvent?.result === 'allowed' ? directionIcon.value : XCircle)
const scannerEngine = computed(() => detector ? 'BarcodeDetector' : 'jsQR fallback')
const canTorch = computed(() => torchSupported.value && stream.value)
const isSecureContext = computed(() => Boolean(globalThis?.isSecureContext))
const hasCameraApi = computed(() => Boolean(navigator.mediaDevices?.getUserMedia))

function createBarcodeDetector() {
  try {
    if (typeof window === 'undefined' || !('BarcodeDetector' in window)) return null
    return new window.BarcodeDetector({ formats: ['qr_code'] })
  } catch {
    return null
  }
}

function vibrateAllowed() { navigator.vibrate?.(90) }
function vibrateDenied() { navigator.vibrate?.([80, 70, 80]) }
function beep(allowed = true) {
  try {
    audioContext ||= new AudioContext()
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

async function loadCameras() {
  if (!navigator.mediaDevices?.enumerateDevices) return
  const devices = await navigator.mediaDevices.enumerateDevices()
  cameras.value = devices.filter((device) => device.kind === 'videoinput')
}

async function startCamera(deviceId = selectedDeviceId.value) {
  cameraError.value = ''
  cameraStatus.value = 'Запрос доступа к камере...'
  stopCamera()

  if (!isSecureContext.value) {
    cameraError.value = `Камера доступна только через HTTPS. Откройте портал по адресу https://${window.location.hostname}.`
    cameraStatus.value = 'Нужен HTTPS'
    return
  }

  if (!hasCameraApi.value) {
    cameraError.value = 'Браузер не предоставил доступ к камере. Проверьте разрешения сайта и используйте HTTPS.'
    cameraStatus.value = 'Камера недоступна'
    return
  }

  try {
    const constraints = {
      video: deviceId
        ? { deviceId: { exact: deviceId } }
        : { facingMode: { ideal: 'environment' }, width: { ideal: 640 }, height: { ideal: 480 } },
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
  if (scanTimer) window.clearTimeout(scanTimer)
  if (resumeTimer) window.clearTimeout(resumeTimer)
  scanTimer = null
  resumeTimer = null
  paused.value = false
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
  if (!scanning.value || paused.value || !videoRef.value || !canvasRef.value) return

  const startedAt = Date.now()
  const video = videoRef.value
  if (video.readyState >= 2 && video.videoWidth && video.videoHeight) {
    let value = ''
    if (detector) {
      const codes = await detector.detect(video).catch(() => [])
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

  if (scanning.value && !paused.value) {
    scanTimer = window.setTimeout(scanLoop, Math.max(0, scanIntervalMs - (Date.now() - startedAt)))
  }
}

/**
 * Сканирование идёт непрерывно: после прохода не нужно ничего нажимать.
 *
 * Один и тот же код второй раз на сервер не уходит, и окно тут не по времени,
 * а по значению. Пропуск динамический и одноразовый: `CP2:<срок>:<подпись>`
 * живёт тридцать секунд, а сервер помечает предъявленный код использованным и
 * на повтор отвечает «QR-код уже использован». Телефон в кадре камера читает
 * несколько раз подряд, и по временному окну — как было до этого — повторы
 * после его истечения превратились бы в череду отказов в журнале и красный
 * экран у оператора сразу после успешного прохода. Код на телефоне обновляется
 * сам, и следующий скан того же человека приходит уже с новой подписью.
 */
async function handleScan(value) {
  const normalized = normalizeQrToken(value)
  if (!normalized) return
  if (normalized === lastSentPayload.value) {
    repeatHint.value = 'Этот код уже отсканирован. Попросите обновить QR на телефоне.'
    return
  }
  repeatHint.value = ''
  lastSentPayload.value = normalized
  paused.value = true
  try {
    const event = await store.scan(normalized, { access_point: 'Мобильный сканер', device_name: 'Mobile Camera Scanner' })
    const allowed = event?.result === 'allowed'
    allowed ? vibrateAllowed() : vibrateDenied()
    beep(allowed)
  } catch {
    vibrateDenied()
    beep(false)
  } finally {
    scheduleResume()
  }
}

function scheduleResume() {
  if (resumeTimer) window.clearTimeout(resumeTimer)
  resumeTimer = window.setTimeout(() => {
    resumeTimer = null
    // Снимаем паузу всегда, а цикл продолжаем только при работающей камере:
    // ручной ввод работает и без неё, и оставить экран в паузе навсегда нельзя.
    paused.value = false
    if (scanning.value) scanLoop()
  }, resumeDelayMs)
}

async function submitManual() {
  await handleScan(manualToken.value)
  manualToken.value = ''
}

onMounted(async () => {
  await loadCameras().catch(() => {})
  if (isSecureContext.value && hasCameraApi.value) {
    await startCamera()
  } else {
    cameraStatus.value = isSecureContext.value ? 'Камера недоступна' : 'Нужен HTTPS'
  }
})

onBeforeUnmount(stopCamera)
</script>

<template>
  <AppPage class="mobile-scanner-page">
    <PageHeader title="Мобильный сканер" subtitle="Сканирование QR-пропусков камерой телефона. Камера работает локально в браузере." />

    <AppErrorBanner :message="cameraError || store.error" />
    <q-banner v-if="store.warning || repeatHint" rounded class="access-gate-warning">{{ store.warning || repeatHint }}</q-banner>

    <div class="mobile-scanner-layout">
      <section class="mobile-scanner-camera-card">
        <div class="mobile-scanner-camera">
          <video ref="videoRef" playsinline muted />
          <div class="mobile-scanner-frame"><ScanLine :size="34" /><span>{{ cameraStatus }}</span></div>
          <canvas ref="canvasRef" hidden />
        </div>

        <div class="mobile-scanner-controls">
          <q-btn v-if="!stream" color="primary" no-caps @click="startCamera()"><Camera :size="17" class="q-mr-xs" /> Запустить камеру</q-btn>
          <q-btn outline no-caps :disable="!stream" @click="switchCamera"><FlipHorizontal :size="17" class="q-mr-xs" /> Сменить</q-btn>
          <q-btn outline no-caps :disable="!canTorch" @click="toggleTorch"><Flashlight :size="17" class="q-mr-xs" /> {{ torchEnabled ? 'Выключить' : 'Фонарик' }}</q-btn>
        </div>

        <div class="mobile-scanner-meta">
          <AppStatusBadge :label="scannerEngine" tone="info" />
          <AppStatusBadge :label="isSecureContext ? 'Secure context' : 'Нужен HTTPS'" :tone="isSecureContext ? 'success' : 'warning'" />
          <AppStatusBadge :label="paused ? 'Пауза после прохода' : 'Сканирование идёт'" :tone="paused ? 'warning' : 'success'" />
        </div>
      </section>

      <AppCard :class="['mobile-scanner-result', store.lastEvent ? resultClass : 'mobile-scanner-result--idle']">
        <template v-if="store.lastEvent">
          <div class="mobile-scanner-result__status">
            <component :is="resultIcon" :size="54" />
            <div><strong>{{ outcomeHeadline(store.lastEvent) }}</strong><span>{{ outcomeDetail(store.lastEvent) }}</span></div>
          </div>
          <h2>{{ ownerName(store.lastEvent) }}</h2>
          <p>{{ entityTypeLabel(store.lastEvent.entity_type) }}</p>
          <div class="mobile-scanner-result__badges">
            <AppStatusBadge :label="directionLabel(store.lastEvent.direction)" :tone="store.lastEvent.direction === 'in' ? 'success' : 'warning'" />
            <AppStatusBadge :label="resultLabel(store.lastEvent.result)" :tone="resultTone(store.lastEvent.result)" />
          </div>
          <dl>
            <div><dt>Время</dt><dd>{{ formatEventTime(store.lastEvent.event_time) }}</dd></div>
            <div><dt>Причина отказа</dt><dd>{{ store.lastEvent.reason || '—' }}</dd></div>
          </dl>
        </template>
        <div v-else class="mobile-scanner-result__empty"><ScanLine :size="48" /><strong>Ожидание QR</strong><span>После распознавания здесь появится результат прохода.</span></div>
      </AppCard>

      <AppCard title="Ручной ввод" subtitle="Fallback, если камера недоступна или QR поврежден">
        <q-form class="mobile-scanner-manual" @submit.prevent="submitManual">
          <q-input v-model="manualToken" outlined label="Актуальный QR-код CP2" autocomplete="off"><template #prepend><Keyboard :size="20" /></template></q-input>
          <q-btn color="primary" type="submit" :loading="store.scanning" :disable="!manualToken.trim()">Проверить</q-btn>
        </q-form>
      </AppCard>
    </div>
  </AppPage>
</template>
