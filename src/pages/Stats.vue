<script setup>
import { ref, computed, onMounted } from 'vue'

const sheets = ref([])
const sheetsData = ref({})
const selectedId = ref(null)
const loading = ref(true)
const loadingSheet = ref(false)
const loadingPrices = ref(false)
const priceError = ref(null)
const priceDebug = ref({ resolved: 0, fetched: 0, succeeded: 0, failed: 0, errors: [] })
const activeTab = ref('case')

const progress = ref({ loaded: 0, total: 0, failed: 0 })

// Prix récupérés : { marketHashName: { price, currency } }
const prices = ref({})
const KEY_PRICE = 2.19 // EUR, prix in-game

// Filtre global
const filterOpen = ref(false)
const selectedTypes = ref(['all'])

const typeLabels = {
  all:        'Ensemble',
  case:       'Caisses',
  souvenir:   'Souvenirs',
  sticker:    'Stickers',
  charm:      'Porte-bonheurs',
  collection: 'Collections',
  terminal:   'Terminal',
}

// Caisses qui n'ont pas "case"/"caisse" dans le nom
const specialCases = ['cs20', 'saturation', 'zone de danger']
// Collections "Terminal" (opérations spéciales)
const terminalCollections = ['genèse', 'genese']

function categorizeSheet(name) {
  const l = name.toLowerCase()
  if (specialCases.some(s => l.includes(s))) return 'case'
  if (terminalCollections.some(s => l.includes(s))) return 'terminal'
  if (l.includes('souvenir')) return 'souvenir'
  if (l.includes('case') || l.includes('caisse')) return 'case'
  if (l.includes('sticker') || l.includes('capsule') || l.includes('sugarface')) return 'sticker'
  if (l.includes('porte-bonheur')) return 'charm'
  return 'collection'
}

