<script setup>
import { onMounted, onBeforeUnmount, ref, watch, computed } from 'vue'

const props = defineProps({
  steamIds: { type: Array, required: true },
  apiUrl:   { type: String, default: '/api/get_inventory.php' },
  labels:   { type: Array, default: () => [] }
})

const canvasRef = ref(null)
let ctx = null

const resultText = ref('—')
const rotation = ref(0)
const spinning = ref(false)
const loading  = ref(false)
const loadingPrices = ref(false)
const showWin  = ref(false)
const winColor = ref('')
const winIndex = ref(-1)

const rawItems = ref([])
const pattern = ref([])        // noms affichés répétés selon amount (filtré)
const colors  = ref({})        // nom -> couleur
const prices  = ref({})        // market_hash_name -> { price, currency }
const currentSteamId = ref(props.steamIds?.[0] ?? '')
const currentFilter  = ref('all')

const KEY_PRICE = 2.35  // Prix fixe clé CS2 en EUR (in-game)

const size = 550
let dpr = 1
let rafId = 0

// --- Helpers pour accéder aux items ---
// Clé unique d'un item (market_hash_name si dispo, sinon name)
function itemKey(item) {
  return item.market_hash_name || item.name
}
// Retrouver un item par sa clé
function itemByKey(key) {
  return rawItems.value.find(i => itemKey(i) === key)
}
// Nom d'affichage d'un item
function displayName(item) {
  return item?.name || item?.market_hash_name || '?'
}

// --- Son de tick ---
let audioCtx = null
let lastTickTime = 0
const MIN_TICK_INTERVAL = 45

function initAudio() {
  if (audioCtx) return
  audioCtx = new (window.AudioContext || window.webkitAudioContext)()
}

function playTick() {
  try {
    if (!audioCtx) return
    const now = performance.now()
    if (now - lastTickTime < MIN_TICK_INTERVAL) return
    lastTickTime = now
    if (audioCtx.state === 'suspended') audioCtx.resume()
    const t = audioCtx.currentTime
    const osc  = audioCtx.createOscillator()
    const gain = audioCtx.createGain()
    osc.connect(gain)
    gain.connect(audioCtx.destination)
    osc.type = 'square'
    osc.frequency.setValueAtTime(2200, t)
    osc.frequency.exponentialRampToValueAtTime(400, t + 0.04)
    gain.gain.setValueAtTime(0.4, t)
    gain.gain.exponentialRampToValueAtTime(0.001, t + 0.08)
    osc.start(t)
    osc.stop(t + 0.08)
  } catch { /* fail silently */ }
}

function playWinSound() {
  try {
    if (!audioCtx) return
    if (audioCtx.state === 'suspended') audioCtx.resume()
    const t = audioCtx.currentTime
    const notes = [523, 659, 784, 1047]
    notes.forEach((freq, i) => {
      const osc  = audioCtx.createOscillator()
      const gain = audioCtx.createGain()
      osc.connect(gain)
      gain.connect(audioCtx.destination)
      osc.type = 'sine'
      osc.frequency.setValueAtTime(freq, t + i * 0.12)
      gain.gain.setValueAtTime(0, t)
      gain.gain.linearRampToValueAtTime(0.3, t + i * 0.12)
      gain.gain.exponentialRampToValueAtTime(0.001, t + i * 0.12 + 0.25)
      osc.start(t + i * 0.12)
      osc.stop(t + i * 0.12 + 0.25)
    })
  } catch { /* fail silently */ }
}

const palette = [
  '#49C9B3', '#FFD700', '#AE81FF', '#00FF88', '#FF934F',
  '#FF4D8E', '#5BC0EB', '#E55934', '#B06AB3', '#00CED1'
]

function generateColors(newKeys){
  newKeys.forEach((key, i) => {
    if (!colors.value[key]) {
      colors.value[key] = palette[i % palette.length]
    }
  })
}

