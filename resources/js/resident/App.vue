<script setup>
import { inject, onMounted } from 'vue';
import ResidentLayout from './layouts/ResidentLayout.vue';
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

onMounted(() => {
    window.axios.get(`${apiBase}/profile`).then((response) => {
        applyResidentAccount(response.data.data);
    }).catch(() => {
        applyResidentAccount({ reporting_open: false, profile_photo_url: null });
    });
});
</script>

<template>
    <ResidentLayout v-bind="$props">
        <router-view />
    </ResidentLayout>
</template>