/** Convertit un nom de sheet (souvent FR) en market_hash_name Steam (EN) */
function toMarketHashName(sheetName) {
  const l = sheetName.toLowerCase().trim()

  // Dictionnaire FR → EN (exact match sur lowercase)
  const map = {
    // === CAISSES ===
    'caisse de fièvre':                      'Fever Case',
    'caisse fièvre':                         'Fever Case',
    'fever':                                 'Fever Case',

    "caisse d'armes envenimée ( snakebite )": 'Snakebite Case',
    "caisse d'armes envenimée (snakebite)":   'Snakebite Case',
    'caisse morsure':                         'Snakebite Case',
    'snakebite':                              'Snakebite Case',

    "caisse de l'opération riptide":          'Operation Riptide Case',
    'caisse opération riptide':               'Operation Riptide Case',
    'operation riptide':                      'Operation Riptide Case',

    'caisse prisma':                          'Prisma Case',
    'caisse prisma 2':                        'Prisma 2 Case',

    'caisse spectrale':                       'Spectrum Case',
    'caisse spectrale n°2':                   'Spectrum 2 Case',
    "caisse spectrale n'2":                   'Spectrum 2 Case',
    'caisse spectre':                         'Spectrum Case',
    'caisse spectre 2':                       'Spectrum 2 Case',

    'caisse winter offensive':                'Winter Offensive Weapon Case',
    'caisse offensive hivernale':             'Winter Offensive Weapon Case',

    'caisse kilowatt':                        'Kilowatt Case',
    'kilowatt':                               'Kilowatt Case',
    'caisse revolution':                      'Revolution Case',
    'caisse révolution':                      'Revolution Case',
    'revolution':                             'Revolution Case',
    'caisse recul':                           'Recoil Case',
    'recoil':                                 'Recoil Case',
    'caisse fracture':                        'Fracture Case',
    'fracture':                               'Fracture Case',

    'caisse rêves et cauchemars':             'Dreams & Nightmares Case',
    'rêves et cauchemars':                    'Dreams & Nightmares Case',
    'dreams & nightmares':                    'Dreams & Nightmares Case',

    'caisse galerie':                         'Gallery Case',
    'gallery':                                'Gallery Case',

    'caisse chroma':                          'Chroma Case',
    'caisse chroma 2':                        'Chroma 2 Case',
    'caisse chroma 3':                        'Chroma 3 Case',

    'caisse gamma':                           'Gamma Case',
    'caisse gamma 2':                         'Gamma 2 Case',

    "caisse de l'opération breakout":         'Operation Breakout Weapon Case',
    'caisse opération breakout':              'Operation Breakout Weapon Case',
    "caisse de l'opération bravo":            'Operation Bravo Case',
    'caisse opération bravo':                 'Operation Bravo Case',
    "caisse de l'opération phoenix":          'Operation Phoenix Weapon Case',
    'caisse opération phoenix':               'Operation Phoenix Weapon Case',
    "caisse de l'opération vanguard":         'Operation Vanguard Weapon Case',
    'caisse opération vanguard':              'Operation Vanguard Weapon Case',
    "caisse de l'opération wildfire":         'Operation Wildfire Case',
    'caisse opération wildfire':              'Operation Wildfire Case',
    "caisse de l'opération hydra":            'Operation Hydra Case',
    'caisse opération hydra':                 'Operation Hydra Case',
    'caisse toile brisée':                    'Shattered Web Case',
    "caisse de l'opération broken fang":      'Operation Broken Fang Case',
    'caisse opération broken fang':           'Operation Broken Fang Case',
    'broken fang':                            'Operation Broken Fang Case',

    'caisse clutch':                          'Clutch Case',
    'clutch':                                 'Clutch Case',
    'caisse zone de danger':                  'Danger Zone Case',
    'zone de danger':                         'Danger Zone Case',
    'caisse horizon':                         'Horizon Case',
    'caisse ombre':                           'Shadow Case',
    'caisse gant':                            'Glove Case',
    'caisse revolver':                        'Revolver Case',
    'caisse falchion':                        'Falchion Case',
    'caisse huntsman':                        'Huntsman Weapon Case',
    'caisse cs20':                            'CS20 Case',
    'cs20':                                   'CS20 Case',

    "caisse d'armes cs:go":                   'CS:GO Weapon Case',
    "caisse d'armes cs:go 2":                 'CS:GO Weapon Case 2',
    "caisse d'armes cs:go 3":                 'CS:GO Weapon Case 3',

    'caisse esports 2013':                    'eSports 2013 Case',
    'caisse esports 2013 hiver':              'eSports 2013 Winter Case',
    'caisse esports 2014 été':                'eSports 2014 Summer Case',

    // Saturation = Chroma (FR)
    'saturation n°3':                         'Chroma 3 Case',
    'saturation':                             'Chroma Case',

    // === CAPSULES STICKERS ===
    'capsule stickers de la communauté de 2025':  '2025 Community Sticker Capsule',
    'capsule stickers communauté 2025':            '2025 Community Sticker Capsule',
    'collection capsule à stickers cs20':          'CS20 Sticker Capsule',
    'capsule à stickers cs20':                     'CS20 Sticker Capsule',
    'sugarface 2':                                 'Sugarface 2 Capsule',

    // === TERMINAL ===
    'collection genèse':                      'Sealed Genesis Terminal',
    'collection genese':                      'Sealed Genesis Terminal',
    'terminal genèse scellé':                 'Sealed Genesis Terminal',
    'sealed genesis terminal':                'Sealed Genesis Terminal',
  }

  // 1. Exact match
  if (l in map) return map[l]

  // 2. Nettoyage parenthèses FR : "Recoil Case (Recul du Serpent)" → "Recoil Case"
  const withoutParens = sheetName.replace(/\s*\([^)]*\)\s*/g, '').trim()
  const lClean = withoutParens.toLowerCase().trim()
  if (lClean in map) return map[lClean]

  // 3. Souvenir packages → déjà en anglais
  if (l.includes('souvenir package')) return sheetName.trim()

  // 4. Si contient déjà "Case" en anglais
  if (/\bcase\b/i.test(withoutParens)) return withoutParens

  // 5. Transformation auto "Caisse X" → "X Case"
  const caisseMatch = withoutParens.match(/^caisse\s+(.+)$/i)
  if (caisseMatch) return caisseMatch[1].trim() + ' Case'

  // 6. Fallback
  return sheetName.trim()
}

/** Collections/items gratuits (drops, armurerie) — pas de prix sur le Steam Market */
function isFreeCollection(sheetName) {
  const l = sheetName.toLowerCase()

  // Collections armurerie (drops en jeu gratuits)
  if (l.startsWith('collection ') && !l.includes('capsule') && !l.includes('genèse') && !l.includes('genese')) {
    return true
  }

  // Stickers Armory (Création de Personnages, Maîtrise des Elements) → gratuits via pass
  if (l.startsWith('stickers ')) return true

  // Porte-bonheurs (charms) → drops gratuits
  if (l.startsWith('porte-bonheurs') || l.startsWith('porte-bonheur')) return true

  return false
}