// --- Catégories disponibles dynamiquement ---
const availableFilters = computed(() => {
  const types = new Set(rawItems.value.map(i => i.type))
  const filters = [{ value: 'all', label: 'Tout' }]
  if (types.has('case'))     filters.push({ value: 'case',     label: 'Caisses' })
  if (types.has('souvenir')) filters.push({ value: 'souvenir', label: 'Souvenirs' })
  if (types.has('sticker'))  filters.push({ value: 'sticker',  label: 'Stickers' })
  return filters
})

// --- Item gagnant ---
const winnerItem = computed(() => {
  if (winIndex.value < 0) return null
  const key = pattern.value[winIndex.value]
  return itemByKey(key)
})

const winnerPrice = computed(() => {
  if (!winnerItem.value) return null
  const mhn = winnerItem.value.market_hash_name
  if (!mhn) return null
  return prices.value[mhn] ?? null
})

const winnerTotalCost = computed(() => {
  if (!winnerItem.value || !winnerPrice.value?.price) return null
  const casePrice = winnerPrice.value.price
  const needsKey = winnerItem.value.needs_key === true
  if (needsKey) {
    return { casePrice, keyPrice: KEY_PRICE, total: Math.round((casePrice + KEY_PRICE) * 100) / 100 }
  }
  return { casePrice, keyPrice: 0, total: casePrice }
})

// --- Reconstruire le pattern quand le filtre change ---
function applyFilter() {
  const filtered = currentFilter.value === 'all'
    ? rawItems.value
    : rawItems.value.filter(item => item.type === currentFilter.value)

  pattern.value = []
  const newKeys = []
  filtered.forEach(item => {
    const key = itemKey(item)
    if (!colors.value[key]) newKeys.push(key)
    for (let i = 0; i < (item.amount || 0); i++) {
      pattern.value.push(key)
    }
  })
  generateColors(newKeys)
  shufflePattern()

  winIndex.value = -1
  resultText.value = '—'
  winColor.value = ''
  rotation.value = 0
}

function setupHiDPI() {
  const canvas = canvasRef.value
  if (!canvas) return
  dpr = window.devicePixelRatio || 1
  canvas.width  = size * dpr
  canvas.height = size * dpr
  ctx = canvas.getContext('2d')
  ctx.setTransform(1, 0, 0, 1, 0, 0)
  ctx.scale(dpr, dpr)
}

function drawWheel(angle = rotation.value){
  const canvas = canvasRef.value
  if (!canvas || !ctx) return
  const N = pattern.value.length
  const ARC = Math.PI * 2 / (N || 1)
  const r = size / 2

  ctx.clearRect(0, 0, size, size)
  if (!N) return

  for (let i = 0; i < N; i++){
    const key = pattern.value[i]
    const item = itemByKey(key)
    const name = displayName(item)
    const a0 = angle + i * ARC
    const a1 = a0 + ARC
    ctx.beginPath()
    ctx.moveTo(r, r)
    ctx.arc(r, r, r - 14, a0, a1)
    ctx.closePath()
    ctx.fillStyle = colors.value[key] || '#555'
    ctx.fill()
    ctx.strokeStyle = '#000'
    ctx.lineWidth = 1
    ctx.stroke()

    ctx.save()
    ctx.translate(r, r)
    ctx.rotate(a0 + ARC / 2)
    ctx.fillStyle = '#fff'
    ctx.font = 'bold 14px Arial'
    ctx.textAlign = 'right'

    // Afficher le nom + prix si disponible
    const mhn = item?.market_hash_name
    const p = mhn ? prices.value[mhn] : null
    let label = String(name).slice(0, 18)
    if (p?.price != null) {
      label = `${label}  ${p.price}€`
    }
    ctx.fillText(label, r - 24, 5)
    ctx.restore()
  }
}

function shufflePattern(){
  for (let i = pattern.value.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1))
    ;[pattern.value[i], pattern.value[j]] = [pattern.value[j], pattern.value[i]]
  }
  drawWheel()
}

function showResult(){
  const N = pattern.value.length
  if (!N) return
  const ARC = Math.PI * 2 / N
  const angleUnderPointer =
    (2 * Math.PI - ((rotation.value + Math.PI/2) % (2 * Math.PI))) % (2 * Math.PI)
  const index = Math.floor(angleUnderPointer / ARC) % N
  const key = pattern.value[index]
  const item = itemByKey(key)
  resultText.value = displayName(item)
  winIndex.value = index
  winColor.value = colors.value[key] || '#ff4d8e'
  playWinSound()
  showWin.value = true
  setTimeout(() => { showWin.value = false }, 2500)
}

