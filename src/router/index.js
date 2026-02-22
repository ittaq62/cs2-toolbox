import { createRouter, createWebHistory } from 'vue-router'

const routes = [
  { path: '/',         name: 'home',     component: () => import('@/pages/Home.vue'),     meta: { title: "Accueil" } },
  { path: '/outil',    name: 'tool',     component: () => import('@/pages/Tool.vue'),     meta: { title: "Outil" } },
  { path: '/stats',    name: 'stats',    component: () => import('@/pages/Stats.vue'),    meta: { title: "Stats" } },
  { path: '/settings', name: 'settings', component: () => import('@/pages/Settings.vue'), meta: { title: "Paramètres" } },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior() { return { top: 0 } }
})

export default router
