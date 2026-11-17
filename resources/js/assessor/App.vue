<script setup>
import { provide, reactive } from 'vue';
import WorkspaceLayout from '../shared/layouts/WorkspaceLayout.vue';

defineProps({
    userName: { type: String, default: '' },
    userEmail: { type: String, default: '' },
    jobTitle: { type: String, default: '' },
    logoutUrl: { type: String, default: '/logout' },
    homeUrl: { type: String, default: '/' },
    csrfToken: { type: String, default: '' },
});

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
        router.push({ path: '/residents' });
    },
});
</script>

<template>
    <WorkspaceLayout v-bind="$props" workspace="assessor">
        <router-view />
    </WorkspaceLayout>
</template>
