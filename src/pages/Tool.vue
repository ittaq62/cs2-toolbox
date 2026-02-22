<script setup>
import { computed, onMounted } from 'vue'
import Wheel from '@/components/Wheel.vue'
import { wheelStore, loadAccounts } from '@/stores/wheel.js'

onMounted(() => {
  if (!wheelStore.loaded) loadAccounts()
})

const steamIds = computed(() => wheelStore.inventories.map(inv => inv.steamId))
const labels   = computed(() => wheelStore.inventories.map(inv => inv.label))
</script>

<template>
  <div class="box">
    <Wheel :steam-ids="steamIds" :api-url="wheelStore.apiUrl" :labels="labels" />
  </div>
</template>
