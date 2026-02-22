import { reactive } from 'vue'

const DEFAULT_ACCOUNTS = [
  { steamId: '76561199055485964', label: 'Dublatic' },
  { steamId: '76561199652580615', label: 'Livies' },
  { steamId: '76561199065020540', label: 'Henebus' },
]

export const wheelStore = reactive({
  inventories: [...DEFAULT_ACCOUNTS],
  apiUrl: '/api/get_inventory.php',
  loaded: false,
})

// Charger les comptes depuis le PHP
export async function loadAccounts() {
  try {
    const r = await fetch('/api/accounts.php')
    const data = await r.json()
    if (Array.isArray(data) && data.length > 0) {
      wheelStore.inventories = data
    }
  } catch (e) {
    console.warn('loadAccounts failed, using defaults:', e)
  }
  wheelStore.loaded = true
}

// Sauvegarder les comptes côté serveur
async function saveAccounts() {
  try {
    await fetch('/api/accounts.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(wheelStore.inventories),
    })
  } catch (e) {
    console.warn('saveAccounts failed:', e)
  }
}

// Ajouter un compte
export async function addAccount(steamId, label) {
  if (wheelStore.inventories.some(inv => inv.steamId === steamId)) return false
  wheelStore.inventories.push({ steamId, label })
  await saveAccounts()
  return true
}

// Supprimer un compte
export async function removeAccount(index) {
  wheelStore.inventories.splice(index, 1)
  await saveAccounts()
}
