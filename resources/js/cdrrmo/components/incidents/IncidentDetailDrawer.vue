<script setup>
import Button from 'primevue/button';
import Drawer from 'primevue/drawer';
import Message from 'primevue/message';
import ProgressSpinner from 'primevue/progressspinner';
import Tag from 'primevue/tag';
import MapIcon from '../../../shared/components/MapIcon.vue';
defineProps({ open: Boolean, incident: { type: Object, default: null }, loading: Boolean, error: { type: String, default: '' }, editing: Boolean });
defineEmits(['close', 'retry', 'edit', 'map', 'report', 'hide']);
</script>

<template>
    <Drawer :visible="open" position="right" header="Incident details" aria-label="Incident details" class="cdrrmo-incidents-overlay incident-detail-drawer" block-scroll @update:visible="!$event && $emit('close')" @after-hide="$emit('hide')">
        <div v-if="loading" class="incident-drawer-loading" role="status"><ProgressSpinner aria-label="Loading incident details" /><p>Loading incident details…</p></div>
        <div v-else-if="error" class="incident-detail-section"><Message severity="error" :closable="false">{{ error }}</Message><Button label="Try again" size="small" severity="secondary" outlined @click="$emit('retry')" /></div>
        <template v-else-if="incident">
            <div class="incident-detail-intro">
                <p class="incident-reference">{{ incident.incident_reference }}</p>
                <h3>{{ incident.incident_name }}</h3>
                <div class="incident-type-line"><Tag :value="incident.incident_status === 'closed' ? 'Closed' : 'Active'" :severity="incident.incident_status === 'closed' ? 'secondary' : 'info'" /><Tag v-if="incident.is_demo" value="Demo data" severity="secondary" /></div>
            </div>
            <dl class="incident-detail-facts">
                <div><dt>Disaster type</dt><dd>{{ incident.disaster_type || 'Not recorded' }}</dd></div>
                <div><dt>Occurrence</dt><dd>{{ incident.occurred_at_display }}</dd></div>
                <div><dt>Associated barangays</dt><dd>{{ incident.barangays?.map((barangay) => barangay.name).join(', ') || 'Not recorded' }}</dd></div>
            </dl>
            <section class="incident-detail-section"><h4>Description</h4><p class="incident-description">{{ incident.description || 'No description was recorded.' }}</p></section>
            <section class="incident-detail-section">
                <h4>Related reports <span class="incident-report-count">{{ incident.reports?.length || 0 }}</span></h4>
                <p v-if="!incident.reports?.length" class="incident-help">No related reports are available for this incident.</p>
                <ul v-else class="incident-related-reports">
                    <li v-for="report in incident.reports" :key="`${report.type}-${report.reference}`">
                        <MapIcon name="file-text" />
                        <div><strong>{{ report.reference }}</strong><span>{{ report.type }}<template v-if="report.context"> · {{ report.context }}</template></span><span>{{ report.status }}</span><Button v-if="report.open_path" label="Open report" size="small" severity="secondary" text @click="$emit('report', report.open_path)" /></div>
                    </li>
                </ul>
            </section>
        </template>
        <template #footer>
            <div class="incident-drawer-actions">
                <Button label="Edit incident" size="small" :disabled="loading || !!error || editing" :loading="editing" @click="$emit('edit')"><template #icon><MapIcon name="pencil" /></template></Button>
                <Button label="Assessment Map" severity="secondary" outlined size="small" :disabled="loading || !!error" @click="$emit('map')"><template #icon><MapIcon name="map" /></template></Button>
            </div>
        </template>
    </Drawer>
</template>
