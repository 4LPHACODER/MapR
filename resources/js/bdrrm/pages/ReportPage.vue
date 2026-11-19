<script setup>
import { computed, inject, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { onBeforeRouteLeave, useRoute, useRouter } from 'vue-router';
import Button from 'primevue/button';
import Message from 'primevue/message';
import Select from 'primevue/select';
import Tag from 'primevue/tag';
import Textarea from 'primevue/textarea';
import { useConfirm } from 'primevue/useconfirm';
import ReviewSignDialog from '../../shared/components/ReviewSignDialog.vue';
import MapIcon from '../../shared/components/MapIcon.vue';
import BuildingPicker from '../components/BuildingPicker.vue';
import ReportWorkspace from '../components/ReportWorkspace.vue';

const apiBase = inject('apiBase');
const route = useRoute();
const router = useRouter();
const confirm = useConfirm();
const record = ref(null);
const error = ref('');
const message = ref('');
const saving = ref(false);
const leaving = ref(false);
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
const fieldErrors = reactive({
    disaster_incident_id: '',
    barangay_id: '',
    building_id: '',
    report_description: '',
});
const incidents = ref([]);
const barangays = ref([]);
const classes = ref([]);
const profile = ref(null);
const signOpen = ref(false);
const signMode = ref('verify');
const saveState = ref('Saved');
const savedSnapshot = ref('');
const createBaseline = ref('');
const editing = ref(false);
const menuOpen = ref(false);
const zoom = ref('100%');
const detailsOpen = ref(false);
const contextReady = ref(false);
const contextError = ref('');
const buildingChoice = ref(null);

const selectedIncident = computed(() => incidents.value.find((incident) => String(incident.id) === String(form.disaster_incident_id)));
const selectedBarangay = computed(() => barangays.value.find((barangay) => String(barangay.id) === String(form.barangay_id)));
const buildingSummary = computed(() => {
    if (!buildingChoice.value) return 'Not selected';
    const place = buildingChoice.value.address || locationLabel(buildingChoice.value.location_status);
    return `${buildingChoice.value.reference} · ${place}`;
});
const createDirty = computed(() => route.meta.kind === 'create' && createBaseline.value !== '' && createBaseline.value !== createSnapshot());
const draftStatus = computed(() => {
    if (saving.value) return 'Saving…';
    if (error.value) return 'Save failed';
    return createDirty.value ? 'Unsaved changes' : 'Not saved yet';
});
const backHref = computed(() => {
    const from = typeof route.query.from === 'string' ? route.query.from : '';
    if (from.startsWith('/reports/drafts') || from.startsWith('/reports/returned') || from.startsWith('/reports/submitted')) return from;
    if (record.value?.status === 'returned') return '/reports/returned';
    if (['review', 'signed', 'submitted', 'finalized'].includes(record.value?.status)) return '/reports/submitted';
    return '/reports/drafts';
});

function createSnapshot() {
    return JSON.stringify({
        disaster_incident_id: String(form.disaster_incident_id || ''),
        barangay_id: String(form.barangay_id || ''),
        building_id: String(form.building_id || ''),
        report_description: form.report_description || '',
    });
}

function markCreateBaseline() {
    createBaseline.value = createSnapshot();
}

function locationLabel(status) {
    if (status === 'verified') return 'Verified';
    if (status === 'rejected') return 'Rejected';
    return 'Unverified';
}

function clearFieldErrors() {
    fieldErrors.disaster_incident_id = '';
    fieldErrors.barangay_id = '';
    fieldErrors.building_id = '';
    fieldErrors.report_description = '';
}

function clearCreateError(field) {
    fieldErrors[field] = '';
    if (!Object.values(fieldErrors).some(Boolean)) error.value = '';
}

async function applyFieldErrors(errors, fallback = '') {
    clearFieldErrors();
    const source = errors || {};
    for (const key of Object.keys(fieldErrors)) {
        const value = source[key];
        fieldErrors[key] = Array.isArray(value) ? value[0] : (value || '');
    }
    if (!Object.values(fieldErrors).some(Boolean) && fallback) {
        if (fallback.toLowerCase().includes('building')) fieldErrors.building_id = fallback;
        else fieldErrors.disaster_incident_id = fallback;
    }
    const first = ['disaster_incident_id', 'barangay_id', 'building_id', 'report_description'].find((key) => fieldErrors[key]);
    await nextTick();
    if (first) document.getElementById(`report-${first}`)?.focus();
}

function unsaved() {
    if (saving.value || leaving.value) return false;
    if (route.meta.kind === 'create') return createDirty.value;
    return saveState.value === 'Unsaved changes';
}

async function loadContext() {
    contextError.value = '';
    try {
        const summary = await window.axios.get(`${apiBase}/summary`);
        barangays.value = summary.data.barangays || [];
        incidents.value = (await window.axios.get(`${apiBase}/incidents`)).data.data || [];
        profile.value = (await window.axios.get(`${apiBase}/profile`)).data;
        if (route.meta.kind === 'create' && barangays.value.length === 1 && !form.barangay_id) {
            form.barangay_id = barangays.value[0].id;
        }
        if (route.meta.kind === 'create') markCreateBaseline();
        contextReady.value = true;
    } catch (exception) {
        if (route.meta.kind !== 'create') throw exception;
        contextError.value = exception.response?.data?.message || 'Report options could not be loaded.';
    }
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
    if (route.query.panel === 'history') detailsOpen.value = true;
    if (route.query.saved === '1') {
        message.value = 'Draft saved. Photographs, the checklist, signing, and forwarding are available on this report. Saving a draft does not send it for review.';
    }
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
    if (saving.value || !contextReady.value || !barangays.value.length) return;
    saving.value = true;
    error.value = '';
    clearFieldErrors();
    try {
        const payload = {
            disaster_incident_id: form.disaster_incident_id,
            barangay_id: form.barangay_id,
            report_description: form.report_description,
        };
        if (form.building_id) payload.building_id = form.building_id;
        const { data } = await window.axios.post(`${apiBase}/reports`, payload);
        leaving.value = true;
        await router.push({ path: `/reports/${data.data.id}`, query: { saved: '1' } });
        leaving.value = false;
        await loadRecord();
    } catch (exception) {
        const errors = exception.response?.data?.errors;
        const fallback = exception.response?.data?.message || 'The draft was not saved.';
        const validationFailed = exception.response?.status === 422;
        await applyFieldErrors(errors, validationFailed && !errors ? fallback : '');
        error.value = Object.values(fieldErrors).some(Boolean) ? 'Check the highlighted fields and save the draft again.' : fallback;
    } finally {
        saving.value = false;
    }
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
    if (!savedSnapshot.value || route.meta.kind === 'create') return;
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
    if (!unsaved()) return;
    event.preventDefault();
    event.returnValue = '';
}

onBeforeRouteLeave(() => {
    if (!unsaved()) return true;
    if (route.meta.kind !== 'create') return window.confirm('Leave this page? Unsaved changes will be lost.');
    return new Promise((resolve) => confirm.require({
        header: 'Leave this report?',
        message: 'Your draft has unsaved changes.',
        acceptLabel: 'Leave report',
        rejectLabel: 'Keep editing',
        defaultFocus: 'reject',
        acceptProps: { severity: 'danger', size: 'small' },
        rejectProps: { severity: 'secondary', outlined: true, size: 'small' },
        accept: () => resolve(true),
        reject: () => resolve(false),
        onHide: () => resolve(false),
    }));
});

onMounted(async () => {
    window.addEventListener('beforeunload', onLeave);
    try {
        await loadContext();
        await loadRecord();
    } catch (exception) {
        error.value = exception.response?.data?.message || 'This record could not be opened.';
    }
});

onBeforeUnmount(() => {
    window.removeEventListener('beforeunload', onLeave);
});
</script>

<template>
    <section class="field-report bdrrm-reports">
        <p v-if="error && route.meta.kind !== 'create'" class="cdrrmo-error" role="alert">{{ error }}</p>
        <p v-if="message" class="report-note" role="status">{{ message }}</p>

        <form v-if="route.meta.kind === 'create'" class="report-create grid gap-4" novalidate @submit.prevent="createReport">
            <header class="grid gap-1">
                <h2 class="text-2xl leading-8 font-semibold tracking-tight">Create Barangay Report</h2>
                <p class="report-note">Record the incident details and save them as a draft.</p>
            </header>
            <Message v-if="contextError" severity="error" size="small">
                {{ contextError }}
                <Button type="button" label="Retry" size="small" severity="secondary" text @click="loadContext" />
            </Message>
            <div class="report-create-grid grid items-start gap-4">
                <section class="report-create-panel min-w-0" aria-labelledby="report-create-details">
                    <div class="report-create-panel-heading">
                        <h3 id="report-create-details" class="text-[15px] font-semibold">Report details</h3>
                    </div>
                    <div class="report-create-panel-body grid gap-4">
                        <div class="report-create-context grid gap-4">
                            <div class="grid min-w-0 content-start gap-1.5">
                                <span id="report-incident-label" class="font-semibold">Incident <span class="report-create-required">Required</span></span>
                                <Select
                                    v-model="form.disaster_incident_id"
                                    input-id="report-disaster_incident_id"
                                    aria-labelledby="report-incident-label"
                                    :options="incidents"
                                    option-label="incident_name"
                                    option-value="id"
                                    placeholder="Select an incident"
                                    empty-message="No incidents available"
                                    append-to="self"
                                    panel-class="report-create-overlay"
                                    size="small"
                                    fluid
                                    show-clear
                                    :loading="!contextReady && !contextError"
                                    :disabled="!contextReady"
                                    :invalid="Boolean(fieldErrors.disaster_incident_id)"
                                    :pt="{ label: { 'aria-required': 'true', 'aria-describedby': fieldErrors.disaster_incident_id ? 'report-incident-error' : undefined } }"
                                    @update:model-value="clearCreateError('disaster_incident_id')"
                                />
                                <small v-if="fieldErrors.disaster_incident_id" id="report-incident-error" class="report-field-error">{{ fieldErrors.disaster_incident_id }}</small>
                                <small v-else-if="contextReady && !incidents.length" class="report-note">No incidents are available for this account.</small>
                            </div>
                            <div class="grid min-w-0 content-start gap-1.5">
                                <template v-if="barangays.length > 1">
                                    <span id="report-barangay-label" class="font-semibold">Barangay <span class="report-create-required">Required</span></span>
                                    <Select
                                        v-model="form.barangay_id"
                                        input-id="report-barangay_id"
                                        aria-labelledby="report-barangay-label"
                                        :options="barangays"
                                        option-label="name"
                                        option-value="id"
                                        placeholder="Select a barangay"
                                        append-to="self"
                                        panel-class="report-create-overlay"
                                        size="small"
                                        fluid
                                        show-clear
                                        :disabled="!contextReady"
                                        :invalid="Boolean(fieldErrors.barangay_id)"
                                        :pt="{ label: { 'aria-required': 'true', 'aria-describedby': fieldErrors.barangay_id ? 'report-barangay-error' : undefined } }"
                                        @update:model-value="clearCreateError('barangay_id')"
                                    />
                                </template>
                                <dl v-else class="grid gap-1.5">
                                    <dt class="font-semibold">Assigned barangay</dt>
                                    <dd id="report-barangay_id" tabindex="-1" :aria-describedby="fieldErrors.barangay_id ? 'report-barangay-error' : undefined" class="report-create-assignment flex min-w-0 items-center gap-2">
                                        <MapIcon name="pin" class="size-3.5 shrink-0" />
                                        <span>{{ selectedBarangay?.name || (contextReady ? 'No barangay assigned' : (contextError ? 'Unavailable' : 'Loading…')) }}</span>
                                    </dd>
                                </dl>
                                <small v-if="fieldErrors.barangay_id" id="report-barangay-error" class="report-field-error">{{ fieldErrors.barangay_id }}</small>
                            </div>
                        </div>
                        <BuildingPicker
                            v-model="form.building_id"
                            :barangay-id="form.barangay_id"
                            :invalid="Boolean(fieldErrors.building_id)"
                            :error="fieldErrors.building_id"
                            @update:model-value="clearCreateError('building_id')"
                            @selected="buildingChoice = $event"
                        />
                        <div class="grid gap-1.5">
                            <label for="report-report_description" class="font-semibold">Description <span class="report-create-optional">Optional for draft</span></label>
                            <Textarea
                                id="report-report_description"
                                v-model="form.report_description"
                                maxlength="5000"
                                rows="4"
                                size="small"
                                fluid
                                auto-resize
                                class="resize-none"
                                placeholder="Describe what happened and what you observed."
                                :disabled="!contextReady"
                                :invalid="Boolean(fieldErrors.report_description)"
                                :aria-describedby="fieldErrors.report_description ? 'report-description-error report-description-length' : 'report-description-length'"
                                @update:model-value="clearCreateError('report_description')"
                            />
                            <div class="flex flex-wrap items-start justify-between gap-x-3">
                                <small v-if="fieldErrors.report_description" id="report-description-error" class="report-field-error">{{ fieldErrors.report_description }}</small>
                                <small id="report-description-length" class="report-note ml-auto tabular-nums">{{ form.report_description.length }} / 5,000<span class="visually-hidden"> characters</span></small>
                            </div>
                        </div>
                        <Message v-if="error" severity="error" size="small">{{ error }}</Message>
                    </div>
                    <footer class="report-create-actions flex flex-wrap gap-2">
                        <Button type="submit" label="Save Draft" size="small" :loading="saving" :disabled="saving || !contextReady || !barangays.length">
                            <template #icon><MapIcon name="save" class="size-3.5" /></template>
                        </Button>
                        <Button as="router-link" to="/reports/drafts" label="Back to Drafts" size="small" severity="secondary" outlined />
                    </footer>
                </section>
                <aside class="report-create-panel min-w-0" aria-labelledby="report-create-summary">
                    <div class="report-create-panel-heading flex flex-wrap items-center justify-between gap-2">
                        <h3 id="report-create-summary" class="text-[15px] font-semibold">Draft summary</h3>
                        <span role="status"><Tag :value="draftStatus" :severity="error ? 'danger' : (saving || createDirty ? 'info' : 'secondary')" /></span>
                    </div>
                    <div class="report-create-panel-body grid gap-4">
                        <dl class="report-create-summary grid">
                            <div><dt>Incident</dt><dd>{{ selectedIncident?.incident_name || 'Not selected' }}</dd></div>
                            <div><dt>Barangay</dt><dd>{{ selectedBarangay?.name || 'Not selected' }}</dd></div>
                            <div><dt>Building</dt><dd>{{ buildingSummary }}</dd></div>
                        </dl>
                        <p class="report-note">Save Draft keeps the report in Draft Reports. You can forward it later when it’s ready for review.</p>
                        <div class="report-create-guidance grid gap-1">
                            <h4 class="font-semibold">Before forwarding for review</h4>
                            <p>Add a description, link one building in this barangay, attach a photograph, and obtain the barangay captain’s signature.</p>
                        </div>
                    </div>
                </aside>
            </div>
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
            :back-to="backHref"
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