/** Est-ce que cette collection nécessite une clé ? */
function collectionNeedsKey(name) {
  const type = categorizeSheet(name)
  // Caisses classiques = clé requise. Souvenirs, stickers, collections, terminal = non.
  return type === 'case'
}

function toggleType(type) {
  if (type === 'all') {
    selectedTypes.value = ['all']
    return
  }
  const idx = selectedTypes.value.indexOf('all')
  if (idx !== -1) selectedTypes.value.splice(idx, 1)

  const i = selectedTypes.value.indexOf(type)
  if (i !== -1) {
    selectedTypes.value.splice(i, 1)
    if (selectedTypes.value.length === 0) selectedTypes.value = ['all']
  } else {
    selectedTypes.value.push(type)
  }
}

function isTypeSelected(type) {
  return selectedTypes.value.includes(type)
}

const availableTypes = computed(() => {
  const types = new Set()
  sheets.value.forEach(s => types.add(categorizeSheet(s.name)))
  return ['all', ...Array.from(types)]
})

const rarityColorsMap = {
  'gris':      '#b0c3d9',
  'bleu-ciel': '#5e98d9',
  'bleu':      '#4b69ff',
  'violet':    '#8847ff',
  'rose':      '#d32ce6',
  'rouge':     '#eb4b4b',
  'jaune':     '#ffd700',
}

function getRarityColor(rarity) {
  if (rarity.color) return rarity.color
  const key = rarity.name.toLowerCase().replace(/[\s-]+/g, '-')
  return rarityColorsMap[key] || '#888'
}

const officialCaseRates = {
  'bleu':   79.92,
  'violet': 15.98,
  'rose':   3.20,
  'rouge':  0.64,
  'jaune':  0.26,
}

function isCaseType(name) {
  const l = name.toLowerCase()
  if (specialCases.some(s => l.includes(s))) return true
  return l.includes('case') || l.includes('caisse')
}

function getOfficialRate(rarityName, sheetName) {
  if (!isCaseType(sheetName)) return null
  const key = rarityName.toLowerCase().replace(/[\s-]+/g, '-')
  return officialCaseRates[key] ?? null
}

// --- Prix par collection ---
function getCollectionPrice(sheetName) {
  if (isFreeCollection(sheetName)) return { price: 0, currency: '€', free: true }
  const mhn = toMarketHashName(sheetName)
  if (!mhn) return null // pas de market hash name connu → prix indisponible
  return prices.value[mhn] ?? null
}

function getCollectionUnitCost(sheetName) {
  const p = getCollectionPrice(sheetName)
  if (!p) return null
  if (p.free) return { itemPrice: 0, keyPrice: 0, total: 0, free: true }
  if (p.price == null) return null
  const itemPrice = p.price
  const keyPrice = collectionNeedsKey(sheetName) ? KEY_PRICE : 0
  return { itemPrice, keyPrice, total: Math.round((itemPrice + keyPrice) * 100) / 100, free: false }
}

function getCollectionTotalCost(sheetName, sheetId) {
  const unit = getCollectionUnitCost(sheetName)
  if (!unit) return null
  if (unit.free) return 0
  const data = sheetsData.value[sheetId]
  const opens = data?.total || 0
  return Math.round(unit.total * opens * 100) / 100
}

// --- Stats globales avec prix ---
const selectedData = computed(() => {
  if (!selectedId.value) return null
  return sheetsData.value[selectedId.value] || null
})

const selectedName = computed(() => {
  return sheets.value.find(s => s.id === selectedId.value)?.name || ''
})

const allLoaded = computed(() => {
  return progress.value.loaded >= progress.value.total && progress.value.total > 0
})

const filteredSheets = computed(() => {
  if (selectedTypes.value.includes('all')) return sheets.value
  return sheets.value.filter(s => selectedTypes.value.includes(categorizeSheet(s.name)))
})