function removeWinner() {
  if (winIndex.value < 0 || spinning.value) return
  pattern.value.splice(winIndex.value, 1)
  winIndex.value = -1
  resultText.value = '—'
  winColor.value = ''
  rotation.value = 0
  drawWheel()
}

function refresh() {
  if (spinning.value) return
  winIndex.value = -1
  resultText.value = '—'
  winColor.value = ''
  rotation.value = 0
  fetchInventory()
}

function spin(){
  if (spinning.value || pattern.value.length === 0) return
  spinning.value = true
  resultText.value = '—'
  winIndex.value = -1
  winColor.value = ''
  initAudio()

  const N = pattern.value.length
  const ARC = Math.PI * 2 / N
  const start = rotation.value
  const extraDeg = Math.random() * 360
  const target = start
    + (Math.PI * 2 * (5 + Math.random() * 4))
    + (extraDeg * Math.PI / 180)

  let lastCrossing = Math.floor(start / ARC)
  const startT = performance.now()
  const DUR = 4500

  const animate = (t) => {
    const p = Math.min((t - startT) / DUR, 1)
    const ease = 1 - Math.pow(1 - p, 3)
    rotation.value = start + (target - start) * ease
    drawWheel(rotation.value)

    const currentCrossing = Math.floor(rotation.value / ARC)
    if (currentCrossing !== lastCrossing) {
      playTick()
      lastCrossing = currentCrossing
    }

    if (p < 1) {
      rafId = requestAnimationFrame(animate)
    } else {
      rotation.value %= Math.PI * 2
      showResult()
      spinning.value = false
    }
  }
  rafId = requestAnimationFrame(animate)
}

// --- Fetch des prix depuis le Steam Market ---
async function fetchPrices() {
  // On ne fetch que si on a des market_hash_name
  const withHash = rawItems.value.filter(i => i.market_hash_name)
  if (withHash.length === 0) return
  loadingPrices.value = true
  try {
    const names = withHash.map(i => i.market_hash_name).join(',')
    const r = await fetch(`/api/get_prices.php?names=${encodeURIComponent(names)}`)
    const data = await r.json()
    if (data && typeof data === 'object' && !data.error) {
      prices.value = data
      drawWheel()
    }
  } catch (e) {
    console.warn('fetchPrices échoué:', e)
  } finally {
    loadingPrices.value = false
  }
}

async function fetchInventory(){
  loading.value = true
  try {
    const url = `${props.apiUrl}?steamid=${encodeURIComponent(currentSteamId.value)}`
    const r = await fetch(url)
    const data = await r.json()
    if (!Array.isArray(data)) throw new Error('format inattendu')
    rawItems.value = data
    applyFilter()
    // Fetch les prix en arrière-plan
    fetchPrices()
  } catch (e) {
    console.warn('fetchInventory échoué:', e)
    try {
      const r2 = await fetch('/mock_inventory.json')
      const d2 = await r2.json()
      rawItems.value = d2
      applyFilter()
    } catch {/* ignore */}
  } finally {
    loading.value = false
  }
}

function switchId(id){
  if (spinning.value) return
  currentSteamId.value = id
  rotation.value = 0
}

function labelFor(id){
  const idx = props.steamIds.indexOf(id)
  if (idx >= 0 && props.labels[idx]) return props.labels[idx]
  return idx >= 0 ? `Inventaire ${idx + 1}` : id
}

function formatPrice(val) {
  if (val == null) return '—'
  return val.toFixed(2) + '€'
}

function onResize(){
  setupHiDPI()
  drawWheel()
}

onMounted(() => {
  setupHiDPI()
  drawWheel()
  fetchInventory()
  window.addEventListener('resize', onResize)
})

onBeforeUnmount(() => {
  if (rafId) cancelAnimationFrame(rafId)
  window.removeEventListener('resize', onResize)
  if (audioCtx) audioCtx.close()
})

