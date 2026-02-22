import { reactive } from 'vue'

/**
 * Store partagé entre Tool.vue et Settings.vue.
 * On utilise un simple reactive() – pas besoin de Pinia pour l'instant.
 */
export const wheelStore = reactive({
  inventories: [
    { steamId: '76561199055485964', label: 'Dublatic' },
    { steamId: '76561199652580615', label: 'Livies' },
    { steamId: '76561199065020540', label: 'Henebus' },
  ],
  apiUrl: '/api/get_inventory.php'
})