const globalStats = computed(() => {
  if (!allLoaded.value) return null

  const filtered = filteredSheets.value
    .map(s => ({ sheet: s, data: sheetsData.value[s.id] }))
    .filter(({ data }) => data && data.total > 0)

  if (!filtered.length) return null

  const totalOpened = filtered.reduce((s, { data }) => s + data.total, 0)
  const caseCount = filtered.length

  // Prix global — exclure les gratuits et ceux sans prix
  let totalCost = 0
  let pricedOpens = 0  // nb d'ouvertures qui ont un coût réel
  let pricedCount = 0  // nb de collections avec prix

  filtered.forEach(({ sheet, data }) => {
    if (isFreeCollection(sheet.name)) return // skip gratuits
    const unit = getCollectionUnitCost(sheet.name)
    if (!unit || unit.free) return           // skip sans prix ou gratuit
    if (unit.total <= 0) return              // skip coût 0
    const cost = unit.total * data.total
    totalCost += cost
    pricedOpens += data.total
    pricedCount++
  })

  const avgCostPerOpen = pricedOpens > 0
    ? Math.round(totalCost / pricedOpens * 100) / 100
    : null

  // Weighted average rarities
  const rarityTotals = {}
  filtered.forEach(({ data }) => {
    data.rarities.forEach(r => {
      const key = r.name.toLowerCase()
      if (!rarityTotals[key]) {
        rarityTotals[key] = { name: r.name, virtualDrops: 0, color: r.color }
      }
      rarityTotals[key].virtualDrops += (r.percent / 100) * data.total
    })
  })

  const rarities = Object.values(rarityTotals).map(r => ({
    name: r.name,
    percent: Math.round((r.virtualDrops / totalOpened) * 10000) / 100,
    color: r.color,
  }))
  rarities.sort((a, b) => b.percent - a.percent)

  return { totalOpened, caseCount, rarities, totalCost: Math.round(totalCost * 100) / 100, avgCostPerOpen, pricedCount }
})

function fmt(val) {
  if (val == null) return '—'
  return val.toFixed(2) + '€'
}

async function fetchSheetsList() {
  try {
    const r = await fetch('/api/sheets.php')
    const data = await r.json()
    if (Array.isArray(data)) sheets.value = data
  } catch (e) {
    console.warn('fetchSheetsList failed:', e)
  }
}

async function fetchSheet(id) {
  if (sheetsData.value[id]) return true
  try {
    const r = await fetch(`/api/sheets.php?id=${id}`)
    if (r.status === 429) return false
    const data = await r.json()
    if (data && !data.error) {
      sheetsData.value[id] = data
      return true
    }
  } catch (e) {
    console.warn('fetchSheet failed:', id, e)
  }
  return false
}

async function fetchPrices() {
  if (sheets.value.length === 0) return
  loadingPrices.value = true
  priceError.value = null
  priceDebug.value = { resolved: 0, fetched: 0, succeeded: 0, failed: 0, errors: [], progress: '' }

  try {
    // Résoudre les noms (exclure gratuits et null)
    const namesSet = new Set()
    sheets.value.forEach(s => {
      if (isFreeCollection(s.name)) return
      const mhn = toMarketHashName(s.name)
      if (mhn) namesSet.add(mhn)
    })
    const allNames = Array.from(namesSet)
    priceDebug.value.resolved = allNames.length

    if (!allNames.length) { loadingPrices.value = false; return }

    // Petits batchs de 5 — chaque requête PHP prend ~15s max
    // Les prix apparaissent progressivement au fur et à mesure
    const BATCH = 5
    const total = Math.ceil(allNames.length / BATCH)

    for (let i = 0; i < allNames.length; i += BATCH) {
      const batch = allNames.slice(i, i + BATCH)
      const num = Math.floor(i / BATCH) + 1
      priceDebug.value.progress = `${num}/${total}`

      try {
        const r = await fetch(`/api/get_prices.php?names=${encodeURIComponent(batch.join(','))}`)
        if (r.ok) {
          const data = await r.json()
          if (data && typeof data === 'object' && !data.error) {
            prices.value = { ...prices.value, ...data }
            priceDebug.value.succeeded++
          }
        } else {
          priceDebug.value.failed++
        }
      } catch (e) {
        priceDebug.value.failed++
        priceDebug.value.errors.push(e.message)
      }

      // Petit délai entre batchs (les requêtes prennent déjà ~15s chacune)
      if (i + BATCH < allNames.length) {
        await new Promise(r => setTimeout(r, 500))
      }
    }
  } catch (e) {
    priceError.value = e.message
  } finally {
    loadingPrices.value = false
  }
}

async function retryPrices() {
  prices.value = {}
  try { await fetch('/api/get_prices.php?clear_cache=1') } catch(e) {}
  await fetchPrices()
}

