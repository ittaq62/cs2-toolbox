<script setup>
import { ref, computed, onMounted } from 'vue'

const sheets = ref([])
const sheetsData = ref({})
const selectedId = ref(null)
const loading = ref(true)
const loadingSheet = ref(false)
const activeTab = ref('case')

const progress = ref({ loaded: 0, total: 0, failed: 0 })

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

  // Check special cases first
  if (specialCases.some(s => l.includes(s))) return 'case'
  if (terminalCollections.some(s => l.includes(s))) return 'terminal'

  if (l.includes('souvenir')) return 'souvenir'
  if (l.includes('case') || l.includes('caisse')) return 'case'
  if (l.includes('sticker') || l.includes('capsule') || l.includes('sugarface')) return 'sticker'
  if (l.includes('porte-bonheur')) return 'charm'
  return 'collection'
}

function toggleType(type) {
  if (type === 'all') {
    selectedTypes.value = ['all']
    return
  }
  // Remove 'all' if selecting specific type
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

// Available types based on actual sheets
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

// Filtered sheets for global tab
const filteredSheets = computed(() => {
  if (selectedTypes.value.includes('all')) return sheets.value
  return sheets.value.filter(s => selectedTypes.value.includes(categorizeSheet(s.name)))
})

const globalStats = computed(() => {
  if (!allLoaded.value) return null

  const filtered = filteredSheets.value
    .map(s => sheetsData.value[s.id])
    .filter(d => d && d.total > 0)

  if (!filtered.length) return null

  const totalOpened = filtered.reduce((s, d) => s + d.total, 0)
  const caseCount = filtered.length

  // Weighted average by number of openings
  const rarityTotals = {}
  filtered.forEach(d => {
    const weight = d.total
    d.rarities.forEach(r => {
      const key = r.name.toLowerCase()
      if (!rarityTotals[key]) {
        rarityTotals[key] = { name: r.name, weightedPct: 0, totalWeight: 0, color: r.color }
      }
      rarityTotals[key].weightedPct += r.percent * weight
      rarityTotals[key].totalWeight += weight
    })
  })

  const rarities = Object.values(rarityTotals).map(r => ({
    name: r.name,
    percent: Math.round((r.weightedPct / r.totalWeight) * 100) / 100,
    color: r.color,
  }))

  // Sort by percent desc
  rarities.sort((a, b) => b.percent - a.percent)

  return { totalOpened, caseCount, rarities }
})

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

const sleep = ms => new Promise(r => setTimeout(r, ms))

async function fetchAllSheets() {
  loading.value = true
  await fetchSheetsList()
  const ids = sheets.value.map(s => s.id)
  progress.value.total = ids.length
  progress.value.loaded = 0
  progress.value.failed = 0

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
          <h3>Récapitulatif</h3>
          <table class="drops-table">
            <thead>
              <tr>
                <th class="col-name">Collection</th>
                <th class="col-num">Ouvertures</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="s in filteredSheets" :key="s.id" class="clickable" @click="selectSheet(s.id)">
                <td class="col-name">{{ s.name }}</td>
                <td class="col-num">{{ sheetsData[s.id]?.total || 0 }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </template>
  </div>
</template>
