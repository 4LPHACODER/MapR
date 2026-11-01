<script setup>
import { computed, inject, nextTick, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import MapIcon from '../../shared/components/MapIcon.vue';
import PhotoStep from '../components/PhotoStep.vue';
import DetailsStep from '../components/DetailsStep.vue';
import ReviewStep from '../components/ReviewStep.vue';

const apiBase = inject('apiBase');
const route = useRoute();
const router = useRouter();
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
const summaryRef = ref(null);
const form = reactive({
    disaster_incident_id: '',
    incident_description: '',
    reported_location: '',
    observed_at: '',
    observed_at_approximate: false,
    latitude: null,
    longitude: null,
    confirmed: false,
});

const selectedIncident = computed(() => incidents.value.find((item) => String(item.id) === String(form.disaster_incident_id)) || null);
const incidentName = computed(() => selectedIncident.value?.name || '');
const storageKey = computed(() => (reportId.value ? `mapr-resident-draft-${reportId.value}` : ''));
const uploading = computed(() => pending.value.some((item) => item.uploading));
const detailsReady = computed(() => Boolean(form.disaster_incident_id) && form.reported_location.trim() !== '' && form.incident_description.trim() !== '' && form.latitude != null && form.longitude != null);
const reviewBlockReason = computed(() => {
    if (uploading.value) return 'Wait until the photos finish uploading.';
    if (pending.value.some((item) => item.error)) return 'Remove or retry the photo that failed before continuing.';
    if (photos.value.length === 0) return 'Add at least 2 clear photos of the affected building.';
    if (photos.value.length === 1) return 'Add 1 more photo to continue.';
    return '';
});
const photoError = computed(() => (photoLeaveAttempt.value ? reviewBlockReason.value : ''));
const canSubmit = computed(() => detailsReady.value && photos.value.length >= 2 && !reviewBlockReason.value && form.confirmed && signatureReady.value && !signatureBusy.value && !saving.value);
const summaryLines = computed(() => {
    const lines = [];
    if (incidentName.value) lines.push(incidentName.value);
    if (form.reported_location.trim()) lines.push(form.reported_location.trim());
    if (form.incident_description.trim()) lines.push(form.incident_description.trim());
    if (form.latitude != null && form.longitude != null) lines.push('Pin confirmed');
    if (photos.value.length) lines.push(`${photos.value.length} photo${photos.value.length === 1 ? '' : 's'}`);
    return lines;
});

function remember() {
    if (!storageKey.value) return;
    sessionStorage.setItem(storageKey.value, JSON.stringify({
        disaster_incident_id: form.disaster_incident_id,
        incident_description: form.incident_description,
        reported_location: form.reported_location,
        observed_at: form.observed_at,
        observed_at_approximate: form.observed_at_approximate,
        latitude: form.latitude,
        longitude: form.longitude,
    }));
}

function restore() {
    if (!storageKey.value) return;
    const saved = sessionStorage.getItem(storageKey.value);
    if (!saved) return;
    Object.assign(form, JSON.parse(saved));
}

function toLocalInput(value) {
    if (!value) return '';
    return String(value).replace(' ', 'T').slice(0, 16);
}

function payload() {
    return {
        disaster_incident_id: form.disaster_incident_id || null,
        incident_description: form.incident_description,
        reported_location: form.reported_location,
        observed_at: form.observed_at ? form.observed_at : null,
        observed_at_approximate: Boolean(form.observed_at) && form.observed_at_approximate,
        latitude: form.latitude,
        longitude: form.longitude,
    };
}

function applyReport(report) {
    reportId.value = report.id;
    reportStatus.value = report.status || '';
    returnReason.value = report.return_reason || '';
    form.disaster_incident_id = report.disaster_incident_id || '';
    form.incident_description = report.incident_description || '';
    form.reported_location = report.reported_location || '';
    form.observed_at = toLocalInput(report.observed_at);
    form.observed_at_approximate = Boolean(report.observed_at_approximate);
    form.latitude = report.latitude;
    form.longitude = report.longitude;
    recordedSignature.value = Boolean(report.signature);
    photos.value = report.photos || [];
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
    if (!form.disaster_incident_id) fieldErrors.incident = 'Choose the incident.';
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
    if (!form.disaster_incident_id) {
        throw new Error('Choose the incident before saving.');
    }
    if (!reportId.value) {
        const created = await window.axios.post(`${apiBase}/reports`, payload());
        applyReport(created.data.data);
        await router.replace(`/report/${reportId.value}`);
    } else {
        const updated = await window.axios.patch(`${apiBase}/reports/${reportId.value}`, payload());
        photos.value = updated.data.data.photos || photos.value;
    }
    remember();
}

async function uploadOne(item) {
    item.uploading = true;
    item.error = '';
    const body = new FormData();
    body.append('photo', item.file);
    body.append('source', item.source);
    try {
        const response = await window.axios.post(`${apiBase}/reports/${reportId.value}/photos`, body, {
            onUploadProgress(event) {
                progress.value = { ...progress.value, [item.key]: event.total ? Math.round((event.loaded / event.total) * 100) : 0 };
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
        if (!problem) accepted.push(item);
    });
    if (!accepted.length) return;
    try {
        await ensureDraft();
    } catch (exception) {
        error.value = exception.response?.data?.message || exception.message || 'The draft could not be saved.';
        accepted.forEach((item) => {
            item.error = error.value;
        });
        return;
    }
    for (const item of accepted) {
        await uploadOne(item);
    }
}

async function retryPending(key) {
    const item = pending.value.find((entry) => entry.key === key);
    if (!item || item.uploading) return;
    error.value = '';
    try {
        await ensureDraft();
        await uploadOne(item);
    } catch (exception) {
        error.value = exception.response?.data?.message || exception.message || 'The photo could not be uploaded.';
    }
}

async function removeServer(photo) {
    await window.axios.delete(`${apiBase}/reports/${reportId.value}/photos/${photo.id}`);
    photos.value = photos.value.filter((item) => item.id !== photo.id);
}

function removePending(index) {
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

function showStep(next) {
    step.value = next;
}

async function saveDraft() {
    error.value = '';
    saving.value = true;
    try {
        await ensureDraft();
        await uploadPending();
    } catch (exception) {
        error.value = exception.response?.data?.message || exception.message || 'The draft could not be saved.';
    } finally {
        saving.value = false;
    }
}

async function continueStep() {
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
        if (storageKey.value) sessionStorage.removeItem(storageKey.value);
        success.value = response.data.data;
    } catch (exception) {
        const body = exception.response?.data;
        if (exception.response?.status === 409) {
            error.value = `${body?.message || 'This report was already submitted.'} Reference ${body?.reference || ''}.`;
        } else {
            error.value = body?.message || exception.message || 'The report could not be submitted. The draft is still saved.';
            if (String(body?.message || '').includes('at least 2 photos')) step.value = 2;
        }
    } finally {
        saving.value = false;
    }
}

function syncSummary() {
    if (!summaryRef.value) return;
    summaryRef.value.open = window.matchMedia('(min-width: 1024px)').matches;
}

onMounted(async () => {
    const [profileResponse, incidentResponse] = await Promise.all([
        window.axios.get(`${apiBase}/profile`),
        window.axios.get(`${apiBase}/incidents`),
    ]);
    profile.value = profileResponse.data.data;
    incidents.value = incidentResponse.data.data || [];
    if (!route.params.id && !form.reported_location.trim() && profile.value.suggested_location) {
        form.reported_location = profile.value.suggested_location;
    }
    if (route.params.id) {
        const report = (await window.axios.get(`${apiBase}/reports/${route.params.id}`)).data.data;
        if (!report.editable) {
            await router.replace(`/reports/${report.id}`);
            return;
        }
        applyReport(report);
    }
    syncSummary();
    window.addEventListener('resize', syncSummary);
});

onUnmounted(() => window.removeEventListener('resize', syncSummary));

watch(form, remember, { deep: true });
watch(summaryLines, () => nextTick(syncSummary));
watch(reviewBlockReason, (reason) => {
    if (!reason) photoLeaveAttempt.value = false;
});
watch(step, async () => {
    if (step.value !== 2) photoLeaveAttempt.value = false;
    await nextTick();
    const heading = document.querySelector('.resident-wizard .resident-step-heading');
    const anchor = document.querySelector('.resident-wizard .resident-wizard-steps');
    heading?.focus({ preventScroll: true });
    anchor?.scrollIntoView({ block: 'start' });
});
</script>

<template>
    <section class="resident-wizard">
        <p v-if="profile.reporting_open === false" class="resident-reason">Your barangay is not assigned yet. Ask barangay staff to assign it before reporting.</p>
        <template v-else-if="success">
            <h2>Report submitted</h2>
            <p>Reference {{ success.reference }}</p>
            <p class="resident-lead">Your report is with BDRRM for review. This does not mean it has been accepted.</p>
            <div class="resident-greeting-actions">
                <router-link class="resident-primary" :to="`/reports/${success.id}/track`">Track Report</router-link>
                <router-link class="resident-secondary" to="/">Back to Dashboard</router-link>
            </div>
        </template>
        <template v-else>
            <p v-if="reportStatus === 'returned'" class="resident-reason">Needs correction. {{ returnReason }}</p>
            <p v-else-if="reportStatus === 'draft'" class="resident-kicker">Draft</p>
            <ol class="resident-wizard-steps" aria-label="Report steps">
                <li :class="{ 'is-current': step === 1, 'is-done': step > 1 }" :aria-current="step === 1 ? 'step' : undefined">
                    <span class="resident-wizard-step-index" aria-hidden="true">1</span>
                    <span>Details</span>
                </li>
                <li :class="{ 'is-current': step === 2, 'is-done': step > 2 }" :aria-current="step === 2 ? 'step' : undefined">
                    <span class="resident-wizard-step-index" aria-hidden="true">2</span>
                    <span>Photos</span>
                </li>
                <li :class="{ 'is-current': step === 3 }" :aria-current="step === 3 ? 'step' : undefined">
                    <span class="resident-wizard-step-index" aria-hidden="true">3</span>
                    <span>Review</span>
                </li>
            </ol>
            <div v-if="step > 1" class="resident-wizard-back">
                <button type="button" class="resident-secondary" :disabled="saving" @click="showStep(step - 1)">
                    <MapIcon name="chevron" class="resident-back-icon" />
                    {{ step === 2 ? 'Back to Details' : 'Back to Photos' }}
                </button>
            </div>
            <div class="resident-wizard-layout">
                <div class="resident-wizard-main">
                    <DetailsStep
                        v-if="step === 1"
                        ref="detailsRef"
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
                    <p v-if="step === 2 && photoError" class="resident-error">{{ photoError }}</p>
                    <p v-if="error" class="resident-error">{{ error }}</p>
                    <div class="resident-wizard-bar">
                        <button type="button" class="resident-secondary" :disabled="saving" @click="saveDraft">
                            <MapIcon name="file" />
                            {{ saving && step !== 3 ? 'Saving…' : (reportStatus === 'returned' ? 'Save changes' : 'Save Draft') }}
                        </button>
                        <button v-if="step === 1" type="button" class="resident-primary" :disabled="saving" @click="continueStep">
                            Continue to Photos <MapIcon name="chevron" />
                        </button>
                        <button v-else-if="step === 2" type="button" class="resident-primary" :aria-disabled="!!reviewBlockReason" :disabled="saving" @click="continueStep">
                            Continue to Review <MapIcon name="chevron" />
                        </button>
                        <button v-else type="button" class="resident-primary" :disabled="!canSubmit" @click="submitReport">
                            <MapIcon name="send" />
                            {{ saving ? 'Submitting…' : (reportStatus === 'returned' ? 'Resubmit to BDRRM' : 'Submit to BDRRM') }}
                        </button>
                    </div>
                </div>
                <details v-if="summaryLines.length" ref="summaryRef" class="resident-wizard-summary">
                    <summary>Report Summary</summary>
                    <div>
                        <p v-for="(line, index) in summaryLines" :key="index">{{ line }}</p>
                    </div>
                </details>
            </div>
        </template>
    </section>
</template>
