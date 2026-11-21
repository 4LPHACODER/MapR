<script setup>
import Button from 'primevue/button';
import MapIcon from '../../../shared/components/MapIcon.vue';

defineProps({
    exports: { type: Object, default: () => ({ consolidated: null, barangays: [] }) },
    canExport: { type: Boolean, default: false },
    canSubmitPackage: { type: Boolean, default: false },
    exporting: { type: Boolean, default: false },
    formatWhen: { type: Function, required: true },
});

const emit = defineEmits(['export-consolidated', 'export-barangay', 'preview', 'view-history', 'submit']);
</script>

<template>
    <section class="field-panel" aria-label="Report consolidation">
        <header class="field-section-heading">
            <div>
                <h2>Report Consolidation</h2>
                <p>Generate official Excel workbooks from the current eligible assessment snapshot.</p>
            </div>
        </header>

        <article class="field-export-hero">
            <div class="field-export-hero__body">
                <span class="field-kpi-icon" aria-hidden="true"><MapIcon name="file-spreadsheet" /></span>
                <div>
                    <h3>Consolidated Assessment Report</h3>
                    <p>Generate a consolidated workbook containing eligible reviewed assessment records from all four barangays.</p>
                    <ul class="field-export-meta">
                        <li>Included barangays: {{ exports.consolidated?.barangay_count ?? (exports.barangays?.length || 0) }}</li>
                        <li>Eligible reports: {{ exports.consolidated?.eligible_count || 0 }}</li>
                        <li>Last generated: {{ formatWhen(exports.consolidated?.generated_at) }}</li>
                    </ul>
                </div>
            </div>
            <div class="field-card-actions field-card-actions--toolbar">
                <Button type="button" size="small" severity="secondary" outlined label="Preview" @click="emit('preview')">
                    <template #icon><MapIcon name="eye" /></template>
                </Button>
                <Button
                    type="button"
                    size="small"
                    severity="secondary"
                    outlined
                    label="Export Excel"
                    :disabled="!canExport"
                    :loading="exporting"
                    @click="emit('export-consolidated')"
                >
                    <template #icon><MapIcon name="file-spreadsheet" /></template>
                </Button>
                <Button type="button" size="small" severity="secondary" outlined label="View History" @click="emit('view-history')">
                    <template #icon><MapIcon name="history" /></template>
                </Button>
                <Button
                    type="button"
                    size="small"
                    label="Submit Package"
                    :disabled="!canSubmitPackage"
                    @click="emit('submit')"
                >
                    <template #icon><MapIcon name="send" /></template>
                </Button>
            </div>
        </article>

        <div class="field-export-list" aria-label="Individual barangay exports">
            <article v-for="row in exports.barangays || []" :key="row.barangay_name" class="field-export-row">
                <div class="field-export-row__info">
                    <span class="field-kpi-icon" aria-hidden="true"><MapIcon name="file-spreadsheet" /></span>
                    <div>
                        <h3>{{ row.barangay_name }}</h3>
                        <p>
                            {{ row.eligible_count || 0 }} eligible report{{ (row.eligible_count || 0) === 1 ? '' : 's' }}
                            · Last generated {{ formatWhen(row.last_generated_at || row.generated_at) }}
                        </p>
                    </div>
                </div>
                <Button
                    type="button"
                    size="small"
                    severity="secondary"
                    outlined
                    label="Download"
                    :disabled="!canExport || !row.barangay_id"
                    :loading="exporting"
                    @click="emit('export-barangay', row.barangay_id)"
                >
                    <template #icon><MapIcon name="download" /></template>
                </Button>
            </article>
        </div>
    </section>
</template>
