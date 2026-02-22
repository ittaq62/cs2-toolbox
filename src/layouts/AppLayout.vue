<script setup>
import { ref, computed } from 'vue'
import { RouterLink, RouterView, useRoute } from 'vue-router'

const isCollapsed = ref(false)
const toggleSidebar = () => { isCollapsed.value = !isCollapsed.value }

const route = useRoute()
const pageTitle = computed(() => route.meta?.title ?? 'Outil Roue')
</script>

<template>
  <div class="layout" :class="{ collapsed: isCollapsed }">
    <!-- Sidebar -->
    <aside class="sidebar">
      <div class="sidebar-top">
        <button class="toggle-btn" @click="toggleSidebar" aria-label="Basculer la sidebar">
          <i class="fas fa-bars"></i>
        </button>
      </div>

      <nav class="nav">
        <RouterLink class="nav-item" to="/">
          <i class="fas fa-home"></i><span class="text">Accueil</span>
        </RouterLink>
        <RouterLink class="nav-item" to="/outil">
          <i class="fas fa-circle-notch"></i><span class="text">Outil</span>
        </RouterLink>
        <RouterLink class="nav-item" to="/stats">
          <i class="fas fa-chart-bar"></i><span class="text">Stats</span>
        </RouterLink>
        <RouterLink class="nav-item" to="/settings">
          <i class="fas fa-cog"></i><span class="text">Paramètres</span>
        </RouterLink>
      </nav>
    </aside>

    <!-- Contenu -->
    <section class="main-content">
      <header class="topbar">
        <h1>{{ pageTitle }}</h1>
      </header>

      <div class="content">
        <RouterView />
      </div>
    </section>
  </div>
</template>
