<script setup>
import Button from 'primevue/button';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import Tag from 'primevue/tag';
import MapIcon from '../../../shared/components/MapIcon.vue';
defineProps({ reports: { type: Array, default: () => [] }, total: { type: Number, default: 0 }, formatWhen: { type: Function, required: true } });
</script>

<template>
    <section class="cdrrmo-dash-panel cdrrmo-dash-review" aria-labelledby="cdrrmo-review-heading">
        <header class="cdrrmo-dash-panel-heading">
            <div><h3 id="cdrrmo-review-heading">Awaiting CDRRMO review <Tag :value="total" severity="warn" /></h3><p>Submitted field assessments, oldest first.</p></div>
            <Button as="router-link" to="/reports/field" label="All field reports" size="small" text severity="secondary" />
        </header>
        <DataTable :value="reports" data-key="id" size="small" :paginator="reports.length > 5" :rows="5" paginator-template="PrevPageLink CurrentPageReport NextPageLink" current-page-report-template="{first}–{last} of {totalRecords}" table-style="min-width: 35rem" :table-props="{ 'aria-label': 'Field assessments awaiting review' }">
            <Column header="Field assessment">
                <template #body="{ data }"><div class="cdrrmo-dash-report-cell"><router-link :to="{ path: '/reports/field', query: { reference: data.assessment_reference || data.report_reference } }">{{ data.assessment_reference || data.report_reference }}</router-link><span>{{ data.incident_name }}</span><span>Barangay report {{ data.report_reference }}</span></div></template>
            </Column>
            <Column header="Barangay / assessor"><template #body="{ data }"><div class="cdrrmo-dash-report-cell"><strong>{{ data.barangay_name }}</strong><span>{{ data.assessor_name }}</span></div></template></Column>
            <Column header="Submitted"><template #body="{ data }"><div class="cdrrmo-dash-report-cell"><Tag :value="data.status_label || 'Submitted'" severity="info" /><time :datetime="data.submitted_at || data.updated_at">{{ formatWhen(data.submitted_at || data.updated_at) }}</time></div></template></Column>
            <template #empty><div class="cdrrmo-dash-empty"><MapIcon name="clipboard-check" /><strong>No assessments awaiting review</strong><p>Submitted field assessments will appear here. Drafts stay with the assessor.</p></div></template>
        </DataTable>
        <p v-if="reports.length && total > reports.length" class="cdrrmo-dash-panel-foot">Showing the oldest {{ reports.length }} of {{ total }} assessments. Open all field reports to continue.</p>
    </section>
</template>
