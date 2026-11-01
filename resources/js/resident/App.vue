<script setup>
import { computed, inject, onMounted, onUnmounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import ResidentSidebar from './components/ResidentSidebar.vue';
import ResidentHeader from './components/ResidentHeader.vue';
import ResidentBottomNav from './components/ResidentBottomNav.vue';
import { applyResidentAccount } from './accountState';

defineProps({
    userName: { type: String, default: '' },
    userEmail: { type: String, default: '' },
    jobTitle: { type: String, default: 'Resident' },
    logoutUrl: { type: String, default: '/logout' },
    homeUrl: { type: String, default: '/' },
    csrfToken: { type: String, default: '' },
});

const apiBase = inject('apiBase');
const route = useRoute();
const collapsed = ref(false);
const keyboardOpen = ref(false);
const title = computed(() => route.meta.title || 'Resident');
const isWizard = computed(() => route.meta.wizard === true);

function onFocusIn(event) {
    if (!isWizard.value) return;
    const tag = event.target?.tagName;
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') keyboardOpen.value = window.innerWidth < 768;
}

function onFocusOut() {
    window.setTimeout(() => {
        const active = document.activeElement?.tagName;
        if (active !== 'INPUT' && active !== 'TEXTAREA' && active !== 'SELECT') keyboardOpen.value = false;
    }, 120);
}

onMounted(() => {
    window.addEventListener('focusin', onFocusIn);
    window.addEventListener('focusout', onFocusOut);
    window.axios.get(`${apiBase}/profile`).then((response) => {
        applyResidentAccount(response.data.data);
    }).catch(() => {
        applyResidentAccount({ reporting_open: false, profile_photo_url: null });
    });
});
onUnmounted(() => {
    window.removeEventListener('focusin', onFocusIn);
    window.removeEventListener('focusout', onFocusOut);
});
</script>

<template>
    <div class="resident-app" :class="{ 'sidebar-collapsed': collapsed, 'keyboard-open': keyboardOpen, 'is-wizard': isWizard }">
        <ResidentSidebar
            :home-url="homeUrl"
            :user-name="userName"
            :job-title="jobTitle"
            :logout-url="logoutUrl"
            :csrf-token="csrfToken"
            :collapsed="collapsed"
            @toggle-collapse="collapsed = !collapsed"
        />
        <div class="resident-main">
            <ResidentHeader :title="title" :user-name="userName" />
            <main class="resident-content">
                <router-view />
            </main>
        </div>
        <ResidentBottomNav />
    </div>
</template>
