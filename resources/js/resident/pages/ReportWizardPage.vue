<script setup>
import { computed, inject, nextTick, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import Button from 'primevue/button';
import Message from 'primevue/message';
import ProgressSpinner from 'primevue/progressspinner';
import Stepper from 'primevue/stepper';
import StepList from 'primevue/steplist';
import Step from 'primevue/step';
import StepPanels from 'primevue/steppanels';
import StepPanel from 'primevue/steppanel';
import Tag from 'primevue/tag';
import { onBeforeRouteLeave, useRoute, useRouter } from 'vue-router';
import { useConfirm } from 'primevue/useconfirm';
import MapIcon from '../../shared/components/MapIcon.vue';
import PhotoStep from '../components/PhotoStep.vue';
import DetailsStep from '../components/DetailsStep.vue';
import ReviewStep from '../components/ReviewStep.vue';

const apiBase = inject('apiBase');
const route = useRoute();
const router = useRouter();
const confirm = useConfirm();
const savedPayload = ref('');
const step = ref(1);
const profile = ref({});
const incidents = ref([]);
const photos = ref([]);
const pending = ref([]);
const progress = ref({});
const error = ref('');
const fieldErrors = reactive({ incident: '', location: '', description: '', pin: '' });
const success = ref(null);
const saving = ref(false);
const photoLeaveAttempt = ref(false);
const signatureReady = ref(false);
const signatureBusy = ref(false);
const recordedSignature = ref(false);
const reportId = ref(route.params.id || null);
const reportStatus = ref('');
const returnReason = ref('');
const detailsRef = ref(null);
const loading = ref(true);
const loadError = ref('');
const savedMessage = ref('');
let inputMethod = 'keyboard';
let stepAnimation = null;
const steps = [
    { value: 1, label: 'Details', hint: 'Incident and location' },
    { value: 2, label: 'Photos', hint: 'Evidence of damage' },
    { value: 3, label: 'Review', hint: 'Check and sign' },
];
const loadController = new AbortController();
const form = reactive({
    disaster_incident_id: '',
    incident_text: '',
    reported_incident_type: '',
    incident_description: '',
    reported_location: '',
    observed_at: '',
    observed_at_approximate: false,
    latitude: null,
    longitude: null,
    confirm_affected_building: false,
    affected_building_id: '',
    confirmed: false,
});

const selectedIncident = computed(
    () => incidents.value.find((item) => String(item.id) === String(form.disaster_incident_id)) || null,
);
watch(selectedIncident, (incident) => {
    if (incident && !form.incident_text.trim()) form.incident_text = incident.name;
});
const incidentName = computed(() => form.incident_text.trim() || selectedIncident.value?.name || '');
const storageKey = computed(() => (reportId.value ? `mapr-resident-draft-${reportId.value}` : ''));
const uploading = computed(() => pending.value.some((item) => item.uploading));
const detailsReady = computed(
    () =>
        form.incident_text.trim() !== '' &&
        form.reported_location.trim() !== '' &&
        form.incident_description.trim() !== '' &&
        form.latitude != null &&
        form.longitude != null,
);
const reviewBlockReason = computed(() => {
    if (uploading.value) return 'Wait until the photos finish uploading.';
    if (pending.value.some((item) => item.error)) return 'Remove or retry the photo that failed before continuing.';
    if (photos.value.length === 0) return 'Add at least 2 clear photos of the affected building.';
    if (photos.value.length === 1) return 'Add 1 more photo to continue.';
    return '';
});
const photoError = computed(() => (photoLeaveAttempt.value ? reviewBlockReason.value : ''));
const dirty = computed(
    () =>
        !loading.value &&
        !loadError.value &&
        !success.value &&
        (JSON.stringify(payload()) !== savedPayload.value || pending.value.length > 0),
);
const canSubmit = computed(
    () =>
        detailsReady.value &&
        photos.value.length >= 2 &&
        !reviewBlockReason.value &&
        form.confirmed &&
        signatureReady.value &&
        !signatureBusy.value &&
        !saving.value,
);

function remember() {
    if (!storageKey.value) return;
    try {
        sessionStorage.setItem(
            storageKey.value,
            JSON.stringify({
                disaster_incident_id: form.disaster_incident_id,
                incident_text: form.incident_text,
                reported_incident_type: form.reported_incident_type,
                incident_description: form.incident_description,
                reported_location: form.reported_location,
                observed_at: form.observed_at,
                observed_at_approximate: form.observed_at_approximate,
                latitude: form.latitude,
                longitude: form.longitude,
            }),
        );
    } catch {
        /* The server draft remains available if browser storage is blocked. */
    }
}

function restore() {
    if (!storageKey.value) return;
    try {
        const saved = sessionStorage.getItem(storageKey.value);
        if (saved) Object.assign(form, JSON.parse(saved));
    } catch {
        /* Ignore unavailable or outdated browser drafts. */
    }
}

function toLocalInput(value) {
    if (!value) return '';
    return String(value).replace(' ', 'T').slice(0, 16);
}

function payload() {
    return {
        disaster_incident_id: form.disaster_incident_id || null,
        reported_incident_type: form.disaster_incident_id
            ? null
            : form.reported_incident_type.trim() || form.incident_text.trim() || null,
        incident_description: form.incident_description,
        reported_location: form.reported_location,
        observed_at: form.observed_at ? form.observed_at : null,
        observed_at_approximate: Boolean(form.observed_at) && form.observed_at_approximate,
        latitude: form.latitude,
        longitude: form.longitude,
        confirm_affected_building: Boolean(form.confirm_affected_building),
        affected_building_id:
            form.confirm_affected_building && form.affected_building_id ? Number(form.affected_building_id) : null,
    };
}

function applyReport(report) {
    reportId.value = report.id;
    reportStatus.value = report.status || '';
    returnReason.value = report.return_reason || '';
    form.disaster_incident_id = report.disaster_incident_id || '';
    form.reported_incident_type = report.reported_incident_type || '';
    form.incident_text =
        report.reported_incident_type ||
        incidents.value.find((item) => String(item.id) === String(report.disaster_incident_id))?.name ||
        form.incident_text;
    form.incident_description = report.incident_description || '';
    form.reported_location = report.reported_location || '';
    form.observed_at = toLocalInput(report.observed_at);
    form.observed_at_approximate = Boolean(report.observed_at_approximate);
    form.latitude = report.latitude;
    form.longitude = report.longitude;
    form.confirm_affected_building = Boolean(report.confirm_affected_building || report.building_id);
    form.affected_building_id = report.building_id ? String(report.building_id) : '';
    recordedSignature.value = Boolean(report.signature);
    photos.value = report.photos || [];
    savedPayload.value = JSON.stringify(payload());
    restore();
}

function clearFieldErrors() {
    fieldErrors.incident = '';
    fieldErrors.location = '';
    fieldErrors.description = '';
    fieldErrors.pin = '';
}

function validateDetails() {
    clearFieldErrors();
    if (!form.incident_text.trim()) fieldErrors.incident = 'Choose or type the incident.';
    if (!form.reported_location.trim()) fieldErrors.location = 'Describe the location.';
    if (!form.incident_description.trim()) fieldErrors.description = 'Describe what happened.';
    if (form.latitude == null || form.longitude == null) fieldErrors.pin = 'Confirm the incident location on the map.';
    return ['incident', 'location', 'description', 'pin'].find((name) => fieldErrors[name]) || '';
}

function fingerprint(file) {
    return `${file.name}:${file.size}:${file.lastModified}`;
}

function acceptedFile(file) {
    const allowed = ['image/jpeg', 'image/png', 'image/webp'];
    const extension = file.name.split('.').pop()?.toLowerCase();
    const typeOk = allowed.includes(file.type) || ['jpg', 'jpeg', 'png', 'webp'].includes(extension);
    if (!typeOk || file.size < 1 || file.size > 10 * 1024 * 1024) {
        return 'Use a JPEG, PNG, or WebP photo up to 10 MB.';
    }
    return '';
}

async function ensureDraft() {
    if (!form.incident_text.trim()) {
        throw new Error('Choose or type the incident before saving.');
    }
    const snapshot = JSON.stringify(payload());
    if (!reportId.value) {
        const created = await window.axios.post(`${apiBase}/reports`, payload());
        const currentForm = { ...form };
        applyReport(created.data.data);
        Object.assign(form, currentForm);
        await router.replace(`/report/${reportId.value}`);
    } else {
        const updated = await window.axios.patch(`${apiBase}/reports/${reportId.value}`, payload());
        photos.value = updated.data.data.photos || photos.value;
    }
    savedPayload.value = snapshot;
    remember();
}

async function uploadOne(item) {
    const problem = acceptedFile(item.file);
    if (problem) {
        item.error = problem;
        return;
    }
    item.uploading = true;
    item.error = '';
    const body = new FormData();
    body.append('photo', item.file);
    body.append('source', item.source);
    try {
        const response = await window.axios.post(`${apiBase}/reports/${reportId.value}/photos`, body, {
            onUploadProgress(event) {
                progress.value = {
                    ...progress.value,
                    [item.key]: event.total ? Math.round((event.loaded / event.total) * 100) : 0,
                };
            },
        });
        photos.value.push({
            id: response.data.data.id,
            name: response.data.data.original_filename,
            file_size: response.data.data.file_size,
        });
        URL.revokeObjectURL(item.preview);
        pending.value = pending.value.filter((entry) => entry.key !== item.key);
    } catch (exception) {
        item.uploading = false;
        item.error = exception.response?.data?.message || 'Upload failed. Remove the photo or try again.';
    }
}

async function uploadPending() {
    const queue = pending.value.filter((item) => !item.uploading);
    for (const item of queue) {
        await uploadOne(item);
    }
    const failed = pending.value.find((item) => item.error);
    if (failed) throw new Error(failed.error);
}

async function addFiles(event, source) {
    if (saving.value || uploading.value) return;
    const files = [...(event.target.files || [])];
    if ('value' in event.target) event.target.value = '';
    error.value = '';
    if (!files.length) return;
    const room = 8 - photos.value.length - pending.value.length;
    if (files.length > room) error.value = 'A report can include up to 8 photographs.';
    const known = new Set([
        ...photos.value.map((photo) => `${photo.name}:${photo.file_size ?? ''}`),
        ...pending.value.map((item) => item.fingerprint),
    ]);
    const accepted = [];
    files.slice(0, Math.max(room, 0)).forEach((file) => {
        const print = fingerprint(file);
        const savedKey = `${file.name}:${file.size}`;
        if (known.has(print) || known.has(savedKey) || known.has(`${file.name}:`)) return;
        known.add(print);
        const problem = acceptedFile(file);
        const item = {
            key: print,
            fingerprint: print,
            file,
            source,
            preview: URL.createObjectURL(file),
            error: problem,
            uploading: false,
        };
        pending.value.push(item);
        if (!problem) accepted.push(pending.value[pending.value.length - 1]);
    });
    if (!accepted.length) return;
    saving.value = true;
    try {
        await ensureDraft();
    } catch (exception) {
        error.value = exception.response?.data?.message || exception.message || 'The draft could not be saved.';
        accepted.forEach((item) => {
            item.error = error.value;
        });
        return;
    } finally {
        saving.value = false;
    }
    for (const item of accepted) {
        await uploadOne(item);
    }
}

async function retryPending(key) {
    const item = pending.value.find((entry) => entry.key === key);
    if (!item || item.uploading || saving.value || uploading.value) return;
    error.value = '';
    try {
        await ensureDraft();
        await uploadOne(item);
    } catch (exception) {
        error.value = exception.response?.data?.message || exception.message || 'The photo could not be uploaded.';
    }
}

async function removeServer(photo) {
    if (saving.value || uploading.value) return;
    error.value = '';
    saving.value = true;
    try {
        await window.axios.delete(`${apiBase}/reports/${reportId.value}/photos/${photo.id}`);
        photos.value = photos.value.filter((item) => item.id !== photo.id);
    } catch (exception) {
        error.value = exception.response?.data?.message || 'The photo could not be removed. Try again.';
    } finally {
        saving.value = false;
    }
}

function removePending(index) {
    if (pending.value[index]?.uploading) return;
    URL.revokeObjectURL(pending.value[index].preview);
    pending.value.splice(index, 1);
}

function confirmPin(point) {
    form.latitude = point.latitude;
    form.longitude = point.longitude;
    fieldErrors.pin = '';
    remember();
}

function clearPin() {
    form.latitude = null;
    form.longitude = null;
    remember();
}

function onStepKeydown(event) {
    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
    const tabs = [...event.currentTarget.querySelectorAll('[role="tab"]:not(:disabled)')];
    if (!tabs.length) return;
    const current = tabs.indexOf(document.activeElement);
    const index =
        event.key === 'Home'
            ? 0
            : event.key === 'End'
              ? tabs.length - 1
              : (current + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
    event.preventDefault();
    tabs[index].focus();
}

function showStep(next) {
    if (saving.value || uploading.value || next > step.value) return;
    step.value = next;
}

async function saveDraft() {
    if (saving.value || uploading.value) return;
    error.value = '';
    savedMessage.value = '';
    saving.value = true;
    try {
        await ensureDraft();
        await uploadPending();
        savedMessage.value = 'Draft saved. You can return to it from My Reports.';
    } catch (exception) {
        error.value = exception.response?.data?.message || exception.message || 'The draft could not be saved.';
    } finally {
        saving.value = false;
    }
}

async function continueStep() {
    if (saving.value || uploading.value) return;
    error.value = '';
    if (step.value === 1) {
        const invalid = validateDetails();
        if (invalid) {
            await nextTick();
            detailsRef.value?.focusField(invalid);
            return;
        }
        saving.value = true;
        try {
            await ensureDraft();
            step.value = 2;
        } catch (exception) {
            error.value = exception.response?.data?.message || exception.message || 'The draft could not be saved.';
        } finally {
            saving.value = false;
        }
        return;
    }
    if (reviewBlockReason.value) {
        photoLeaveAttempt.value = true;
        return;
    }
    photoLeaveAttempt.value = false;
    step.value = 3;
}

async function submitReport() {
    if (!canSubmit.value) return;
    error.value = '';
    const invalid = validateDetails();
    if (invalid) {
        step.value = 1;
        await nextTick();
        detailsRef.value?.focusField(invalid);
        return;
    }
    saving.value = true;
    try {
        await ensureDraft();
        await uploadPending();
        if (photos.value.length < 2) {
            error.value = 'Please attach at least 2 photos before submitting your report.';
            step.value = 2;
            return;
        }
        const response = await window.axios.post(`${apiBase}/reports/${reportId.value}/submit`, { confirmed: true });
        try {
            if (storageKey.value) sessionStorage.removeItem(storageKey.value);
        } catch {
            /* Submission is already saved on the server. */
        }
        success.value = response.data.data;
    } catch (exception) {
        const body = exception.response?.data;
        if (exception.response?.status === 409) {
            error.value = `${body?.message || 'This report was already submitted.'} Reference ${body?.reference || ''}.`;
        } else {
            error.value =
                body?.message || exception.message || 'The report could not be submitted. The draft is still saved.';
            if (String(body?.message || '').includes('at least 2 photos')) step.value = 2;
        }
    } finally {
        saving.value = false;
    }
}

async function load() {
    loading.value = true;
    loadError.value = '';
    try {
        const options = { signal: loadController.signal };
        const [profileResponse, incidentResponse] = await Promise.all([
            window.axios.get(`${apiBase}/profile`, options),
            window.axios.get(`${apiBase}/incidents`, options),
        ]);
        profile.value = profileResponse.data.data;
        incidents.value = incidentResponse.data.data || [];
        if (!route.params.id && !form.reported_location.trim() && profile.value.suggested_location) {
            form.reported_location = profile.value.suggested_location;
        }
        const linked = profile.value.linked_buildings || [];
        if (!route.params.id && linked.length === 1) {
            form.affected_building_id = String(linked[0].building_id);
            form.confirm_affected_building = true;
        }
        savedPayload.value = JSON.stringify(payload());
        if (route.params.id) {
            const report = (await window.axios.get(`${apiBase}/reports/${route.params.id}`, options)).data.data;
            if (!report.editable) {
                await router.replace(`/reports/${report.id}`);
                return;
            }
            applyReport(report);
        }
    } catch (exception) {
        if (!loadController.signal.aborted)
            loadError.value = exception.response?.data?.message || 'Your report could not be loaded. Try again.';
    } finally {
        if (!loadController.signal.aborted) loading.value = false;
    }
}

function beforeUnload(event) {
    if (!dirty.value && !saving.value && !uploading.value) return;
    event.preventDefault();
    event.returnValue = '';
}

onBeforeRouteLeave(() => {
    if (saving.value || uploading.value || signatureBusy.value) {
        error.value = 'Wait until saving and uploads finish before leaving this report.';
        return false;
    }
    if (!dirty.value) return true;
    return new Promise((resolve) =>
        confirm.require({
            header: 'Leave this report?',
            message: 'Your latest changes have not been saved. Save a draft before leaving to keep them.',
            acceptLabel: 'Leave report',
            rejectLabel: 'Keep editing',
            defaultFocus: 'reject',
            acceptProps: { severity: 'danger', size: 'small' },
            rejectProps: { severity: 'secondary', outlined: true, size: 'small' },
            accept: () => resolve(true),
            reject: () => resolve(false),
            onHide: () => resolve(false),
        }),
    );
});

onMounted(() => {
    document.title = 'Report an incident | MapR';
    load();
    window.addEventListener('beforeunload', beforeUnload);
});
onUnmounted(() => {
    stepAnimation?.cancel();
    loadController.abort();
    window.removeEventListener('beforeunload', beforeUnload);
    pending.value.forEach((item) => URL.revokeObjectURL(item.preview));
});

watch(
    form,
    () => {
        if (form.incident_text.trim()) fieldErrors.incident = '';
        if (form.reported_location.trim()) fieldErrors.location = '';
        if (form.incident_description.trim()) fieldErrors.description = '';
        savedMessage.value = '';
        remember();
    },
    { deep: true },
);
watch(reviewBlockReason, (reason) => {
    if (!reason) photoLeaveAttempt.value = false;
});
watch(step, async () => {
    if (step.value !== 2) photoLeaveAttempt.value = false;
    await nextTick();
    const heading = document.querySelector(
        '.resident-wizard .p-steppanel:not([style*="display: none"]) .resident-step-heading',
    );
    const anchor = document.querySelector('.resident-wizard .resident-wizard-steps');
    heading?.focus({ preventScroll: true });
    anchor?.scrollIntoView({ block: 'start' });
    stepAnimation?.cancel();
    if (inputMethod === 'keyboard') return;
    const panel = heading?.closest('.p-steppanel');
    if (!panel) return;
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const tokens = getComputedStyle(panel.closest('.resident-report'));
    const duration = parseFloat(tokens.getPropertyValue(reduced ? '--report-motion-reduced' : '--report-motion-step'));
    const easing = tokens.getPropertyValue('--resident-sidebar-ease').trim();
    stepAnimation = panel.animate(
        reduced
            ? [{ opacity: 0.75 }, { opacity: 1 }]
            : [
                  { opacity: 0.75, transform: 'translateY(6px)' },
                  { opacity: 1, transform: 'translateY(0)' },
              ],
        { duration, easing },
    );
});
</script>

<template>
    <section
        class="resident-wizard resident-report"
        aria-labelledby="resident-report-title"
        @pointerdown.capture="inputMethod = 'pointer'"
        @keydown.capture="inputMethod = 'keyboard'"
    >
        <header class="report-page-header">
            <div>
                <h2 id="resident-report-title">
                    {{
                        success
                            ? 'Report submitted'
                            : reportStatus === 'returned'
                              ? 'Correct your report'
                              : 'Report an incident'
                    }}
                </h2>
                <p>
                    {{
                        success
                            ? 'Your report is now with your barangay.'
                            : 'Document what happened to your home or building.'
                    }}
                </p>
            </div>
            <Tag
                v-if="reportStatus && !success"
                :value="reportStatus === 'returned' ? 'Needs correction' : 'Draft'"
                :severity="reportStatus === 'returned' ? 'warn' : 'secondary'"
            />
        </header>

        <div v-if="loading" class="report-load-state" role="status" aria-label="Loading report">
            <ProgressSpinner />
            <p>Loading your report…</p>
        </div>
        <div v-else-if="loadError" class="report-load-state">
            <Message severity="error" :closable="false">{{ loadError }}</Message>
            <Button label="Try again" size="small" severity="secondary" outlined @click="load" />
        </div>
        <Message v-else-if="profile.reporting_open === false" severity="warn" :closable="false">
            Your barangay is not assigned yet. Ask barangay staff to assign it before reporting.
        </Message>
        <div v-else-if="success" class="report-success">
            <MapIcon name="circle-check" />
            <h3>Report received</h3>
            <p class="report-reference">{{ success.reference }}</p>
            <p>BDRRM will review your report. Submission does not mean it has been accepted.</p>
            <div class="resident-greeting-actions">
                <Button as="router-link" :to="`/reports/${success.id}/track`" label="Track report" size="small" />
                <Button as="router-link" to="/" label="Back to dashboard" size="small" severity="secondary" outlined />
            </div>
        </div>
        <template v-else>
            <Message v-if="reportStatus === 'returned'" severity="warn" :closable="false">{{
                returnReason || 'Review the details below and resubmit your report.'
            }}</Message>
            <Stepper
                :value="step"
                role="group"
                aria-label="Incident report"
                class="report-stepper"
                @update:value="showStep"
            >
                <StepList
                    role="tablist"
                    class="resident-wizard-steps"
                    aria-label="Report steps"
                    @keydown="onStepKeydown"
                >
                    <Step
                        v-for="item in steps"
                        :key="item.value"
                        :value="item.value"
                        :disabled="saving || uploading || item.value > step"
                        :class="{ 'report-step-complete': item.value < step }"
                        :pt="{
                            root: { 'aria-current': null },
                            header: { 'aria-selected': step === item.value, tabindex: step === item.value ? 0 : -1 },
                        }"
                    >
                        <span class="report-step-label"
                            >{{ item.label }}<small>{{ item.hint }}</small></span
                        >
                    </Step>
                </StepList>
                <div class="resident-wizard-layout">
                    <div class="resident-wizard-main">
                        <StepPanels>
                            <StepPanel
                                v-for="item in steps"
                                :key="item.value"
                                :value="item.value"
                                :aria-label="item.label"
                            >
                                <template v-if="step === item.value">
                                    <DetailsStep
                                        v-if="step === 1"
                                        :ref="(instance) => (detailsRef = instance)"
                                        :form="form"
                                        :incidents="incidents"
                                        :profile="profile"
                                        :errors="fieldErrors"
                                        @confirm-pin="confirmPin"
                                        @clear-pin="clearPin"
                                    />
                                    <PhotoStep
                                        v-else-if="step === 2"
                                        :photos="photos"
                                        :pending="pending"
                                        :progress="progress"
                                        :report-id="reportId"
                                        :busy="saving || uploading"
                                        @add="addFiles"
                                        @remove-server="removeServer"
                                        @remove-pending="removePending"
                                        @retry="retryPending"
                                    />
                                    <ReviewStep
                                        v-else
                                        :form="form"
                                        :photos="photos"
                                        :incident-name="incidentName"
                                        :incident-type="selectedIncident?.type || ''"
                                        :occurred-at="selectedIncident?.occurred_at || ''"
                                        :profile="profile"
                                        :report-id="reportId"
                                        :recorded="recordedSignature"
                                        @edit="showStep"
                                        @signature-ready="signatureReady = $event"
                                        @signature-busy="signatureBusy = $event"
                                    />
                                </template>
                            </StepPanel>
                        </StepPanels>
                        <Message v-if="step === 2 && photoError" severity="error" :closable="false">{{
                            photoError
                        }}</Message>
                        <Message v-if="error" severity="error" :closable="false">{{ error }}</Message>
                        <Message v-if="savedMessage" severity="success" :closable="false" role="status">{{
                            savedMessage
                        }}</Message>
                        <footer class="resident-wizard-bar">
                            <Button
                                v-if="step > 1"
                                type="button"
                                label="Back"
                                size="small"
                                severity="secondary"
                                text
                                :disabled="saving || uploading"
                                @click="showStep(step - 1)"
                            >
                                <template #icon><MapIcon name="chevron" class="resident-back-icon" /></template>
                            </Button>
                            <div class="report-forward-actions">
                                <Button
                                    type="button"
                                    :label="reportStatus === 'returned' ? 'Save changes' : 'Save draft'"
                                    size="small"
                                    severity="secondary"
                                    outlined
                                    :loading="saving"
                                    :disabled="saving || uploading"
                                    @click="saveDraft"
                                />
                                <Button
                                    v-if="step < 3"
                                    type="button"
                                    :label="step === 1 ? 'Continue to photos' : 'Review report'"
                                    size="small"
                                    :disabled="saving || uploading"
                                    @click="continueStep"
                                    iconPos="right"
                                >
                                    <template #icon="{ class: iconClass }"
                                        ><MapIcon name="chevron" :class="iconClass"
                                    /></template>
                                </Button>
                                <Button
                                    v-else
                                    type="button"
                                    :label="reportStatus === 'returned' ? 'Resubmit to BDRRM' : 'Submit to BDRRM'"
                                    size="small"
                                    :loading="saving"
                                    :disabled="!canSubmit"
                                    @click="submitReport"
                                >
                                    <template #icon><MapIcon name="send" /></template>
                                </Button>
                            </div>
                        </footer>
                    </div>
                    <aside class="report-context" aria-label="Report information">
                        <div class="report-context-identity">
                            <MapIcon name="pin" />
                            <div>
                                <span>Reporting to</span><strong>{{ profile.barangay_name || 'Your barangay' }}</strong
                                ><small>Barangay disaster risk reduction and management</small>
                            </div>
                        </div>
                        <section>
                            <h3>Before you submit</h3>
                            <ul class="report-requirements">
                                <li :class="{ 'is-complete': detailsReady }">
                                    <MapIcon :name="detailsReady ? 'circle-check' : 'file'" /><span
                                        >Incident details<small
                                            >Describe the damage and confirm the map pin.</small
                                        ></span
                                    >
                                </li>
                                <li :class="{ 'is-complete': photos.length >= 2 }">
                                    <MapIcon :name="photos.length >= 2 ? 'circle-check' : 'camera'" /><span
                                        >At least 2 photos<small>{{
                                            photos.length
                                                ? `${photos.length} of 8 photos attached`
                                                : 'An overall view and a close-up of damage.'
                                        }}</small></span
                                    >
                                </li>
                                <li :class="{ 'is-complete': signatureReady && form.confirmed }">
                                    <MapIcon
                                        :name="signatureReady && form.confirmed ? 'circle-check' : 'pencil'"
                                    /><span
                                        >Your confirmation<small>Review the report and add your signature.</small></span
                                    >
                                </li>
                            </ul>
                        </section>
                        <section class="report-context-note">
                            <h3>You can finish later</h3>
                            <p>
                                Save a draft to continue from My Reports. Your report reaches BDRRM only after you
                                submit it.
                            </p>
                        </section>
                    </aside>
                </div>
            </Stepper>
        </template>
    </section>
</template>
