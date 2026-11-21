<script setup>
import { ref, watch } from 'vue';
import Button from 'primevue/button';
import ReportPdfViewer from '../../shared/components/ReportPdfViewer.vue';

const props = defineProps({
    documentUrl: { type: String, required: true },
});

const viewer = ref(null);
const failed = ref(false);

function reload() {
    failed.value = false;
    return viewer.value?.reload();
}

watch(() => props.documentUrl, () => { failed.value = false; });
defineExpose({ reload });
</script>

<template>
    <div>
        <ReportPdfViewer ref="viewer" presentation="review" fit-on-open :document-url="documentUrl" @error="failed = true" />
        <Button v-if="failed" class="report-preview-retry" size="small" outlined label="Reload preview" @click="reload" />
    </div>
</template>

<style scoped>
:deep(.resident-report-zoom) {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.25rem 0.375rem;
    min-height: 2.75rem;
    padding: 0.25rem 0.625rem;
    border-bottom: 1px solid var(--resident-line);
    color: var(--p-slate-600, #475569);
    font-variant-numeric: tabular-nums;
}

:deep(.resident-report-zoom button) {
    display: inline-flex;
    justify-content: center;
    align-items: center;
    min-width: 2rem;
    min-height: 2rem;
    padding-inline: 0.5rem;
    border-radius: var(--resident-control);
    color: var(--resident-navy);
    font-weight: 500;
    cursor: pointer;
}

:deep(.resident-report-zoom button:hover) {
    background: var(--resident-wash);
}

:deep(.resident-report-zoom button[aria-pressed="true"]) {
    background: var(--resident-wash);
    color: var(--resident-blue-hover);
}

:deep(.resident-report-zoom > span:last-child) {
    margin-inline-start: auto;
}

:deep(.resident-report-host) {
    min-width: 0;
    height: clamp(12rem, 64dvh, 50rem);
    overflow: auto;
    scrollbar-gutter: stable;
    padding: clamp(0.5rem, 1.6cqi, 1.25rem);
    border-radius: 0 0 var(--resident-radius) var(--resident-radius);
    background: var(--p-slate-100, #f1f5f9);
    scrollbar-color: var(--p-slate-400, #94a3b8) var(--p-slate-100, #f1f5f9);
}

:deep(.resident-report-stage) {
    display: grid;
    justify-items: center;
    gap: 1rem;
    width: max-content;
    min-width: 100%;
}

:deep(.resident-report-sheet) {
    width: auto;
    max-width: none;
    height: auto;
    background: var(--resident-card);
    box-shadow: 0 0 0 1px var(--resident-line), var(--resident-shadow);
}

:deep(.resident-error) {
    padding: 0.75rem 1rem;
    color: var(--p-red-700, #b91c1c);
}

:deep(.resident-error ~ :is(.resident-report-zoom, .resident-report-host)) {
    display: none;
}

.report-preview-retry { margin: 0.75rem 1rem; }

:deep(.resident-report-host:focus-visible) { outline-offset: -2px; }

@media (max-width: 767px), (pointer: coarse) {
    :deep(.resident-report-zoom button) { min-height: 2.75rem; min-width: 2.75rem; }
    :deep(.resident-report-host) { height: clamp(12rem, 52dvh, 40rem); }
}
</style>
