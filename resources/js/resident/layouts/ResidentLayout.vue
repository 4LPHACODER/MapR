<script setup>
import { inject, onMounted, onUnmounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import WorkspaceLayout from '../../shared/layouts/WorkspaceLayout.vue';
import ResidentReportButton from '../components/ResidentReportButton.vue';
import { residentAccount } from '../accountState';

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
const attention = ref([]);
let request = 0;
async function loadAttention() {
    const current = ++request;
    try {
        const { data } = await window.axios.get(`${apiBase}/dashboard`);
        if (current === request) attention.value = data.attention || [];
    } catch {
        if (current === request) attention.value = [];
    }
}
onMounted(loadAttention);
watch(() => route.fullPath, loadAttention);
onUnmounted(() => { request++; });
</script>

<template>
    <WorkspaceLayout v-bind="$props" workspace="resident" :photo-url="residentAccount.photoUrl || ''" :attention="attention">
        <template #header-actions><ResidentReportButton /></template>
        <template #bottom-action><ResidentReportButton /></template>
        <slot />
    </WorkspaceLayout>
</template>
