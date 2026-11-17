<script setup>
import { provide } from 'vue';
import WorkspaceLayout from '../shared/layouts/WorkspaceLayout.vue';
import { useMapReportContext } from './composables/mapReportContext.js';

defineProps({
    userName: { type: String, default: '' },
    userEmail: { type: String, default: '' },
    logoutUrl: { type: String, default: '/logout' },
    homeUrl: { type: String, default: '/' },
    csrfToken: { type: String, default: '' },
});

const reportContext = useMapReportContext();
provide('reportContext', reportContext);
</script>

<template>
    <WorkspaceLayout v-bind="$props" workspace="cdrrmo">
        <router-view />
    </WorkspaceLayout>
</template>
