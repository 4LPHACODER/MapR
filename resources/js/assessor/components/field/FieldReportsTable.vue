<script setup>
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import Skeleton from 'primevue/skeleton';
import Tag from 'primevue/tag';
import MapIcon from '../../../shared/components/MapIcon.vue';

defineProps({
    reports: { type: Array, default: () => [] },
    tab: { type: String, required: true },
    search: { type: String, default: '' },
    selectedBarangayId: { type: [Number, String, null], default: null },
    loading: { type: Boolean, default: false },
    formatWhen: { type: Function, required: true },
    statusSeverity: { type: Function, required: true },
});

const emit = defineEmits(['update:tab', 'update:search', 'search', 'clear-barangay', 'open']);

const tabs = [
    { id: 'ready', label: 'Ready for Consolidation' },
    { id: 'ready_for_submission', label: 'Ready for Submission' },
    { id: 'submitted', label: 'Submitted to CDRRMO' },
    { id: 'returned', label: 'Returned for Correction' },
];
</script>

<template>
    <section id="field-reports-table" class="field-panel" aria-label="Assessment report registry">
        <header class="field-section-heading">
            <div>
                <h2>Assessment Report Registry</h2>
                <p>Search and review eligible field assessments by consolidation status.</p>
            </div>
        </header>

        <div class="field-tabs" role="tablist">
            <button
                v-for="item in tabs"
                :key="item.id"
                type="button"
                role="tab"
                :aria-selected="tab === item.id"
                @click="emit('update:tab', item.id)"
            >{{ item.label }}</button>
        </div>

        <div class="field-table-toolbar">
            <InputText
                :model-value="search"
                size="small"
                placeholder="Search reference, barangay, incident, assessor"
                @update:model-value="emit('update:search', $event)"
                @keyup.enter="emit('search')"
            />
            <Button type="button" size="small" severity="secondary" outlined label="Search" @click="emit('search')">
                <template #icon><MapIcon name="search" /></template>
            </Button>
            <Button
                v-if="selectedBarangayId"
                type="button"
                size="small"
                text
                label="Clear barangay filter"
                @click="emit('clear-barangay')"
            />
        </div>

        <div v-if="loading" class="field-skeleton-stack" aria-busy="true">
            <Skeleton v-for="n in 4" :key="n" height="2.75rem" border-radius="0.55rem" />
        </div>
        <div v-else-if="!reports.length" class="field-empty field-empty--compact">
            <MapIcon name="clipboard-list" />
            <strong>No assessments in this view</strong>
            <span>Try another status tab or clear the barangay filter.</span>
        </div>
        <div v-else class="field-table-wrap">
            <table class="cdrrmo-table field-registry-table">
                <thead>
                    <tr>
                        <th>Report Reference</th>
                        <th>Barangay</th>
                        <th>Incident</th>
                        <th>Assessor</th>
                        <th>Verified By</th>
                        <th>Reviewed By</th>
                        <th>Status</th>
                        <th>Updated</th>
                        <th><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in reports" :key="row.id">
                        <td>
                            <button type="button" class="report-ref-button" @click="emit('open', row.id)">{{ row.reference }}</button>
                        </td>
                        <td>{{ row.barangay_name || 'Not Recorded' }}</td>
                        <td>{{ row.incident_name || 'Not Recorded' }}</td>
                        <td>{{ row.assessor_name || 'Not Recorded' }}</td>
                        <td>{{ row.verified_by || 'Not Recorded' }}</td>
                        <td>{{ row.reviewed_by || 'Not Recorded' }}</td>
                        <td><Tag :value="row.label" :severity="statusSeverity(row.status)" /></td>
                        <td>{{ formatWhen(row.updated_at) }}</td>
                        <td class="report-row-actions">
                            <button type="button" class="residents-icon-btn" title="View" aria-label="View" @click="emit('open', row.id)">
                                <MapIcon name="eye" />
                            </button>
                            <a
                                v-if="row.barangay_report_id"
                                class="residents-icon-btn"
                                :href="`/rdana/reports/barangay/${row.barangay_report_id}/preview`"
                                target="_blank"
                                rel="noopener"
                                title="Supporting documents"
                                aria-label="Supporting documents"
                            >
                                <MapIcon name="file" />
                            </a>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
