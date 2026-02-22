<script setup>
import { ref, onMounted } from 'vue'
import { wheelStore, loadAccounts, addAccount, removeAccount } from '@/stores/wheel.js'

const newLabel   = ref('')
const newSteamId = ref('')
const saving     = ref(false)

onMounted(() => {
  if (!wheelStore.loaded) loadAccounts()
})

async function handleAdd() {
  const id    = newSteamId.value.trim()
  const label = newLabel.value.trim()
  if (!id || !label) return
  saving.value = true
  const ok = await addAccount(id, label)
  saving.value = false
  if (ok) {
    newSteamId.value = ''
    newLabel.value   = ''
  }
}

async function handleRemove(index) {
  saving.value = true
  await removeAccount(index)
  saving.value = false
}
</script>

<template>
  <div class="box settings-page">
    <h2>Paramètres</h2>
    <p style="margin-bottom: 1.5rem">Gère les inventaires Steam utilisés par la roue.</p>

    <div class="settings-list">
      <div v-for="(inv, i) in wheelStore.inventories" :key="inv.steamId" class="settings-row">
        <span class="settings-label">{{ inv.label }}</span>
        <span class="settings-id">{{ inv.steamId }}</span>
        <button class="settings-remove" @click="handleRemove(i)" :disabled="saving" title="Supprimer">
          <i class="fas fa-trash-alt"></i>
        </button>
      </div>
      <div v-if="!wheelStore.inventories.length" class="settings-empty">
        Aucun inventaire configuré.
      </div>
    </div>

    <div class="settings-add">
      <input v-model="newLabel" class="settings-input" placeholder="Label (ex: Dublatic)" @keyup.enter="handleAdd" />
      <input v-model="newSteamId" class="settings-input" placeholder="Steam ID (ex: 76561199055485964)" @keyup.enter="handleAdd" />
      <button class="settings-btn" @click="handleAdd" :disabled="saving">
        <i class="fas fa-plus"></i> Ajouter
      </button>
    </div>
  </div>
</template>
