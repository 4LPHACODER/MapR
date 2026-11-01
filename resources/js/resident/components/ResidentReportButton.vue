<script setup>
import { computed, useId } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import MapIcon from '../../shared/components/MapIcon.vue';
import { residentAccount } from '../accountState';

const route = useRoute();
const router = useRouter();
const reasonId = useId();
const onReport = computed(() => route.path === '/report' || /^\/report\/[^/]+$/.test(route.path));
const canReport = computed(() => residentAccount.loaded && residentAccount.reportingOpen);
const reason = computed(() => {
    if (!residentAccount.loaded) return 'Reporting availability is still loading.';
    if (!residentAccount.reportingOpen) return 'Your barangay is not assigned yet. Ask barangay staff to assign it before reporting.';
    return 'Report an incident';
});

function openReport() {
    if (!canReport.value || onReport.value) return;
    router.push('/report');
}
</script>

<template>
    <button
        type="button"
        class="resident-report-plus"
        :class="{ 'is-active': onReport, 'is-disabled': !canReport }"
        aria-label="Report an incident"
        :aria-disabled="canReport ? 'false' : 'true'"
        :aria-describedby="canReport ? undefined : reasonId"
        :data-tooltip="reason"
        :title="reason"
        @click="openReport"
    >
        <MapIcon name="plus" />
        <span v-if="!canReport" :id="reasonId" class="resident-profile-sr">{{ reason }}</span>
    </button>
</template>
