<script setup>
import { ref } from 'vue'
import { wheelStore } from '@/stores/wheel.js'

const newLabel   = ref('')
const newSteamId = ref('')

function addInventory() {
  const id    = newSteamId.value.trim()
  const label = newLabel.value.trim()
  if (!id || !label) return
  // Évite les doublons
  if (wheelStore.inventories.some(inv => inv.steamId === id)) return
  wheelStore.inventories.push({ steamId: id, label })
  newSteamId.value = ''
  newLabel.value   = ''
}

function removeInventory(index) {
  wheelStore.inventories.splice(index, 1)
}
</script>

<template>
  <div class="box settings-page">
    <h2>Paramètres</h2>
    <p style="margin-bottom: 1.5rem">Gère les inventaires Steam utilisés par la roue.</p>

    <!-- Liste existante -->
    <div class="settings-list">
      <div
        v-for="(inv, i) in wheelStore.inventories"
        :key="inv.steamId"
        class="settings-row"
      >
        <span class="settings-label">{{ inv.label }}</span>
        <span class="settings-id">{{ inv.steamId }}</span>
        <button class="settings-remove" @click="removeInventory(i)" title="Supprimer">
          <i class="fas fa-trash-alt"></i>
        </button>
      </div>

      <div v-if="!wheelStore.inventories.length" class="settings-empty">
        Aucun inventaire configuré.
      </div>
    </div>

    <!-- Ajout -->
    <div class="settings-add">
      <input
        v-model="newLabel"
        class="settings-input"
        placeholder="Label (ex: Dublatic)"
        @keyup.enter="addInventory"
      />
      <input
        v-model="newSteamId"
        class="settings-input"
        placeholder="Steam ID (ex: 76561199055485964)"
        @keyup.enter="addInventory"
      />
      <button class="settings-btn" @click="addInventory">
        <i class="fas fa-plus"></i> Ajouter
      </button>
    </div>
  </div>
</template>