const sleep = ms => new Promise(r => setTimeout(r, ms))

async function fetchAllSheets() {
  loading.value = true
  await fetchSheetsList()
  const ids = sheets.value.map(s => s.id)
  progress.value.total = ids.length
  progress.value.loaded = 0
  progress.value.failed = 0

  // Fetch prices en parallèle
  fetchPrices()

  const failed = []

  for (let i = 0; i < ids.length; i += 2) {
    const batch = ids.slice(i, i + 2)
    const results = await Promise.all(batch.map(id => fetchSheet(id)))
    results.forEach((ok, j) => {
      if (!ok) failed.push(batch[j])
    })
    progress.value.loaded = Math.min(i + 2, ids.length)
    await sleep(200)
  }

  // Retry with backoff
  for (let attempt = 0; attempt < 3 && failed.length > 0; attempt++) {
    const retryList = [...failed]
    failed.length = 0
    await sleep(1500 * (attempt + 1))
    for (const id of retryList) {
      const ok = await fetchSheet(id)
      if (!ok) failed.push(id)
      await sleep(300)
    }
  }

  progress.value.failed = failed.length
  loading.value = false
}

function selectSheet(id) {
  selectedId.value = id
  activeTab.value = 'case'
  fetchSheet(id)
}

function diffClass(actual, official) {
  if (official === null) return ''
  const diff = actual - official
  if (Math.abs(diff) < 1) return 'diff-neutral'
  return diff > 0 ? 'diff-up' : 'diff-down'
}

function diffText(actual, official) {
  if (official === null) return ''
  const diff = actual - official
  return `${diff >= 0 ? '+' : ''}${diff.toFixed(2)}%`
}

onMounted(fetchAllSheets)
</script>

