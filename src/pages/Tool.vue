<script setup>
import { computed } from 'vue'
import Wheel from '@/components/Wheel.vue'
import { wheelStore } from '@/stores/wheel.js'

// Dérivés réactifs du store → si Settings modifie le store, Tool se met à jour
const steamIds = computed(() => wheelStore.inventories.map(inv => inv.steamId))
const labels   = computed(() => wheelStore.inventories.map(inv => inv.label))
</script>

<template>
  <div class="box">
    <Wheel :steam-ids="steamIds" :api-url="wheelStore.apiUrl" :labels="labels" />
  </div>
</template>
