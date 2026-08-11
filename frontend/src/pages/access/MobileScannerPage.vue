<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import jsQR from 'jsqr'
import { Camera, FlipHorizontal, Flashlight, Keyboard, LogIn, LogOut, Maximize2, Minimize2, RotateCw, ScanLine, XCircle } from '@lucide/vue'
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
const backgrounded = ref(false)
const cameraLost = ref(false)
const torchEnabled = ref(false)
const torchSupported = ref(false)
const manualToken = ref('')
const manualOpen = ref(false)
const lastSentPayload = ref('')
const repeatHint = ref('')
const flash = ref('')
const scanIntervalMs = 350
/**
 * Пауза после отправленного скана — чтобы оператор успел прочитать результат.
 * Дубли она не сторожит: их отсекает проверка по самому коду, см. handleScan.
 */
const resumeDelayMs = 1000
const COMPACT_KEY = 'collegePortal.scanner.compact'
/**
 * Длинная сторона рабочего кадра для jsQR. Кадр камеры приходит 640×480 и
 * больше, а распознаванию столько не нужно: в память копируется каждый пиксель,
 * и на телефоне это главный расход батареи в непрерывном режиме.
 */
const WORK_SIZE = 384
/** Доля кадра под рамкой прицела. Разбирается ровно то, что видно в рамке. */
const ROI_RATIO = 0.72
/**
 * Раз в столько пустых кадров смотрим кадр целиком. Рамка помогает целиться, но
 * если оператор держит телефон боком, код окажется вне её — и без этой
 * подстраховки сканер молчал бы, а причина была бы не видна.
 */
const WIDE_SWEEP_EVERY = 8

const detector = createBarcodeDetector()
let scanTimer = null
let resumeTimer = null
let flashTimer = null
let audioContext = null
let wakeLock = null
let emptyFrames = 0

const compact = ref(loadCompact())
const resultClass = computed(() => store.lastEvent?.result === 'allowed' ? 'mobile-scanner-result--allowed' : 'mobile-scanner-result--denied')
const directionIcon = computed(() => store.lastEvent?.direction === 'out' ? LogOut : LogIn)
const resultIcon = computed(() => store.lastEvent?.result === 'allowed' ? directionIcon.value : XCircle)
const scannerEngine = computed(() => detector ? 'BarcodeDetector' : 'jsQR fallback')
const canTorch = computed(() => torchSupported.value && stream.value)
const isSecureContext = computed(() => Boolean(globalThis?.isSecureContext))
const hasCameraApi = computed(() => Boolean(navigator.mediaDevices?.getUserMedia))
const liveStatus = computed(() => {
  if (cameraLost.value) return 'Камера остановлена'
  if (backgrounded.value) return 'Свёрнуто, съёмка на паузе'
  if (paused.value) return 'Пауза после прохода'
  if (scanning.value) return 'Сканирование идёт'
  return cameraStatus.value
})

function createBarcodeDetector() {
  try {
    if (typeof window === 'undefined' || !('BarcodeDetector' in window)) return null
    return new window.BarcodeDetector({ formats: ['qr_code'] })
  } catch {
    return null
  }
}

function loadCompact() {
  try {
    return window.localStorage.getItem(COMPACT_KEY) === '1'
  } catch {
    return false
  }
}

function toggleCompact() {
  compact.value = !compact.value
  try {
    window.localStorage.setItem(COMPACT_KEY, compact.value ? '1' : '0')
  } catch {
    // Приватный режим браузера — выбор просто не переживёт перезагрузку.
  }
}

