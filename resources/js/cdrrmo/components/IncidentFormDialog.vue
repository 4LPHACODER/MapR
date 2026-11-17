<script setup>
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { onBeforeRouteLeave } from 'vue-router';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import Message from 'primevue/message';
import MapIcon from '../../shared/components/MapIcon.vue';
import IncidentFormFields from './incidents/IncidentFormFields.vue';
import IncidentBarangayPicker from './incidents/IncidentBarangayPicker.vue';

const props = defineProps({ open: Boolean, incident: { type: Object, default: null }, types: { type: Array, default: () => [] }, barangays: { type: Array, default: () => [] } });
const emit = defineEmits(['close', 'saved', 'hide']);
const saving = ref(false);
const error = ref('');
const fieldErrors = ref({});
const closePrompt = ref(false);
const discardOpen = ref(false);
const formElement = ref(null);
const form = reactive({ incident_name: '', disaster_type_id: '', occurred_at: '', incident_status: 'active', description: '', barangay_ids: [] });
const editing = computed(() => !!props.incident?.id);
const title = computed(() => editing.value ? 'Edit Disaster Incident' : 'Create Disaster Incident');
let initialSnapshot = '';
let leaveDecision;
function snapshot() { return JSON.stringify({ ...form, barangay_ids: [...form.barangay_ids].map(Number).sort((a, b) => a - b) }); }
const dirty = computed(() => props.open && snapshot() !== initialSnapshot);

watch(() => props.open, (open) => {
    if (!open) return;
    Object.assign(form, {
        incident_name: props.incident?.incident_name || '',
        disaster_type_id: props.incident?.disaster_type_id ? String(props.incident.disaster_type_id) : '',
        occurred_at: props.incident?.occurred_at_local || '',
        incident_status: props.incident?.incident_status || 'active',
        description: props.incident?.description || '',
        barangay_ids: (props.incident?.barangays || []).map((row) => Number(row.id)),
    });
    error.value = '';
    fieldErrors.value = {};
    closePrompt.value = false;
    discardOpen.value = false;
    initialSnapshot = snapshot();
});
function requestClose() {
    if (saving.value) return;
    if (dirty.value) discardOpen.value = true;
    else emit('close');
}
function decideDiscard(discard) {
    discardOpen.value = false;
    if (discard) emit('close');
    leaveDecision?.(discard);
    leaveDecision = null;
}
function beforeUnload(event) {
    if (!dirty.value) return;
    event.preventDefault();
    event.returnValue = '';
}
watch(dirty, (value) => {
    window.removeEventListener('beforeunload', beforeUnload);
    if (value) window.addEventListener('beforeunload', beforeUnload);
});
onBeforeRouteLeave(() => {
    if (saving.value) return false;
    if (!dirty.value) return true;
    discardOpen.value = true;
    return new Promise((resolve) => { leaveDecision = resolve; });
});
onBeforeUnmount(() => { window.removeEventListener('beforeunload', beforeUnload); leaveDecision?.(false); });
async function focusFirstError() {
    await nextTick();
    const invalid = formElement.value?.querySelector('[aria-invalid="true"]');
    const target = invalid?.matches('input, textarea, [tabindex]') ? invalid : invalid?.querySelector('input, [tabindex]');
    target?.focus();
}
async function save() {
    if (saving.value) return;
    const errors = {};
    if (!form.incident_name.trim()) errors.incident_name = ['Enter an incident name.'];
    if (!form.disaster_type_id) errors.disaster_type_id = ['Choose a disaster type.'];
    if (!form.occurred_at) errors.occurred_at = ['Enter the date and time of occurrence.'];
    if (!form.barangay_ids.length) errors.barangay_ids = ['Select at least one barangay.'];
    fieldErrors.value = errors;
    error.value = '';
    if (Object.keys(errors).length) { await focusFirstError(); return; }
    const closing = editing.value && props.incident.incident_status === 'active' && form.incident_status === 'closed';
    if (closing && !closePrompt.value) { closePrompt.value = true; return; }
    saving.value = true;
    try {
        const payload = { ...form, disaster_type_id: Number(form.disaster_type_id), barangay_ids: form.barangay_ids.map(Number), confirm_close: closing };
        if (editing.value) await window.axios.patch(`/cdrrmo/api/incidents/${props.incident.id}`, payload);
        else await window.axios.post('/cdrrmo/api/incidents', payload);
        initialSnapshot = snapshot();
        emit('saved', editing.value ? 'Incident updated.' : 'Incident created.');
    } catch (exception) {
        fieldErrors.value = exception.response?.data?.errors || {};
        error.value = exception.response?.data?.message || 'The incident could not be saved. Your changes are still here; try again.';
        closePrompt.value = false;
        await focusFirstError();
    } finally { saving.value = false; }
}
</script>

<template>
    <Dialog :visible="open" :header="title" modal :draggable="false" :closable="!saving" :close-on-escape="!saving && !discardOpen" class="cdrrmo-incidents-overlay incident-form-dialog" @update:visible="!$event && requestClose()" @after-hide="$emit('hide')">
        <form id="incident-form" ref="formElement" novalidate @submit.prevent="save">
            <p class="incident-form-intro">Enter the incident details and select the associated barangays. Fields marked * are required.</p>
            <Message v-if="error" severity="error" :closable="false">{{ error }}</Message>
            <Message v-if="closePrompt" severity="warn" :closable="false">Closing this incident does not approve or complete its reports. Choose Save and close incident to confirm.</Message>
            <fieldset class="incident-edit-fields" :disabled="saving">
                <legend class="incident-sr-only">Incident details</legend>
                <IncidentFormFields v-model="form" :types="types" :errors="fieldErrors" :reference="incident?.incident_reference" :disabled="saving" />
                <IncidentBarangayPicker v-model="form.barangay_ids" :barangays="barangays" :error="fieldErrors.barangay_ids?.[0]" :disabled="saving" />
            </fieldset>
        </form>
        <template #footer><Button label="Cancel" severity="secondary" outlined size="small" :disabled="saving" @click="requestClose" /><Button type="submit" form="incident-form" :label="closePrompt && form.incident_status === 'closed' ? 'Save and close incident' : editing ? 'Save Changes' : 'Create Incident'" size="small" :loading="saving" :disabled="saving"><template #icon><MapIcon :name="editing ? 'save' : 'plus'" /></template></Button></template>
    </Dialog>
    <Dialog :visible="discardOpen" header="Discard unsaved changes?" modal :draggable="false" class="cdrrmo-incidents-overlay incident-status-dialog" @update:visible="!$event && decideDiscard(false)">
        <p>Your incident changes have not been saved. Discard them or keep editing.</p>
        <template #footer><Button label="Keep editing" size="small" severity="secondary" outlined autofocus @click="decideDiscard(false)" /><Button label="Discard changes" size="small" severity="danger" @click="decideDiscard(true)" /></template>
    </Dialog>
</template>
