<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';

const props = defineProps({
    userName: { type: String, default: '' },
    userEmail: { type: String, default: '' },
    logoutUrl: { type: String, default: '/logout' },
    csrfToken: { type: String, default: '' },
});

defineEmits(['open-menu']);

const route = useRoute();
const pageTitle = computed(() => route.meta.title || 'CDRRMO workspace');
const initials = computed(() => {
    const parts = props.userName.trim().split(/\s+/).filter(Boolean);
    return parts.length ? parts.slice(0, 2).map((p) => p[0]?.toUpperCase() || '').join('') : 'MA';
});
</script>

<template>
    <header class="cdrrmo-header">
        <div class="cdrrmo-header-left">
            <button type="button" class="cdrrmo-menu-btn" aria-label="Open navigation menu" @click="$emit('open-menu')">☰</button>
            <h1>{{ pageTitle }}</h1>
        </div>
        <div class="cdrrmo-header-user">
            <div class="cdrrmo-user-meta">
                <strong>{{ userName }}</strong>
                <span :title="userEmail">{{ userEmail }}</span>
            </div>
            <div class="cdrrmo-avatar" aria-hidden="true">{{ initials }}</div>
            <form method="POST" :action="logoutUrl">
                <input type="hidden" name="_token" :value="csrfToken">
                <button type="submit" class="mapr-button mapr-button-secondary">Log out</button>
            </form>
        </div>
    </header>
</template>