function vibrateAllowed() { navigator.vibrate?.(90) }
function vibrateDenied() { navigator.vibrate?.([80, 70, 80]) }
function beep(allowed = true) {
  try {
    audioContext ||= new AudioContext()
    // После возврата из фона контекст приходит приостановленным, и звук
    // молча пропадает — как раз в том сценарии, ради которого он и нужен.
    if (audioContext.state === 'suspended') audioContext.resume().catch(() => {})
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

/**
 * Экран не должен гаснуть, пока идёт съёмка: у турникета телефон лежит без
 * прикосновений, а блокировка обрывает поток камеры. Поддержки может не быть —
 * тогда просто работаем как раньше.
 */
async function requestWakeLock() {
  if (!('wakeLock' in navigator)) return
  try {
    wakeLock = await navigator.wakeLock.request('screen')
    wakeLock.addEventListener?.('release', () => { wakeLock = null })
  } catch {
    wakeLock = null
  }
}

async function releaseWakeLock() {
  try {
    await wakeLock?.release()
  } catch {
    // Уже отпущен системой.
  }
  wakeLock = null
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
    // Систему тоже надо слушать: другое приложение или спящий режим забирают
    // камеру молча, и без этого экран остаётся «сканирующим» навсегда.
    track.addEventListener('ended', handleTrackEnded)
    cameraStatus.value = 'Камера подключена'
    cameraLost.value = false
    scanning.value = true
    paused.value = false
    await requestWakeLock()
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
  stream.value?.getVideoTracks().forEach((track) => track.removeEventListener('ended', handleTrackEnded))
  stream.value?.getTracks().forEach((track) => track.stop())
  stream.value = null
  releaseWakeLock()
}

function handleTrackEnded() {
  cameraLost.value = true
  scanning.value = false
  cameraStatus.value = 'Камера остановлена системой'
  cameraError.value = 'Камера была занята другим приложением или отключена системой. Нажмите «Продолжить съёмку».'
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

/**
 * Кадр для распознавания: центральный квадрат под рамкой прицела, уменьшенный
 * до рабочего размера. Раньше в память копировался кадр целиком и в полном
 * разрешении — на телефоне это заметно и по нагреву, и по батарее.
 */
function readFromCanvas(video) {
  const canvas = canvasRef.value
  const wide = emptyFrames > 0 && emptyFrames % WIDE_SWEEP_EVERY === 0
  const shortSide = Math.min(video.videoWidth, video.videoHeight)
  const srcSide = wide ? shortSide : Math.round(shortSide * ROI_RATIO)
  const sx = Math.round((video.videoWidth - srcSide) / 2)
  const sy = Math.round((video.videoHeight - srcSide) / 2)
  const side = Math.min(WORK_SIZE, srcSide)

  if (canvas.width !== side || canvas.height !== side) {
    canvas.width = side
    canvas.height = side
  }

  const context = canvas.getContext('2d', { willReadFrequently: true })
  context.drawImage(video, sx, sy, srcSide, srcSide, 0, 0, side, side)
  const image = context.getImageData(0, 0, side, side)

  return jsQR(image.data, image.width, image.height)?.data || ''
}

async function scanLoop() {
  if (!scanning.value || paused.value || backgrounded.value || !videoRef.value || !canvasRef.value) return

  const startedAt = Date.now()
  const video = videoRef.value
  if (video.readyState >= 2 && video.videoWidth && video.videoHeight) {
    let value = ''
    if (detector) {
      const codes = await detector.detect(video).catch(() => [])
      value = codes[0]?.rawValue || ''
    } else {
      value = readFromCanvas(video)
    }

    emptyFrames = value ? 0 : emptyFrames + 1

    if (value) await handleScan(value)
  }

  if (scanning.value && !paused.value && !backgrounded.value) {
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
    showFlash(allowed ? 'allowed' : 'denied')
    allowed ? vibrateAllowed() : vibrateDenied()
    beep(allowed)
  } catch {
    showFlash('denied')
    vibrateDenied()
    beep(false)
  } finally {
    scheduleResume()
  }
}

/**
 * Цветная вспышка во весь кадр. Оператор держит телефон на вытянутой руке и
 * читать карточку под камерой ему некогда: решение должно быть видно боковым
 * зрением, а подробности — уже потом.
 */
function showFlash(kind) {
  flash.value = kind
  if (flashTimer) window.clearTimeout(flashTimer)
  flashTimer = window.setTimeout(() => { flash.value = '' }, resumeDelayMs)
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
  manualOpen.value = false
}

/**
 * Возврат из фона. Сюда приходят три разных случая: экран заблокировали,
 * приложение свернули, вкладку переключили. Во всех трёх браузер ставит видео
 * на паузу, а таймер продолжает тикать вхолостую по замершему кадру.
 */
async function handleVisibility() {
  if (document.hidden) {
    backgrounded.value = true
    if (scanTimer) window.clearTimeout(scanTimer)
    scanTimer = null
    return
  }

  backgrounded.value = false

  if (!stream.value) return

  const track = stream.value.getVideoTracks()[0]
  if (!track || track.readyState === 'ended') {
    // Систему уже не переубедить: поток закрыт, нужен новый.
    await startCamera(selectedDeviceId.value)
    return
  }

  await videoRef.value?.play().catch(() => {})
  await requestWakeLock()
  if (scanning.value && !paused.value) scanLoop()
}

onMounted(async () => {
  document.addEventListener('visibilitychange', handleVisibility)
  await loadCameras().catch(() => {})
  if (isSecureContext.value && hasCameraApi.value) {
    await startCamera()
  } else {
    cameraStatus.value = isSecureContext.value ? 'Камера недоступна' : 'Нужен HTTPS'
  }
})

onBeforeUnmount(() => {
  document.removeEventListener('visibilitychange', handleVisibility)
  if (flashTimer) window.clearTimeout(flashTimer)
  stopCamera()
})
</script>

<template>
  <AppPage :class="['mobile-scanner-page', { 'mobile-scanner-page--compact': compact }]">
    <PageHeader v-if="!compact" title="Мобильный сканер" subtitle="Сканирование QR-пропусков камерой телефона. Камера работает локально в браузере." />

    <AppErrorBanner v-if="!compact" :message="cameraError || store.error" />
    <q-banner v-if="!compact && (store.warning || repeatHint)" rounded class="access-gate-warning">{{ store.warning || repeatHint }}</q-banner>

    <div class="mobile-scanner-layout">
      <section class="mobile-scanner-camera-card">
        <div :class="['mobile-scanner-camera', flash ? `mobile-scanner-camera--${flash}` : '']">
          <video ref="videoRef" playsinline muted />
          <div class="mobile-scanner-frame" :style="{ '--cp-roi': ROI_RATIO }"><ScanLine :size="34" /><span>{{ liveStatus }}</span></div>
          <canvas ref="canvasRef" hidden />

          <!-- Итог поверх кадра: крупно, цветом, без прокрутки. -->
          <div v-if="store.lastEvent" :class="['mobile-scanner-overlay', store.lastEvent.result === 'allowed' ? 'mobile-scanner-overlay--allowed' : 'mobile-scanner-overlay--denied']">
            <component :is="resultIcon" :size="34" />
            <div>
              <strong>{{ outcomeHeadline(store.lastEvent) }}</strong>
              <span>{{ ownerName(store.lastEvent) }}</span>
            </div>
          </div>

          <button type="button" class="mobile-scanner-compact-toggle" :aria-label="compact ? 'Обычный режим' : 'Компактный режим'" @click="toggleCompact">
            <component :is="compact ? Minimize2 : Maximize2" :size="18" />
          </button>
        </div>

        <div class="mobile-scanner-controls">
          <q-btn v-if="!stream || cameraLost" color="primary" no-caps @click="startCamera()"><Camera :size="17" class="q-mr-xs" /> {{ cameraLost ? 'Продолжить съёмку' : 'Запустить камеру' }}</q-btn>
          <q-btn outline no-caps :disable="!stream" @click="switchCamera"><FlipHorizontal :size="17" class="q-mr-xs" /> Сменить</q-btn>
          <q-btn outline no-caps :disable="!canTorch" @click="toggleTorch"><Flashlight :size="17" class="q-mr-xs" /> {{ torchEnabled ? 'Выключить' : 'Фонарик' }}</q-btn>
          <q-btn v-if="compact" outline no-caps @click="manualOpen = true"><Keyboard :size="17" class="q-mr-xs" /> Ввести код</q-btn>
        </div>

        <p v-if="compact && (cameraError || store.error || store.warning || repeatHint)" class="mobile-scanner-compact-note">
          {{ cameraError || store.error || store.warning || repeatHint }}
        </p>

        <div v-if="!compact" class="mobile-scanner-meta">
          <AppStatusBadge :label="scannerEngine" tone="info" />
          <AppStatusBadge :label="isSecureContext ? 'Secure context' : 'Нужен HTTPS'" :tone="isSecureContext ? 'success' : 'warning'" />
          <AppStatusBadge :label="liveStatus" :tone="paused || backgrounded || cameraLost ? 'warning' : 'success'" />
        </div>
      </section>

      <AppCard v-if="!compact" :class="['mobile-scanner-result', store.lastEvent ? resultClass : 'mobile-scanner-result--idle']">
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

      <AppCard v-if="!compact" title="Ручной ввод" subtitle="Fallback, если камера недоступна или QR поврежден">
        <q-form class="mobile-scanner-manual" @submit.prevent="submitManual">
          <q-input
            v-model="manualToken"
            outlined
            label="Актуальный QR-код CP2"
            autocomplete="off"
            autocapitalize="off"
            spellcheck="false"
          ><template #prepend><Keyboard :size="20" /></template></q-input>
          <q-btn color="primary" type="submit" :loading="store.scanning" :disable="!manualToken.trim()">Проверить</q-btn>
        </q-form>
      </AppCard>
    </div>

    <!-- В компактном режиме ручной ввод не исчезает, а прячется в диалог:
         запасной путь обязан оставаться под рукой, когда камера подвела. -->
    <q-dialog v-model="manualOpen">
      <q-card class="mobile-scanner-manual-dialog">
        <q-card-section><div class="text-h6">Ручной ввод</div></q-card-section>
        <q-card-section>
          <q-form class="mobile-scanner-manual" @submit.prevent="submitManual">
            <q-input
              v-model="manualToken"
              outlined
              autofocus
              label="Актуальный QR-код CP2"
              autocomplete="off"
              autocapitalize="off"
              spellcheck="false"
            ><template #prepend><Keyboard :size="20" /></template></q-input>
            <q-btn color="primary" type="submit" :loading="store.scanning" :disable="!manualToken.trim()"><RotateCw :size="16" class="q-mr-xs" /> Проверить</q-btn>
          </q-form>
        </q-card-section>
      </q-card>
    </q-dialog>
  </AppPage>
</template>
