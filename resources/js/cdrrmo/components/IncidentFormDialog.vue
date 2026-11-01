<script setup>
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue';
import MapIcon from '../../shared/components/MapIcon.vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    incident: { type: Object, default: null },
    types: { type: Array, default: () => [] },
    barangays: { type: Array, default: () => [] },
});

const emit = defineEmits(['close', 'saved']);

const dialog = ref(null);
const firstField = ref(null);
const saving = ref(false);
const error = ref('');
const fieldErrors = ref({});
const closePrompt = ref(false);
const form = reactive({
    incident_name: '',
    disaster_type_id: '',
    occurred_at: '',
    incident_status: 'active',
    description: '',
    barangay_ids: [],
});
let initialSnapshot = '';

const editing = computed(() => !!props.incident?.id);
const title = computed(() => (editing.value ? 'Edit Disaster Incident' : 'Create Disaster Incident'));
const selectedIds = computed(() => form.barangay_ids.map((id) => Number(id)));

function snapshot() {
    return JSON.stringify({
        ...form,
        barangay_ids: [...form.barangay_ids].map(Number).sort((a, b) => a - b),
    });
}

function fill() {
    form.incident_name = props.incident?.incident_name || '';
    form.disaster_type_id = props.incident?.disaster_type_id ? String(props.incident.disaster_type_id) : '';
    form.occurred_at = props.incident?.occurred_at_local || '';
    form.incident_status = props.incident?.incident_status || 'active';
    form.description = props.incident?.description || '';
    form.barangay_ids = (props.incident?.barangays || []).map((barangay) => Number(barangay.id));
    error.value = '';
    fieldErrors.value = {};
    closePrompt.value = false;
    initialSnapshot = snapshot();
}

watch(() => props.open, async (isOpen) => {
    releaseFocus();
    if (!isOpen) return;
    fill();
    bindFocus();
    await nextTick();
    firstField.value?.focus();
});

onBeforeUnmount(releaseFocus);

function requestClose() {
    if (saving.value) return;
    if (snapshot() !== initialSnapshot && !window.confirm('Discard unsaved incident changes?')) return;
    releaseFocus();
    emit('close');
}

