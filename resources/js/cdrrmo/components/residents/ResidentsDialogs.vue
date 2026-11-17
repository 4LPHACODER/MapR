<script setup>
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';
import Select from 'primevue/select';
import Skeleton from 'primevue/skeleton';
import Textarea from 'primevue/textarea';
const props = defineProps({ archiveTarget: { type: Object, default: null }, archiveReference: { type: String, default: '' }, archiveError: { type: String, default: '' }, inclusionOpen: Boolean, inclusionForm: { type: Object, required: true }, inclusionError: { type: String, default: '' }, incidentId: { type: [String, Number], default: '' }, incidentName: { type: String, default: '' }, selectedCount: { type: Number, default: 0 }, selectedBarangays: { type: Array, default: () => [] }, reportsOpen: Boolean, reportsResident: { type: Object, default: null }, reports: { type: Object, default: null }, reportsError: { type: String, default: '' }, reportGroups: { type: Array, default: () => [] }, reportsEmpty: Boolean, busy: Boolean });
const emit = defineEmits(['update:archiveReference', 'close-archive', 'archive', 'close-inclusion', 'inclusion', 'close-reports', 'retry-reports', 'report', 'hide']);
const basisOptions = [{ label: 'Manual review', value: 'manual_review' }, { label: 'Selected area', value: 'selected_area' }, { label: 'Authorized report', value: 'authorized_report' }];
</script>

<template>
    <Dialog :visible="!!archiveTarget" header="Archive Resident" modal :draggable="false" :closable="!busy" :close-on-escape="!busy" class="cdrrmo-residents-overlay registry-dialog" @update:visible="!$event && $emit('close-archive')" @after-hide="$emit('hide')">
        <form id="archive-resident-form" class="registry-dialog-form" novalidate @submit.prevent="$emit('archive')">
            <p>Archiving hides <strong>{{ archiveTarget?.registry_reference }}</strong> from the active registry. The record is not deleted.</p>
            <div class="registry-field"><label for="archive-resident-reference">Type the resident reference to confirm</label><InputText id="archive-resident-reference" :model-value="archiveReference" :placeholder="archiveTarget?.registry_reference" autocomplete="off" size="small" :disabled="busy" :invalid="!!archiveError" :aria-describedby="archiveError ? 'archive-resident-error' : undefined" @update:model-value="$emit('update:archiveReference', $event)" /></div>
            <Message v-if="archiveError" id="archive-resident-error" severity="error" :closable="false">{{ archiveError }}</Message>
        </form>
        <template #footer><Button label="Cancel" severity="secondary" outlined size="small" autofocus :disabled="busy" @click="$emit('close-archive')" /><Button type="submit" form="archive-resident-form" label="Archive Resident" severity="danger" size="small" :loading="busy" :disabled="busy" /></template>
    </Dialog>

    <Dialog :visible="inclusionOpen" header="Add to potentially affected list" modal :draggable="false" :closable="!busy" :close-on-escape="!busy" class="cdrrmo-residents-overlay registry-dialog" @update:visible="!$event && $emit('close-inclusion')" @after-hide="$emit('hide')">
        <form id="resident-inclusion-form" class="registry-dialog-form" novalidate @submit.prevent="$emit('inclusion')">
            <p>This list is incident-specific. It does not confirm harm or change saved estimates.</p>
            <dl class="registry-dialog-facts"><div><dt>Incident</dt><dd>{{ incidentName || 'Select an incident' }}</dd></div><div><dt>Residents</dt><dd>{{ selectedCount }}</dd></div><div><dt>Barangays</dt><dd>{{ selectedBarangays.join(', ') || 'Not recorded' }}</dd></div></dl>
            <Message v-if="!incidentId" severity="info" :closable="false">Select an incident in the registry filters before confirming inclusion.</Message>
            <div class="registry-field"><label id="resident-inclusion-basis-label" for="resident-inclusion-basis">Basis</label><Select input-id="resident-inclusion-basis" aria-labelledby="resident-inclusion-basis-label" v-model="inclusionForm.basis" :options="basisOptions" option-label="label" option-value="value" size="small" :disabled="busy" panel-class="cdrrmo-residents-overlay" :pt="{ transition: { css: false } }" /></div>
            <div class="registry-field"><label for="resident-inclusion-reference">Reference</label><InputText id="resident-inclusion-reference" v-model="inclusionForm.reference" maxlength="191" size="small" :disabled="busy" /></div>
            <div class="registry-field"><label for="resident-inclusion-notes">Notes</label><Textarea id="resident-inclusion-notes" v-model="inclusionForm.notes" auto-resize rows="3" maxlength="1000" :disabled="busy" /></div>
            <Message v-if="inclusionError" severity="error" :closable="false">{{ inclusionError }}</Message>
        </form>
        <template #footer><Button label="Cancel" severity="secondary" outlined size="small" :disabled="busy" @click="$emit('close-inclusion')" /><Button type="submit" form="resident-inclusion-form" label="Confirm inclusion" size="small" :disabled="busy || !incidentId" :loading="busy" /></template>
    </Dialog>

    <Dialog :visible="reportsOpen" header="Related reports" modal :draggable="false" class="cdrrmo-residents-overlay registry-dialog registry-reports-dialog" @update:visible="!$event && $emit('close-reports')" @after-hide="$emit('hide')">
        <div class="registry-dialog-form">
            <div class="registry-report-identity"><strong>{{ reportsResident?.display_name }}</strong><span class="registry-reference">{{ reportsResident?.registry_reference }}</span></div>
            <Message v-if="reportsError" severity="error" :closable="false">{{ reportsError }} <Button label="Retry" size="small" text @click="$emit('retry-reports')" /></Message>
            <div v-else-if="!reports" class="registry-report-loading" role="status" aria-label="Loading related reports"><Skeleton height="16px" width="60%" /><Skeleton height="48px" /><Skeleton height="48px" /></div>
            <p v-else-if="reportsEmpty" class="registry-muted">No related reports are available for this resident.</p>
            <template v-else><section v-for="[label, rows] in reportGroups" :key="label" class="registry-report-group"><h4>{{ label }}</h4><ul v-if="rows.length"><li v-for="item in rows" :key="item.report_reference + item.report_type"><strong>{{ item.report_reference }}</strong><span>{{ item.report_type }} · {{ item.incident }} · {{ item.relationship }} · {{ item.status }}</span><Button v-if="item.open_path" label="Open report" size="small" severity="secondary" outlined @click="$emit('report', item)" /></li></ul><p v-else class="registry-muted">None in this group.</p></section></template>
        </div>
        <template #footer><Button label="Close" severity="secondary" outlined size="small" @click="$emit('close-reports')" /></template>
    </Dialog>
</template>
