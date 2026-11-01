<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { navItems } from './nav';

const logoUrl = '/images/mapr-logo.png';

const props = defineProps({
    signInUrl: {
        type: String,
        default: '',
    },
    dashboardUrl: {
        type: String,
        default: '',
    },
});

const open = ref(false);
const scrolled = ref(false);
const menuButton = ref(null);
const menuPanel = ref(null);

function closeMenu() {
    open.value = false;
}

function toggleMenu() {
    open.value = !open.value;
}

async function onKeydown(event) {
    if (event.key === 'Escape' && open.value) {
        closeMenu();
        await nextTick();
        menuButton.value?.focus();
    }
}

function onScroll() {
    scrolled.value = window.scrollY > 8;
}

watch(open, async (isOpen) => {
    if (!isOpen) {
        return;
    }

    await nextTick();
    menuPanel.value?.querySelector('a, button')?.focus();
});

onMounted(() => {
    onScroll();
    window.addEventListener('keydown', onKeydown);
    window.addEventListener('scroll', onScroll, { passive: true });
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    window.removeEventListener('scroll', onScroll);
});
</script>

<template>
    <header class="header" :class="{ 'is-scrolled': scrolled }">
        <div class="mapr-wrap bar">
            <a class="brand" href="#top" aria-label="MapR home">
                <img :src="logoUrl" alt="" width="288" height="287">
            </a>

            <nav class="desktop" aria-label="Primary">
                <a v-for="item in navItems" :key="item.href" :href="item.href">{{ item.label }}</a>
            </nav>

            <div class="actions">
                <a v-if="!dashboardUrl" class="mapr-button mapr-button-secondary" :href="signInUrl">Login</a>
                <a class="mapr-button mapr-button-primary" :href="dashboardUrl || signInUrl">
                    {{ dashboardUrl ? 'Open Dashboard' : 'Get Started' }}
                </a>
                <button
                    ref="menuButton"
                    class="menu-button"
                    type="button"
                    aria-controls="mobile-menu"
                    :aria-expanded="open ? 'true' : 'false'"
                    @click="toggleMenu"
                >
                    <span class="sr-only">{{ open ? 'Close menu' : 'Open menu' }}</span>
                    <span aria-hidden="true">{{ open ? 'Close' : 'Menu' }}</span>
                </button>
            </div>
        </div>

        <div v-if="open" id="mobile-menu" ref="menuPanel" class="mobile">
            <a v-for="item in navItems" :key="item.href" :href="item.href" @click="closeMenu">{{ item.label }}</a>
            <a v-if="!dashboardUrl" :href="signInUrl" @click="closeMenu">Login</a>
            <a :href="dashboardUrl || signInUrl" @click="closeMenu">{{ dashboardUrl ? 'Open Dashboard' : 'Get Started' }}</a>
        </div>
    </header>
</template>

<style scoped>
.header {
    position: sticky;
    top: 0;
    z-index: 30;
    background: rgba(255, 255, 255, 0.82);
    backdrop-filter: blur(14px);
}

.header.is-scrolled {
    border-bottom: 1px solid #e2e8f0;
    box-shadow: 0 8px 24px rgba(15, 23, 42, 0.05);
}

.bar {
    display: flex;
    min-height: 5rem;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}

.brand img {
    display: block;
    width: auto;
    height: 4.4rem;
}

.desktop {
    display: none;
    gap: 1.4rem;
}

.desktop a {
    color: #0f172a;
    font-size: 0.9375rem;
    font-weight: 600;
    text-decoration: none;
}

.desktop a:hover {
    color: #2563eb;
}

.actions {
    display: flex;
    align-items: center;
    gap: 0.6rem;
}

.actions .mapr-button-secondary {
    display: none;
}

.menu-button {
    min-height: 2.75rem;
    border: 1px solid #e2e8f0;
    border-radius: 999px;
    background: #ffffff;
    padding: 0.4rem 0.9rem;
    color: #123b70;
    font: inherit;
    font-weight: 600;
}

.mobile {
    display: grid;
    gap: 0.35rem;
    border-top: 1px solid #e2e8f0;
    padding: 0.75rem 1.25rem 1rem;
    background: #ffffff;
}

.mobile a {
    border-radius: 0.8rem;
    padding: 0.75rem 0.4rem;
    color: #0f172a;
    font-weight: 600;
    text-decoration: none;
}

.mobile a:hover {
    background: #eff6ff;
}

.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}

@media (min-width: 960px) {
    .desktop,
    .actions .mapr-button-secondary {
        display: flex;
    }

    .menu-button,
    .mobile {
        display: none;
    }
}
</style>