function onKeydown(event) {
    if (!props.open) return;
    if (event.key === 'Escape') {
        event.preventDefault();
        requestClose();
        return;
    }
    if (event.key !== 'Tab' || !dialog.value) return;
    const nodes = [...dialog.value.querySelectorAll('button, input, select, textarea')].filter((node) => !node.disabled);
    if (!nodes.length) return;
    const first = nodes[0];
    const last = nodes[nodes.length - 1];
    if (!dialog.value.contains(document.activeElement) || (event.shiftKey && document.activeElement === first)) {
        event.preventDefault();
        (event.shiftKey ? last : first).focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}

function onFocusIn(event) {
    if (!props.open || !dialog.value || dialog.value.contains(event.target)) return;
    firstField.value?.focus();
}

function bindFocus() {
    document.addEventListener('keydown', onKeydown);
    document.addEventListener('focusin', onFocusIn);
}

function releaseFocus() {
    document.removeEventListener('keydown', onKeydown);
    document.removeEventListener('focusin', onFocusIn);
}

function selectAll() {
    form.barangay_ids = props.barangays.map((barangay) => Number(barangay.id));
}

function clearBarangays() {
    form.barangay_ids = [];
}

async function save() {
    if (saving.value) return;
    if (!selectedIds.value.length) {
        fieldErrors.value = { barangay_ids: ['Select at least one barangay.'] };
        error.value = 'Select at least one barangay.';
        return;
    }
    const closing = editing.value && props.incident?.incident_status === 'active' && form.incident_status === 'closed';
    if (closing && !closePrompt.value) {
        closePrompt.value = true;
        return;
    }
    saving.value = true;
    error.value = '';
    fieldErrors.value = {};
    const payload = {
        incident_name: form.incident_name,
        disaster_type_id: Number(form.disaster_type_id),
        occurred_at: form.occurred_at,
        incident_status: form.incident_status,
        description: form.description,
        barangay_ids: selectedIds.value,
        confirm_close: closing,
    };
    try {
        if (editing.value) {
            await window.axios.patch(`/cdrrmo/api/incidents/${props.incident.id}`, payload);
        } else {
            await window.axios.post('/cdrrmo/api/incidents', payload);
        }
        releaseFocus();
        emit('saved', editing.value ? 'Incident updated.' : 'Incident created.');
    } catch (exception) {
        const response = exception.response;
        fieldErrors.value = response?.data?.errors || {};
        error.value = response?.data?.message || 'The incident could not be saved.';
        closePrompt.value = false;
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div v-if="open" class="incident-modal-backdrop">
        <form
            ref="dialog"
            class="incident-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="incident-form-title"
            @submit.prevent="save"
        >
            <header class="incident-modal-header">
                <MapIcon name="disaster" />
                <div>
                    <h3 id="incident-form-title">{{ title }}</h3>
                    <p>Enter the incident details and select the associated barangays.</p>
                </div>
                <button type="button" class="incident-icon-btn" aria-label="Close" @click="requestClose">
                    <MapIcon name="x" />
                </button>
            </header>

            <div class="incident-modal-body">
                <p v-if="error" class="cdrrmo-error" role="alert">{{ error }}</p>
                <p v-if="closePrompt" class="incident-close-note" role="status">
                    Closing this incident does not approve or complete its reports. Choose Save Changes again to confirm.
                </p>
                <div class="incident-form-grid">
                    <label>
                        Incident Name <span aria-hidden="true">*</span>
                        <input ref="firstField" v-model="form.incident_name" required maxlength="191" placeholder="Name of the disaster event" autocomplete="off">
                        <span v-if="fieldErrors.incident_name" class="cdrrmo-error">{{ fieldErrors.incident_name[0] }}</span>
                    </label>
                    <label>
                        Disaster Type <span aria-hidden="true">*</span>
                        <select v-model="form.disaster_type_id" required>
                            <option value="">Choose a disaster type</option>
                            <option v-for="type in types" :key="type.id" :value="String(type.id)">{{ type.name }}</option>
                        </select>
                        <span v-if="fieldErrors.disaster_type_id" class="cdrrmo-error">{{ fieldErrors.disaster_type_id[0] }}</span>
                    </label>
                    <label>
                        Date and Time of Occurrence <span aria-hidden="true">*</span>
                        <input v-model="form.occurred_at" type="datetime-local" required>
                        <span class="incident-help">Philippine time (Asia/Manila)</span>
                        <span v-if="fieldErrors.occurred_at" class="cdrrmo-error">{{ fieldErrors.occurred_at[0] }}</span>
                    </label>
                    <label>
                        Status <span aria-hidden="true">*</span>
                        <select v-model="form.incident_status" required>
                            <option value="active">Active</option>
                            <option value="closed">Closed</option>
                        </select>
                    </label>
                    <label v-if="editing" class="incident-span">
                        Incident reference
                        <input :value="incident.incident_reference" readonly>
                    </label>
                    <label class="incident-span">
                        Description
                        <textarea v-model="form.description" rows="4" maxlength="5000" placeholder="What happened, and which area needs attention?"></textarea>
                        <span v-if="fieldErrors.description" class="cdrrmo-error">{{ fieldErrors.description[0] }}</span>
                    </label>
                </div>

                <fieldset class="incident-barangay-set">
                    <legend>Associated barangays <span aria-hidden="true">*</span></legend>
                    <p class="incident-help">Selecting a barangay links it to this incident. It does not mark residents or buildings as affected.</p>
                    <div class="incident-barangay-tools">
                        <button type="button" @click="selectAll">Select all</button>
                        <button type="button" @click="clearBarangays">Clear selection</button>
                    </div>
                    <div class="incident-barangay-grid">
                        <label v-for="barangay in barangays" :key="barangay.id" class="incident-barangay" :class="{ 'is-selected': selectedIds.includes(Number(barangay.id)) }">
                            <input v-model="form.barangay_ids" type="checkbox" :value="Number(barangay.id)">
                            <MapIcon name="pin" />
                            <span>{{ barangay.name }}</span>
                        </label>
                    </div>
                    <span v-if="fieldErrors.barangay_ids" class="cdrrmo-error">{{ fieldErrors.barangay_ids[0] }}</span>
                </fieldset>
            </div>

            <footer class="incident-modal-footer">
                <button type="button" class="mapr-button mapr-button-secondary" :disabled="saving" @click="requestClose">Cancel</button>
                <button type="submit" class="mapr-button mapr-button-primary" :disabled="saving">
                    <MapIcon :name="editing ? 'pencil' : 'plus'" />
                    {{ saving ? 'Saving…' : (editing ? 'Save Changes' : 'Create Incident') }}
                </button>
            </footer>
        </form>
    </div>
</template>
