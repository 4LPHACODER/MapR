<script setup>
import { inject, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import ReviewSignDialog from '../../shared/components/ReviewSignDialog.vue';
import ReportWorkspace from '../components/ReportWorkspace.vue';

const apiBase = inject('apiBase');
const route = useRoute();
const router = useRouter();
const record = ref(null);
const error = ref('');
const message = ref('');
const saving = ref(false);
const form = reactive({
    disaster_incident_id: '',
    barangay_id: '',
    building_id: '',
    report_description: '',
    observations: '',
    findings: '',
    return_reason: '',
    location_verified: false,
    household_link_verified: false,
    verified_damage_class_id: '',
    verification_notes: '',
});
const incidents = ref([]);
const barangays = ref([]);
const classes = ref([]);
const profile = ref(null);
const signOpen = ref(false);
const signMode = ref('verify');
const saveState = ref('Saved');
const savedSnapshot = ref('');
const editing = ref(false);
const menuOpen = ref(false);
const zoom = ref('100%');
const detailsOpen = ref(false);

async function loadContext() {
    const summary = await window.axios.get(`${apiBase}/summary`);
    barangays.value = summary.data.barangays || [];
    incidents.value = (await window.axios.get(`${apiBase}/incidents`)).data.data || [];
    profile.value = (await window.axios.get(`${apiBase}/profile`)).data;
}

async function loadRecord() {
    if (route.meta.kind === 'create') {
        record.value = null;
        return;
    }
    const path = route.meta.kind === 'assessment'
        ? `${apiBase}/assessments/${route.params.id}`
        : `${apiBase}/reports/${route.params.id}`;
    const { data } = await window.axios.get(path);
    record.value = data.data;
    form.report_description = data.data.report_description || '';
    form.findings = data.data.findings || data.data.report_description || '';
    form.building_id = data.data.building?.id || data.data.buildings?.[0]?.building_id || '';
    savedSnapshot.value = JSON.stringify({ findings: form.findings, description: form.report_description, building_id: form.building_id });
    saveState.value = 'Saved';
    editing.value = false;
}

async function keep(action) {
    saving.value = true;
    saveState.value = 'Saving';
    error.value = '';
    message.value = '';
    try {
        await action();
        message.value = 'Saved.';
        saveState.value = 'Saved';
        if (route.meta.kind !== 'create') await loadRecord();
    } catch (exception) {
        saveState.value = 'Save failed';
        const errors = exception.response?.data?.errors;
        error.value = errors ? Object.values(errors).flat().join(' ') : (exception.response?.data?.message || 'The change was not saved.');
    } finally {
        saving.value = false;
    }
}

function openSign(mode) {
    signMode.value = mode;
    signOpen.value = true;
}

async function confirmSign(payload) {
    const mode = signMode.value;
    signOpen.value = false;
    if (mode === 'barangay') {
        await keep(() => window.axios.post(`${apiBase}/reports/${record.value.id}/sign`, payload));
        return;
    }
    if (mode === 'approve') {
        await keep(() => window.axios.post(`${apiBase}/assessments/${record.value.id}/approve`, payload));
        return;
    }
    await keep(() => window.axios.post(`${apiBase}/assessments/${record.value.id}/verify`, payload));
}

async function createReport() {
    await keep(async () => {
        const { data } = await window.axios.post(`${apiBase}/reports`, { ...form });
        router.push(`/reports/${data.data.id}`);
    });
}

async function saveDraft() {
    await keep(() => window.axios.patch(`${apiBase}/reports/${record.value.id}`, {
        report_description: form.report_description,
        building_id: form.building_id || undefined,
        observations: form.observations,
        updated_at: record.value.updated_at,
    }));
}

async function uploadPhoto(event) {
    const file = event.target.files?.[0];
    if (!file || !record.value) return;
    const body = new FormData();
    body.append('photo', file);
    if (form.building_id) body.append('building_id', form.building_id);
    const reportId = record.value.barangay_report?.id || record.value.id;
    await keep(() => window.axios.post(`${apiBase}/reports/${reportId}/photos`, body));
    event.target.value = '';
}

function snapshotNow() {
    return JSON.stringify({ findings: form.findings, description: form.report_description, building_id: form.building_id });
}

watch(() => [form.findings, form.report_description, form.building_id], () => {
    if (!savedSnapshot.value) return;
    saveState.value = snapshotNow() === savedSnapshot.value ? 'Saved' : 'Unsaved changes';
});

function flagSubject() {
    return { subject_type: 'barangay_report', subject_id: record.value?.id };
}

async function addFlag(payload) {
    error.value = '';
    try {
        await window.axios.post(`${apiBase}/flags`, { ...flagSubject(), ...payload });
        await loadRecord();
        message.value = 'Flag recorded.';
    } catch (exception) {
        error.value = exception.response?.data?.message || 'The flag could not be saved.';
    }
}

async function resolveFlag(payload) {
    if (!payload?.id) return;
    error.value = '';
    try {
        await window.axios.post(`${apiBase}/flags/${payload.id}/resolve`, { resolution: payload.resolution });
        await loadRecord();
        message.value = 'Flag resolved.';
    } catch (exception) {
        error.value = exception.response?.data?.message || 'The flag could not be resolved.';
    }
}

function onLeave(event) {
    if (saveState.value === 'Unsaved changes') {
        event.preventDefault();
        event.returnValue = '';
    }
}

onMounted(async () => {
    window.addEventListener('beforeunload', onLeave);
    try {
        await loadContext();
        await loadRecord();
    } catch (exception) {
        error.value = exception.response?.data?.message || 'This record could not be opened.';
    }
});

onBeforeUnmount(() => window.removeEventListener('beforeunload', onLeave));
</script>

<template>
    <section class="field-report">
        <p v-if="record?.return_reason" class="cdrrmo-error">Returned for correction: {{ record.return_reason }}</p>
        <p v-if="error" class="cdrrmo-error" role="alert">{{ error }}</p>
        <p v-if="message" class="cdrrmo-muted">{{ message }}</p>

        <form v-if="route.meta.kind === 'create'" @submit.prevent="createReport">
            <label>Incident
                <select v-model="form.disaster_incident_id" required>
                    <option value="">Choose an incident</option>
                    <option v-for="incident in incidents" :key="incident.id" :value="incident.id">{{ incident.incident_name }}</option>
                </select>
            </label>
            <label>Barangay
                <select v-model="form.barangay_id" required>
                    <option value="">Choose a barangay</option>
                    <option v-for="barangay in barangays" :key="barangay.id" :value="barangay.id">{{ barangay.name }}</option>
                </select>
            </label>
            <label>Building reference id
                <input v-model="form.building_id" inputmode="numeric">
            </label>
            <label>Description
                <textarea v-model="form.report_description" rows="4"></textarea>
            </label>
            <button class="mapr-button mapr-button-primary" type="submit" :disabled="saving">Save draft</button>
        </form>

        <ReportWorkspace
            v-else-if="record"
            :record="record"
            :form="form"
            :profile="profile"
            :classes="classes"
            :api-base="apiBase"
            :saving="saving"
            :save-state="saveState"
            :editing="editing"
            :zoom="zoom"
            :details-open="detailsOpen"
            :menu-open="menuOpen"
            @update:editing="editing = $event"
            @update:zoom="zoom = $event"
            @update:details-open="detailsOpen = $event"
            @update:menu-open="menuOpen = $event"
            @save="saveDraft()"
            @sign="openSign('barangay')"
            @submit="keep(() => window.axios.post(`${apiBase}/reports/${record.id}/submit`, { updated_at: record.updated_at }))"
            @upload="uploadPhoto"
            @flag="addFlag"
            @resolve-flag="resolveFlag"
        />
        <ReviewSignDialog
            :open="signOpen"
            :reference="record?.reference || ''"
            :name="profile?.name || ''"
            :position="profile?.job_title || profile?.role || ''"
            :capacity="signMode === 'approve' ? 'approved_by' : (signMode === 'barangay' ? 'barangay_signer' : 'verified_by')"
            @close="signOpen = false"
            @confirm="confirmSign"
        />
    </section>
</template>
