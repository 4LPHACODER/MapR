<script setup>
import { onMounted, onUnmounted, provide, ref } from 'vue';
import AdminHeader from './components/AdminHeader.vue';
import AdminSidebar from './components/AdminSidebar.vue';
import { useMapReportContext } from './composables/mapReportContext.js';

defineProps({
    userName: { type: String, default: '' },
    userEmail: { type: String, default: '' },
    logoutUrl: { type: String, default: '/logout' },
    homeUrl: { type: String, default: '/' },
    csrfToken: { type: String, default: '' },
});

const drawerOpen = ref(false);
const sidebarCollapsed = ref(false);
const reportContext = useMapReportContext();
provide('reportContext', reportContext);

function onKeydown(event) {
    if (event.key === 'Escape') {
        drawerOpen.value = false;
    }
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onUnmounted(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <div class="cdrrmo-app" :class="{ 'sidebar-collapsed': sidebarCollapsed }">
        <button v-if="drawerOpen" type="button" class="cdrrmo-sidebar-backdrop" aria-label="Close navigation menu" @click="drawerOpen = false" />
        <div class="cdrrmo-layout">
            <AdminSidebar
                :home-url="homeUrl"
                :user-name="userName"
                :user-email="userEmail"
                :logout-url="logoutUrl"
                :csrf-token="csrfToken"
                :open="drawerOpen"
                :collapsed="sidebarCollapsed"
                @close="drawerOpen = false"
                @toggle-collapse="sidebarCollapsed = !sidebarCollapsed"
            />
            <div class="cdrrmo-main">
                <AdminHeader
                    :user-name="userName"
                    :user-email="userEmail"
                    :logout-url="logoutUrl"
                    :csrf-token="csrfToken"
                    @open-menu="drawerOpen = true"
                />
                <div class="cdrrmo-content">
                    <router-view />
                </div>
            </div>
        </div>
    </div>
</template>