<template>
  <div class="stats-page">
    <!-- Tabs -->
    <div class="stats-tabs">
      <button :class="{ active: activeTab === 'case' }" @click="activeTab = 'case'">
        <i class="fas fa-box-open"></i> Par collection
      </button>
      <button :class="{ active: activeTab === 'global' }" @click="activeTab = 'global'">
        <i class="fas fa-chart-pie"></i> Global
      </button>
    </div>

    <!-- Loading progress -->
    <div v-if="loading" class="stats-loading-bar">
      <div class="loading-text">
        <i class="fas fa-spinner fa-spin"></i>
        Chargement… {{ progress.loaded }} / {{ progress.total }}
      </div>
      <div class="loading-track">
        <div class="loading-fill" :style="{ width: (progress.total ? (progress.loaded / progress.total * 100) : 0) + '%' }"></div>
      </div>
    </div>

    <!-- Failed warning -->
    <div v-if="!loading && progress.failed > 0" class="stats-warning">
      <i class="fas fa-exclamation-triangle"></i>
      {{ progress.failed }} collection(s) n'ont pas pu être chargée(s).
      <button @click="fetchAllSheets()">Réessayer</button>
    </div>

    <!-- TAB: Par collection -->
    <template v-if="!loading && activeTab === 'case'">
      <div class="stats-layout">
        <div class="stats-sidebar">
          <h3>Collections ({{ sheets.length }})</h3>
          <div
            v-for="s in sheets" :key="s.id"
            class="case-item"
            :class="{ active: selectedId === s.id }"
            @click="selectSheet(s.id)"
          >
            <span class="case-name">{{ s.name }}</span>
            <span class="case-total" v-if="sheetsData[s.id]">{{ sheetsData[s.id].total }}</span>
          </div>
        </div>

        <div class="stats-detail">
          <div v-if="loadingSheet" class="stats-loading"><i class="fas fa-spinner fa-spin"></i></div>

          <div v-else-if="!selectedData" class="stats-placeholder">
            <i class="fas fa-arrow-left"></i> Sélectionne une collection
          </div>

          <template v-else>
            <div class="detail-header">
              <h2>{{ selectedName }}</h2>
              <span class="badge">{{ selectedData.total }} ouvertures</span>
            </div>

            <!-- Carte de coût par collection -->
            <div class="box detail-section cost-section">
              <h3>Coût estimé</h3>
              <div v-if="isFreeCollection(selectedName)" class="cost-free">
                <span class="cost-free__icon">🎁</span>
                <span class="cost-free__text">Collection gratuite (drops en jeu)</span>
              </div>
              <div v-else-if="getCollectionUnitCost(selectedName)" class="cost-grid">
                <div class="cost-item">
                  <span class="cost-item__label">
                    {{ categorizeSheet(selectedName) === 'case' ? '📦 Caisse' : categorizeSheet(selectedName) === 'souvenir' ? '🎁 Package' : '🏷️ Capsule' }}
                  </span>
                  <span class="cost-item__value">{{ fmt(getCollectionUnitCost(selectedName).itemPrice) }}</span>
                </div>
                <div v-if="collectionNeedsKey(selectedName)" class="cost-item cost-item--key">
                  <span class="cost-item__label">🔑 Clé</span>
                  <span class="cost-item__value">{{ fmt(KEY_PRICE) }}</span>
                </div>
                <div class="cost-item cost-item--unit">
                  <span class="cost-item__label">Coût / ouverture</span>
                  <span class="cost-item__value cost-item__value--accent">{{ fmt(getCollectionUnitCost(selectedName).total) }}</span>
                </div>
                <div class="cost-item cost-item--total">
                  <span class="cost-item__label">Total (× {{ selectedData.total }})</span>
                  <span class="cost-item__value cost-item__value--big">{{ fmt(getCollectionTotalCost(selectedName, selectedId)) }}</span>
                </div>
              </div>
              <div v-else-if="loadingPrices" class="cost-placeholder">
                <i class="fas fa-spinner fa-spin"></i> Chargement des prix…
              </div>
              <div v-else class="cost-placeholder">
                Prix indisponible{{ toMarketHashName(selectedName) ? ` pour « ${toMarketHashName(selectedName)} »` : ' (pas sur le Steam Market)' }}
              </div>
            </div>

            <!-- Raretés -->
            <div class="box detail-section">
              <h3>Répartition par rareté</h3>
              <div class="rarity-list">
                <div v-for="r in selectedData.rarities" :key="r.name" class="rarity-row">
                  <span class="rarity-name" :style="{ color: getRarityColor(r) }">{{ r.name }}</span>
                  <div class="bar-track">
                    <div class="bar-fill" :style="{ width: Math.min(r.percent, 100) + '%', background: getRarityColor(r) }"></div>
                    <div
                      v-if="getOfficialRate(r.name, selectedName) !== null"
                      class="bar-marker"
                      :style="{ left: Math.min(getOfficialRate(r.name, selectedName), 100) + '%' }"
                      :title="'Officiel: ' + getOfficialRate(r.name, selectedName) + '%'"
                    ></div>
                  </div>
                  <span class="rarity-pct">{{ r.percent }}%</span>
                  <span
                    v-if="getOfficialRate(r.name, selectedName) !== null"
                    class="rarity-diff"
                    :class="diffClass(r.percent, getOfficialRate(r.name, selectedName))"
                  >
                    {{ diffText(r.percent, getOfficialRate(r.name, selectedName)) }}
                  </span>
                </div>
              </div>
              <div v-if="isCaseType(selectedName)" class="legend">
                <span><span class="legend-bar-icon"></span> Tes résultats</span>
                <span><span class="legend-marker-icon"></span> Taux officiel CS2</span>
              </div>
            </div>

            <!-- Items -->
            <div class="box detail-section">
              <h3>Détail des drops</h3>
              <table class="drops-table">
                <thead>
                  <tr>
                    <th class="col-name">Arme</th>
                    <th class="col-num">Drops</th>
                    <th class="col-num">%</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="item in selectedData.items.filter(i => i.drops > 0)" :key="item.name">
                    <td class="col-name">
                      <span v-if="item.color" class="rarity-dot" :style="{ background: item.color }"></span>
                      {{ item.name }}
                    </td>
                    <td class="col-num">{{ item.drops }}</td>
                    <td class="col-num">{{ item.pct }}%</td>
                  </tr>
                </tbody>
              </table>
              <div v-if="!selectedData.items.some(i => i.drops > 0)" class="stats-placeholder">
                Aucun drop enregistré.
              </div>
            </div>
          </template>
        </div>
      </div>
    </template>

    <!-- TAB: Global -->
    <template v-if="!loading && activeTab === 'global'">
      <!-- Type filter -->
      <div class="filter-bar">
        <span class="filter-label">Filtrer :</span>
        <div class="filter-chips">
          <button
            v-for="type in availableTypes" :key="type"
            class="filter-chip"
            :class="{ active: isTypeSelected(type) }"
            @click="toggleType(type)"
          >
            {{ typeLabels[type] || type }}
          </button>
        </div>
      </div>

      <div v-if="!allLoaded" class="stats-placeholder">
        <i class="fas fa-spinner fa-spin"></i> En attente du chargement complet…
      </div>
      <div v-else-if="!globalStats" class="stats-placeholder">Aucune donnée pour cette sélection.</div>
      <template v-else>
        <div class="global-cards">
          <div class="box stat-card">
            <div class="stat-value">{{ globalStats.totalOpened }}</div>
            <div class="stat-label">Ouvertures totales</div>
          </div>
          <div class="box stat-card">
            <div class="stat-value">{{ globalStats.caseCount }}</div>
            <div class="stat-label">Collections avec drops</div>
          </div>
          <div class="box stat-card" v-if="globalStats.totalCost > 0">
            <div class="stat-value stat-value--money">{{ fmt(globalStats.totalCost) }}</div>
            <div class="stat-label">Total dépensé</div>
          </div>
          <div class="box stat-card" v-if="globalStats.avgCostPerOpen">
            <div class="stat-value stat-value--money">{{ fmt(globalStats.avgCostPerOpen) }}</div>
            <div class="stat-label">Coût moyen / ouverture</div>
          </div>
        </div>

        <!-- Chargement prix -->
        <div v-if="loadingPrices" class="price-loading-bar">
          <i class="fas fa-coins fa-spin"></i>
          Chargement des prix Steam… batch {{ priceDebug.progress || '...' }}
          <span class="price-loaded-count" v-if="Object.keys(prices).length > 0">
            — {{ Object.values(prices).filter(v => v?.price != null).length }} prix chargés
          </span>
          <div class="price-note">Premier chargement ~2-3 min, ensuite caché 1h</div>
        </div>

        <!-- Erreur prix -->
        <div v-if="!loadingPrices && priceDebug.failed > 0" class="price-error-bar">
          <div class="price-error-bar__text">
            <i class="fas fa-exclamation-triangle"></i>
            {{ priceDebug.failed }} batch(s) échoué(s) — certains prix manquent
          </div>
          <button class="retry-btn" @click="retryPrices">
            <i class="fas fa-redo"></i> Recharger
          </button>
        </div>

        <div class="box detail-section">
          <h3>Moyenne pondérée des raretés</h3>
          <div class="rarity-list">
            <div v-for="r in globalStats.rarities" :key="r.name" class="rarity-row">
              <span class="rarity-name" :style="{ color: getRarityColor(r) }">{{ r.name }}</span>
              <div class="bar-track">
                <div class="bar-fill" :style="{ width: Math.min(r.percent, 100) + '%', background: getRarityColor(r) }"></div>
              </div>
              <span class="rarity-pct">{{ r.percent }}%</span>
            </div>
          </div>
        </div>

        <div class="box detail-section">
          <h3>Récapitulatif par collection</h3>
          <table class="drops-table">
            <thead>
              <tr>
                <th class="col-name">Collection</th>
                <th class="col-num">Ouvertures</th>
                <th class="col-num">Coût unit.</th>
                <th class="col-num">Total</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="s in filteredSheets" :key="s.id"
                class="clickable"
                @click="selectSheet(s.id)"
              >
                <td class="col-name">
                  {{ s.name }}
                  <span v-if="collectionNeedsKey(s.name)" class="key-icon-mini" title="Clé requise">🔑</span>
                  <span v-if="isFreeCollection(s.name)" class="free-badge-mini">Gratuit</span>
                </td>
                <td class="col-num">{{ sheetsData[s.id]?.total || 0 }}</td>
                <td class="col-num">
                  <template v-if="isFreeCollection(s.name)">Gratuit</template>
                  <template v-else>{{ fmt(getCollectionUnitCost(s.name)?.total) }}</template>
                </td>
                <td class="col-num">
                  <template v-if="isFreeCollection(s.name)">Gratuit</template>
                  <template v-else>{{ fmt(getCollectionTotalCost(s.name, s.id)) }}</template>
                </td>
              </tr>
            </tbody>
            <tfoot v-if="globalStats.totalCost > 0">
              <tr class="total-row">
                <td class="col-name"><strong>Total</strong></td>
                <td class="col-num"><strong>{{ globalStats.totalOpened }}</strong></td>
                <td class="col-num"></td>
                <td class="col-num"><strong class="total-price">{{ fmt(globalStats.totalCost) }}</strong></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </template>
    </template>
  </div>
</template>
