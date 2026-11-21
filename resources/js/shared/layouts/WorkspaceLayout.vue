<script setup>
import { computed, inject, onMounted, onUnmounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import Button from 'primevue/button';
import Drawer from 'primevue/drawer';
import WorkspaceSidebar from '../components/WorkspaceSidebar.vue';
import WorkspaceHeader from '../components/WorkspaceHeader.vue';
import MapIcon from '../components/MapIcon.vue';
import MaprFeedbackHost from '../ui/MaprFeedbackHost.vue';
import { flattenNavigation, isNavigationActive, workspaceNavigation } from '../navigation';
import '../../../css/shared/layout.css';

const props = defineProps({
    workspace: { type: String, required: true },
    userName: { type: String, default: '' }, userEmail: { type: String, default: '' },
    jobTitle: { type: String, default: '' }, logoutUrl: { type: String, default: '/logout' },
    homeUrl: { type: String, default: '/' }, csrfToken: { type: String, default: '' },
    photoUrl: { type: String, default: '' }, attention: { type: Array, default: null },
});
const roleCode = inject('roleCode', '');
const route = useRoute();
const collapsed = ref(false);
const drawerOpen = ref(false);
const keyboardOpen = ref(false);
const badges = ref({});
const config = computed(() => workspaceNavigation(props.workspace, roleCode));
const navigation = computed(() => config.value.items.map((item) => {
    if (item.children?.length) {
        const children = item.children.map((child) => ({ ...child, active: isNavigationActive(child, route) }));
        return { ...item, children, active: children.some((child) => child.active) };
    }
    return { ...item, active: isNavigationActive(item, route) };
}));
const flatLinks = computed(() => flattenNavigation(navigation.value));
const mobile = computed(() => config.value.mobile.map((path) => flatLinks.value.find((item) => item.to === path)));
const title = computed(() => route.meta.title || `${config.value.label} workspace`);
const isWizard = computed(() => route.meta.wizard === true);
const sidebarProps = computed(() => ({
    label: config.value.label, navigation: navigation.value, badges: badges.value,
    userName: props.userName, jobTitle: props.jobTitle || config.value.label,
    homeUrl: props.homeUrl, logoutUrl: props.logoutUrl, csrfToken: props.csrfToken, photoUrl: props.photoUrl,
}));
let badgeRequest = 0;
let focusTimer;
function onFocusIn(event) {
    if (!isWizard.value) return;
    if (['INPUT', 'TEXTAREA', 'SELECT'].includes(event.target?.tagName)) keyboardOpen.value = window.innerWidth < 768;
}
function onFocusOut() {
    window.clearTimeout(focusTimer);
    focusTimer = window.setTimeout(() => {
        if (!['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName)) keyboardOpen.value = false;
    }, 120);
}
async function loadBadges() {
    if (!config.value.badgeUrl) return;
    const current = ++badgeRequest;
    try {
        const { data } = await window.axios.get(config.value.badgeUrl);
        if (current === badgeRequest) badges.value = data;
    } catch {
        if (current === badgeRequest) badges.value = {};
    }
}
function onResize() { if (window.innerWidth >= 768) drawerOpen.value = false; }
watch(() => route.fullPath, () => { drawerOpen.value = false; keyboardOpen.value = false; loadBadges(); });
watch(title, value => { document.title = `${value} — MapR`; }, { immediate: true });
onMounted(() => {
    loadBadges();
    window.addEventListener('mapr-nav-refresh', loadBadges);
    window.addEventListener('resize', onResize);
    window.addEventListener('focusin', onFocusIn);
    window.addEventListener('focusout', onFocusOut);
});
onUnmounted(() => {
    badgeRequest++;
    window.clearTimeout(focusTimer);
    window.removeEventListener('mapr-nav-refresh', loadBadges);
    window.removeEventListener('resize', onResize);
    window.removeEventListener('focusin', onFocusIn);
    window.removeEventListener('focusout', onFocusOut);
});
</script>

<template>
    <div class="resident-app workspace-app" :class="[{ 'sidebar-collapsed': collapsed, 'keyboard-open': keyboardOpen, 'is-wizard': isWizard }, workspace !== 'resident' ? 'cdrrmo-app' : '', `workspace-${workspace}`]">
        <WorkspaceSidebar v-bind="sidebarProps" :collapsed="collapsed" />
        <div class="resident-main">
            <WorkspaceHeader :title="title" :label="config.label" :user-name="userName" :user-email="userEmail"
                :photo-url="photoUrl" :attention="attention" :collapsed="collapsed" :mobile-open="drawerOpen"
                :show-mobile-menu="workspace !== 'resident'" @toggle-collapse="collapsed = !collapsed" @open-menu="drawerOpen = true">
                <template #actions><slot name="header-actions" /></template>
            </WorkspaceHeader>
            <main class="resident-content"><slot /></main>
        </div>
        <nav class="resident-bottom-nav" :class="{ 'workspace-bottom-nav': workspace !== 'resident' }" :aria-label="`${config.label} navigation`">
            <template v-for="(item, index) in mobile" :key="item.to">
                <div v-if="workspace === 'resident' && index === 2" class="resident-bottom-slot"><slot name="bottom-action" /></div>
                <router-link :to="item.to" active-class="" exact-active-class="router-link-active" :class="{ 'router-link-active': item.active }">
                    <MapIcon :name="item.icon" /><span>{{ item.to === '/' ? 'Home' : item.to === '/profile' ? 'Profile' : item.label }}</span>
                </router-link>
            </template>
            <Button v-if="workspace !== 'resident'" type="button" text severity="secondary" aria-controls="workspace-mobile-sidebar" :aria-expanded="drawerOpen" @click="drawerOpen = true">
                <MapIcon name="panel" /><span>Menu</span>
            </Button>
        </nav>
        <Drawer v-if="workspace !== 'resident'" v-model:visible="drawerOpen" :header="`${config.label} navigation`" :aria-label="`${config.label} navigation`"
            append-to=".workspace-app" class="workspace-navigation-drawer" :style="{ width: 'min(21rem, 92vw)' }">
            <WorkspaceSidebar v-bind="sidebarProps" id="workspace-mobile-sidebar" class="workspace-mobile-sidebar" @navigate="drawerOpen = false" />
        </Drawer>
        <MaprFeedbackHost />
    </div>
</template>
