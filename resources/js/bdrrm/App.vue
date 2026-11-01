<script setup>
import { computed, onMounted, onUnmounted, provide, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import FieldSidebar from './components/FieldSidebar.vue';
import FieldHeader from './components/FieldHeader.vue';

defineProps({
    userName: { type: String, default: '' },
    userEmail: { type: String, default: '' },
    jobTitle: { type: String, default: '' },
    logoutUrl: { type: String, default: '/logout' },
    homeUrl: { type: String, default: '/' },
    csrfToken: { type: String, default: '' },
});

const route = useRoute();
const drawerOpen = ref(false);
const sidebarCollapsed = ref(false);
const title = computed(() => route.meta.title || 'Workspace');
const areaState = reactive({
    hasPolygon: false,
    coordinates: null,
    spatialRule: 'includes_boundary',
    summary: null,
    summaryLoading: false,
});
provide('reportContext', {
    state: areaState,
    setPolygonContext(coordinates) {
        areaState.coordinates = coordinates;
        areaState.hasPolygon = Array.isArray(coordinates) && coordinates.length >= 4;
        areaState.summary = {
            can_snapshot: false,
            note: 'This working extent does not create an official impact estimate.',
        };
    },
    clearPolygonContext() {
        areaState.coordinates = null;
        areaState.hasPolygon = false;
        areaState.summary = null;
    },
    async storeReportContext() {
        return false;
    },
    async clearAreaContext() {
        areaState.coordinates = null;
        areaState.hasPolygon = false;
        areaState.summary = null;
    },
    openReportPreview() {},
    openResidentsWithArea(router) {
        router.push({ path: '/buildings' });
    },
});

function onKeydown(event) {
    if (event.key === 'Escape') drawerOpen.value = false;
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onUnmounted(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <div class="cdrrmo-app field-role-bdrrm" :class="{ 'sidebar-collapsed': sidebarCollapsed }">
        <button v-if="drawerOpen" type="button" class="cdrrmo-sidebar-backdrop" aria-label="Close navigation menu" @click="drawerOpen = false" />
        <div class="cdrrmo-layout">
            <FieldSidebar
                :home-url="homeUrl"
                :user-name="userName"
                :user-email="userEmail"
                :job-title="jobTitle"
                :logout-url="logoutUrl"
                :csrf-token="csrfToken"
                :open="drawerOpen"
                :collapsed="sidebarCollapsed"
                @close="drawerOpen = false"
                @toggle-collapse="sidebarCollapsed = !sidebarCollapsed"
            />
            <div class="cdrrmo-main">
                <FieldHeader
                    :title="title"
                    :user-name="userName"
                    :job-title="jobTitle"
                    @open-menu="drawerOpen = true"
                />
                <main class="cdrrmo-content">
                    <router-view />
                </main>
            </div>
        </div>
        <nav class="field-bottom-nav" aria-label="Barangay navigation">
            <router-link to="/">Home</router-link>
            <router-link to="/map">Map</router-link>
            <router-link to="/reports/new">Capture / Report</router-link>
            <router-link to="/reports/drafts">Drafts</router-link>
            <button type="button" @click="drawerOpen = true">Menu</button>
        </nav>
    </div>
</template>