watch(currentSteamId, () => fetchInventory())
watch(currentFilter, () => applyFilter())
</script>

<template>
  <div class="wheel-page">
    <div class="wheel-wrapper">
      <canvas ref="canvasRef" aria-label="Roue de la fortune"></canvas>
      <div class="pointer"></div>
      <div v-if="loading" class="wheel-loading">
        <i class="fas fa-spinner fa-spin"></i> Chargement…
      </div>
    </div>

    <div class="controls">
      <button @click="spin" :disabled="spinning || loading || !pattern.length">Lancer</button>
      <button @click="shufflePattern" :disabled="loading || !pattern.length">Mélanger</button>
      <button @click="refresh" :disabled="spinning || loading" title="Recharger l'inventaire">
        <i class="fas fa-sync-alt"></i>
      </button>

      <select
        class="steam-select"
        :value="currentSteamId"
        @change="switchId($event.target.value)"
        :disabled="spinning"
      >
        <option v-for="id in steamIds" :key="id" :value="id">
          {{ labelFor(id) }}
        </option>
      </select>

      <select
        v-if="availableFilters.length > 1"
        class="steam-select"
        v-model="currentFilter"
        :disabled="spinning"
      >
        <option v-for="f in availableFilters" :key="f.value" :value="f.value">
          {{ f.label }}
        </option>
      </select>
    </div>

    <!-- Badge de chargement prix -->
    <div v-if="loadingPrices" class="price-loading">
      <i class="fas fa-coins"></i> Chargement des prix…
    </div>

    <!-- Résultat -->
    <div
      class="wheel-result"
      :class="{ 'wheel-result--win': showWin, 'wheel-result--has-winner': winColor && !showWin }"
      :style="winColor ? { '--win-color': winColor } : {}"
    >
      <template v-if="showWin">
        <span class="win-icon">🎉</span>
        <span class="win-text">{{ resultText }}</span>
        <span class="win-icon">🎉</span>
      </template>
      <template v-else>
        Résultat : {{ resultText }}
      </template>
    </div>

    <!-- Carte de prix du gagnant -->
    <div v-if="winnerItem && !showWin" class="winner-price-card">
      <div class="winner-price-card__header">
        <img
          v-if="winnerItem.image"
          :src="winnerItem.image"
          :alt="winnerItem.name"
          class="winner-price-card__img"
        />
        <div class="winner-price-card__info">
          <span class="winner-price-card__name">{{ winnerItem.name }}</span>
          <span class="winner-price-card__type">
            {{ winnerItem.type === 'case' ? '📦 Caisse' : winnerItem.type === 'souvenir' ? '🎁 Souvenir' : '🏷️ Sticker' }}
            <span v-if="winnerItem.needs_key" class="needs-key-badge">🔑 Clé requise</span>
          </span>
        </div>
      </div>

      <div v-if="winnerTotalCost" class="winner-price-card__prices">
        <div class="price-row">
          <span class="price-label">
            {{ winnerItem.type === 'case' ? 'Caisse' : winnerItem.type === 'souvenir' ? 'Package' : 'Capsule' }}
          </span>
          <span class="price-value">{{ formatPrice(winnerTotalCost.casePrice) }}</span>
        </div>
        <div v-if="winnerItem.needs_key" class="price-row price-row--key">
          <span class="price-label">🔑 Clé</span>
          <span class="price-value">{{ formatPrice(winnerTotalCost.keyPrice) }}</span>
        </div>
        <div v-if="winnerItem.needs_key" class="price-row price-row--total">
          <span class="price-label">Total</span>
          <span class="price-value price-value--total">{{ formatPrice(winnerTotalCost.total) }}</span>
        </div>
      </div>
      <div v-else-if="!loadingPrices" class="winner-price-card__no-price">
        Prix indisponible
      </div>
    </div>

    <button
      v-if="winIndex >= 0 && !spinning"
      class="remove-winner-btn"
      @click="removeWinner"
    >
      <i class="fas fa-trash-alt"></i> Retirer « {{ resultText }} »
    </button>
  </div>
</template>
