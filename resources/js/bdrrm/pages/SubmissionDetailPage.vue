<script setup>
import { computed, inject, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import Button from 'primevue/button';
import Checkbox from 'primevue/checkbox';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';
import Select from 'primevue/select';
import Tag from 'primevue/tag';
import Textarea from 'primevue/textarea';
import { useRoute } from 'vue-router';
import MapIcon from '../../shared/components/MapIcon.vue';
import ReportPdfViewer from '../../shared/components/ReportPdfViewer.vue';
import SignaturePad from '../../resident/components/SignaturePad.vue';

const apiBase = inject('apiBase');
const roleCode = ref(inject('roleCode', ''));
const route = useRoute();
const report = ref(null);
const phase = ref('loading');
const error = ref('');
const actionError = ref('');
const downloadError = ref('');
const reviewError = ref('');
const downloading = ref(false);
const busy = ref(false);
const viewer = ref(null);
const flagOpen = ref(false);
const returnOpen = ref(false);
const pad = ref(null);
const flagCategories = ['Photographs', 'Description', 'Location', 'Other'];
const flagCategory = ref(flagCategories[0]);
const flagSection = ref('');
const flagReason = ref('');
const returnReason = ref('');
const signConfirmed = ref(false);
const useSaved = ref(false);
const savedPreview = ref('');
const officerName = ref('');
const officerRole = ref('');
const assignment = ref('');
const signPreview = ref('');
const signOpen = ref(false);
const resolveOpen = ref(false);
const resolveTarget = ref(null);
const resolution = ref('');
let signPreviewUrl = '';

const backQuery = computed(() => {
    const query = {};
    ['q', 'status', 'queue', 'page', 'per_page', 'submitted_from', 'submitted_to'].forEach((key) => {
        const value = route.query[key];
        if (typeof value === 'string' && value !== '') query[key] = value;
    });
    return query;
});

const submissionId = computed(() => {
    if (route.params.id) return String(route.params.id);
    if (route.query.report_type === 'resident' && typeof route.query.report_id === 'string' && route.query.report_id !== '') {
        return route.query.report_id;
    }
    return '';
});

const documentUrl = computed(() => (submissionId.value ? `${apiBase}/submissions/${submissionId.value}/document` : ''));

const mapLink = computed(() => {
    if (!report.value?.has_point) return null;
    return {
        path: '/map',
        query: {
            lng: String(report.value.longitude),
            lat: String(report.value.latitude),
        },
    };
});

const canReview = computed(() => Boolean(report.value?.reviewable) && roleCode.value === 'bdrrm');
const canStartReview = computed(() => canReview.value && report.value?.review_state === 'not_opened' && report.value?.status === 'submitted');
const canSign = computed(() => canReview.value && (report.value?.review_state === 'reviewing' || report.value?.status === 'under_review'));
const canCheck = computed(() => roleCode.value === 'barangay_secretary'
    && report.value?.barangay_workflow_stage === 'pending_secretary'
    && Boolean(report.value?.bdrrm_endorsement));
const canEndorse = computed(() => roleCode.value === 'barangay_captain'
    && report.value?.barangay_workflow_stage === 'pending_captain'
    && Boolean(report.value?.bdrrm_endorsement));
const canReturn = computed(() => canSign.value || canCheck.value);
const signMode = ref('bdrrm');
const signTitle = computed(() => {
    if (signMode.value === 'secretary') return 'Check and Sign Resident Report';
    if (signMode.value === 'captain') return 'Endorse and Sign Resident Report';
    return 'Sign and Accept Resident Report';
});
const signSubmitLabel = computed(() => {
    if (signMode.value === 'secretary') return 'Check and sign';
    if (signMode.value === 'captain') return 'Endorse and sign';
    return 'Sign and accept';
});
const backLabel = computed(() => {
    if (roleCode.value === 'barangay_captain') return 'Back to Checked Reports';
    if (roleCode.value === 'barangay_secretary') return 'Back to Reviewed Reports';
    return 'Back to Submissions';
});
const openFlags = computed(() => Number(report.value?.open_flags || 0));
const statusSeverity = computed(() => {
    if (report.value?.status === 'returned') return 'warn';
    if (report.value?.label === 'Finalized') return 'success';
    return 'info';
});
const openFlagList = computed(() => (report.value?.flags || []).filter((flag) => flag.status === 'open'));
const historyOpen = ref(false);
const historyNewest = computed(() => [...(report.value?.timeline || [])].reverse());
const historyVisible = computed(() => (historyOpen.value ? historyNewest.value : historyNewest.value.slice(0, 3)));
const hiddenHistory = computed(() => Math.max(0, historyNewest.value.length - 3));

function formatWhen(value) {
    if (!value) return '—';
    const date = new Date(String(value).includes('T') ? value : `${String(value).replace(' ', 'T')}Z`);
    if (Number.isNaN(date.getTime())) return value;
    return `${new Intl.DateTimeFormat('en-PH', {
        timeZone: 'Asia/Manila',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    }).format(date)} PHT`;
}

async function load() {
    phase.value = 'loading';
    error.value = '';
    actionError.value = '';
    downloadError.value = '';
    reviewError.value = '';
    historyOpen.value = false;
    report.value = null;
    try {
        if (!submissionId.value) {
            phase.value = 'error';
            error.value = 'This submission could not be found.';
            return;
        }
        const { data } = await window.axios.get(`${apiBase}/submissions/${submissionId.value}`);
        report.value = data.data;
        phase.value = 'ready';
        await nextTick();
        openRequestedPanel();
    } catch (exception) {
        phase.value = 'error';
        error.value = exception.response?.status === 403
            ? 'This submission is outside your assignment.'
            : exception.response?.status === 404
                ? 'This submission could not be found.'
                : 'This submission could not be loaded.';
    }
}

async function reloadReport() {
    const { data } = await window.axios.get(`${apiBase}/submissions/${submissionId.value}`);
    report.value = data.data;
    await viewer.value?.reload();
}

function openRequestedPanel() {
    if (route.query.panel === 'flag') openFlag();
    if (route.query.panel === 'return' && canReview.value) openReturn();
}

function openFlag() {
    actionError.value = '';
    flagOpen.value = true;
}

function openReturn() {
    actionError.value = '';
    returnOpen.value = true;
}

async function downloadPdf() {
    if (downloading.value || !report.value) return;
    downloading.value = true;
    downloadError.value = '';
    try {
        const response = await window.axios.get(documentUrl.value, {
            params: { download: 1 },
            responseType: 'blob',
        });
        const type = response.headers['content-type'] || '';
        if (!type.includes('pdf')) throw new Error('The download was not a PDF.');
        const blob = new Blob([response.data], { type: 'application/pdf' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `MapR_Resident_Report_${report.value.reference}.pdf`;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    } catch (exception) {
        downloadError.value = exception.response?.data?.message || 'The PDF could not be downloaded.';
    } finally {
        downloading.value = false;
    }
}

async function submitFlag() {
    if (busy.value || !report.value) return;
    const typed = flagReason.value;
    busy.value = true;
    actionError.value = '';
    try {
        await window.axios.post(`${apiBase}/flags`, {
            subject_type: 'resident_incident_report',
            subject_id: report.value.id,
            category: flagCategory.value,
            section: flagSection.value.trim() || null,
            reason: typed.trim(),
        });
        flagReason.value = '';
        flagSection.value = '';
        flagOpen.value = false;
        await reloadReport();
    } catch (exception) {
        flagReason.value = typed;
        actionError.value = exception.response?.data?.message || 'The flag could not be saved.';
    } finally {
        busy.value = false;
    }
}

function openResolve(flag) {
    resolveTarget.value = flag;
    resolution.value = '';
    actionError.value = '';
    resolveOpen.value = true;
}

async function resolveFlag() {
    if (busy.value || !resolveTarget.value) return;
    const typed = resolution.value;
    busy.value = true;
    actionError.value = '';
    try {
        await window.axios.post(`${apiBase}/flags/${resolveTarget.value.id}/resolve`, { resolution: typed.trim() });
        resolveOpen.value = false;
        resolution.value = '';
        await reloadReport();
    } catch (exception) {
        resolution.value = typed;
        actionError.value = exception.response?.data?.errors?.resolution?.[0]
            || exception.response?.data?.message
            || 'The flag could not be resolved.';
    } finally {
        busy.value = false;
    }
}

async function returnReport() {
    if (busy.value || !canReturn.value) return;
    const typed = returnReason.value;
    busy.value = true;
    actionError.value = '';
    try {
        const { data } = await window.axios.post(`${apiBase}/submissions/${report.value.id}/return`, {
            return_reason: typed.trim(),
        });
        report.value = data.data;
        returnOpen.value = false;
        await viewer.value?.reload();
    } catch (exception) {
        returnReason.value = typed;
        const validation = exception.response?.data?.errors?.return_reason?.[0];
        actionError.value = validation || exception.response?.data?.message || 'The report could not be returned.';
    } finally {
        busy.value = false;
    }
}

async function startReview() {
    if (!canStartReview.value || busy.value) return;
    busy.value = true;
    reviewError.value = '';
    try {
        const { data } = await window.axios.post(`${apiBase}/submissions/${submissionId.value}/start-review`);
        report.value = data.data;
        await viewer.value?.reload();
    } catch (exception) {
        reviewError.value = exception.response?.data?.message || 'The review could not be started.';
    } finally {
        busy.value = false;
    }
}

async function openSign(mode = 'bdrrm') {
    if (mode === 'bdrrm' && (!canSign.value || openFlags.value > 0)) return;
    if (mode === 'secretary' && !canCheck.value) return;
    if (mode === 'captain' && !canEndorse.value) return;
    signMode.value = mode;
    signConfirmed.value = false;
    useSaved.value = false;
    savedPreview.value = '';
    if (signPreviewUrl) URL.revokeObjectURL(signPreviewUrl);
    signPreview.value = '';
    actionError.value = '';
    try {
        const profile = (await window.axios.get(`${apiBase}/profile`)).data;
        const summary = (await window.axios.get(`${apiBase}/summary`)).data;
        officerName.value = profile.name || 'Signed-in officer';
        officerRole.value = profile.role || (mode === 'secretary' ? 'Barangay Secretary' : (mode === 'captain' ? 'Barangay Captain' : 'BDRRM'));
        const names = (summary.barangays || []).map((row) => row.name).filter(Boolean);
        assignment.value = names.length ? names.join(', ') : 'Assigned barangay';
    } catch {
        officerName.value = 'Signed-in officer';
        officerRole.value = mode === 'secretary' ? 'Barangay Secretary' : (mode === 'captain' ? 'Barangay Captain' : 'BDRRM');
        assignment.value = 'Assigned barangay';
    }
    signOpen.value = true;
    await nextTick();
}

async function chooseSaved() {
    useSaved.value = true;
    pad.value?.clearDrawing();
    if (signPreviewUrl) URL.revokeObjectURL(signPreviewUrl);
    signPreview.value = '';
    try {
        const response = await window.axios.get(`${apiBase}/signature/preview`, { responseType: 'blob' });
        savedPreview.value = URL.createObjectURL(response.data);
    } catch (exception) {
        useSaved.value = false;
        actionError.value = exception.response?.data?.message || 'No saved signature is available.';
    }
}

function clearSignature() {
    useSaved.value = false;
    savedPreview.value = '';
    if (signPreviewUrl) URL.revokeObjectURL(signPreviewUrl);
    signPreview.value = '';
    pad.value?.clearDrawing();
}

async function refreshPreview() {
    if (!pad.value?.hasInk()) {
        if (signPreviewUrl) URL.revokeObjectURL(signPreviewUrl);
        signPreview.value = '';
        return;
    }
    const blob = await pad.value.exportPng();
    if (!blob) return;
    if (signPreviewUrl) URL.revokeObjectURL(signPreviewUrl);
    signPreviewUrl = URL.createObjectURL(blob);
    signPreview.value = signPreviewUrl;
    useSaved.value = false;
    savedPreview.value = '';
}

async function signAndAccept() {
    const allowed = (signMode.value === 'bdrrm' && canSign.value)
        || (signMode.value === 'secretary' && canCheck.value)
        || (signMode.value === 'captain' && canEndorse.value);
    if (busy.value || !allowed || !signConfirmed.value) return;
    const ink = Boolean(pad.value?.hasInk());
    if (!ink && !useSaved.value) {
        actionError.value = 'Draw a signature or choose your saved signature before continuing.';
        return;
    }
    busy.value = true;
    actionError.value = '';
    try {
        const body = new FormData();
        body.append('confirmed', '1');
        if (ink) {
            const blob = await pad.value.exportPng();
            if (!blob) throw new Error('The signature could not be read.');
            body.append('signature', blob, 'signature.png');
            body.append('use_saved_signature', '0');
        } else {
            body.append('use_saved_signature', '1');
        }
        if (signMode.value === 'bdrrm') {
            body.append('content_revision', report.value.content_revision);
            const { data } = await window.axios.post(`${apiBase}/submissions/${report.value.id}/accept`, body);
            report.value = data.data;
        } else if (signMode.value === 'secretary') {
            await window.axios.post(`${apiBase}/reports/${report.value.barangay_report_id}/endorse`, body);
            await reloadReport();
        } else {
            await window.axios.post(`${apiBase}/reports/${report.value.barangay_report_id}/captain-approve`, body);
            await reloadReport();
        }
        signOpen.value = false;
        await viewer.value?.reload();
    } catch (exception) {
        actionError.value = exception.response?.data?.message || 'The signature could not be saved.';
        if (exception.response?.status === 409) {
            const message = actionError.value;
            try {
                await reloadReport();
            } catch {
                // Keep the conflict message when the refresh fails.
            }
            actionError.value = message;
        }
    } finally {
        busy.value = false;
    }
}

watch(submissionId, load, { immediate: true });

onBeforeUnmount(() => {
    if (signPreviewUrl) URL.revokeObjectURL(signPreviewUrl);
    if (savedPreview.value) URL.revokeObjectURL(savedPreview.value);
});
</script>

<template>
    <section class="bdrrm-submissions submission-detail">
        <nav class="submission-links" aria-label="Submission navigation">
            <RouterLink class="submission-back-link" :to="{ path: '/submissions', query: backQuery }">
                <MapIcon name="chevron" /> {{ backLabel }}
            </RouterLink>
            <a v-if="phase === 'ready'" href="#submission-review-title" class="submission-review-link">Go to review</a>
        </nav>
        <div v-if="phase === 'loading'" class="submission-state" role="status">Loading submission…</div>
        <div v-else-if="phase === 'error'" class="submission-state" role="alert">
            <MapIcon name="file-text" />
            <h2>Submission unavailable</h2>
            <p>{{ error }}</p>
        </div>
        <template v-else-if="report">
            <header class="submission-overview">
                <div class="submission-identity">
                    <h2>{{ report.reference }}</h2>
                    <Tag :value="report.label" :severity="statusSeverity" />
                </div>
                <dl class="submission-facts">
                    <div>
                        <dt>Incident</dt>
                        <dd>{{ report.incident_name || 'Incident unavailable' }}</dd>
                    </div>
                    <div>
                        <dt>Reported by</dt>
                        <dd>{{ report.resident_name || 'Name unavailable' }}</dd>
                    </div>
                    <div>
                        <dt>Barangay</dt>
                        <dd>{{ report.barangay_name || 'Barangay unavailable' }}</dd>
                    </div>
                    <div v-if="report.submitted_at">
                        <dt>Submitted</dt>
                        <dd>{{ formatWhen(report.submitted_at) }}</dd>
                    </div>
                </dl>
            </header>
            <div class="submission-workbench">
                <section class="submission-surface submission-document" aria-labelledby="submission-document-title">
                    <div class="submission-section-heading">
                        <h3 id="submission-document-title"><MapIcon name="file-text" /> Report preview</h3>
                        <Button size="small" severity="secondary" outlined label="Download PDF" :loading="downloading" @click="downloadPdf">
                            <template #icon><MapIcon name="download" /></template>
                        </Button>
                    </div>
                    <Message v-if="downloadError" severity="error" size="small" class="submission-download-error">{{ downloadError }}</Message>
                    <ReportPdfViewer ref="viewer" presentation="review" :document-url="documentUrl" />
                </section>

                <aside class="submission-surface submission-review" aria-labelledby="submission-review-title">
                    <div class="submission-section-heading">
                        <h3 id="submission-review-title" tabindex="-1"><MapIcon name="clipboard" /> Review</h3>
                    </div>
                    <div v-if="canStartReview || canSign || canCheck || canEndorse || report.return_reason" class="submission-decision">
                        <p v-if="report.return_reason"><strong>Correction requested</strong><br>{{ report.return_reason }}</p>
                        <p v-if="canSign && openFlags > 0" id="submission-sign-blocked" class="submission-sign-blocked">
                            Resolve all open flags before signing.
                        </p>
                        <div v-if="canStartReview || canSign || canCheck || canEndorse" class="submission-decision-actions">
                            <Button v-if="canStartReview" size="small" label="Start review" :loading="busy" @click="startReview">
                                <template #icon><MapIcon name="clipboard" /></template>
                            </Button>
                            <Button v-else-if="canSign" size="small" label="Sign and accept" :disabled="busy || openFlags > 0"
                                :aria-describedby="openFlags > 0 ? 'submission-sign-blocked' : undefined" @click="openSign('bdrrm')">
                                <template #icon><MapIcon name="pen-line" /></template>
                            </Button>
                            <Button v-else-if="canCheck" size="small" label="Check and sign" :disabled="busy" @click="openSign('secretary')">
                                <template #icon><MapIcon name="pen-line" /></template>
                            </Button>
                            <Button v-else-if="canEndorse" size="small" label="Endorse and sign" :disabled="busy" @click="openSign('captain')">
                                <template #icon><MapIcon name="file-check-2" /></template>
                            </Button>
                            <Button v-if="canReturn" size="small" severity="secondary" outlined label="Return for correction" :disabled="busy" @click="openReturn" />
                        </div>
                        <Message v-if="reviewError" size="small" severity="error">{{ reviewError }}</Message>
                    </div>
                    <section class="submission-review-group" aria-labelledby="submission-flags-title">
                        <div class="submission-review-heading">
                            <h4 id="submission-flags-title">Flags</h4>
                            <Tag v-if="openFlags" :value="`${openFlags} open`" severity="warn" />
                            <Button size="small" text label="Flag an issue" severity="secondary" :disabled="busy" @click="openFlag">
                                <template #icon><MapIcon name="flag" /></template>
                            </Button>
                        </div>
                        <ul v-if="openFlagList.length" class="submission-flags">
                            <li v-for="flag in openFlagList" :key="flag.id">
                                <strong>{{ flag.category }}</strong>
                                <span v-if="flag.section" class="submission-muted">{{ flag.section }}</span>
                                <p>{{ flag.reason }}</p>
                                <Button size="small" label="Resolve" severity="secondary" outlined :disabled="busy"
                                    :aria-label="`Resolve ${flag.category.toLowerCase()} flag`" @click="openResolve(flag)" />
                            </li>
                        </ul>
                        <p v-else class="submission-muted">No open flags</p>
                    </section>
                    <section class="submission-review-group" aria-labelledby="submission-location-title">
                        <div class="submission-review-heading">
                            <h4 id="submission-location-title">Reported location</h4>
                            <Button v-if="mapLink" :as="RouterLink" :to="mapLink" size="small" label="View on map" severity="secondary" text>
                                <template #icon><MapIcon name="pin" /></template>
                            </Button>
                        </div>
                        <p v-if="report.reported_location">{{ report.reported_location }}</p>
                        <p v-if="!mapLink" class="submission-muted">No map point recorded</p>
                    </section>
                    <section class="submission-review-group" aria-labelledby="submission-signature-title">
                        <h4 id="submission-signature-title">BDRRM signature</h4>
                        <template v-if="report.bdrrm_endorsement">
                            <div>
                                <p>{{ report.bdrrm_endorsement.signer_name || 'Officer' }}</p>
                                <p class="submission-muted">{{ formatWhen(report.bdrrm_endorsement.signed_at) }}</p>
                            </div>
                            <img v-if="report.bdrrm_endorsement.has_image" class="bdrrm-signature-image"
                                :src="`${apiBase}/submissions/${report.id}/review-signature`" alt="BDRRM reviewer signature">
                        </template>
                        <p v-else class="submission-muted">Not signed yet</p>
                    </section>
                    <section class="submission-review-group" aria-labelledby="submission-history-title">
                        <h4 id="submission-history-title">Review history</h4>
                        <ol v-if="historyVisible.length" id="submission-history-events" class="submission-history">
                            <li v-for="(event, index) in historyVisible" :key="`${event.at}-${index}`">
                                <strong>{{ event.title }}</strong>
                                <time class="submission-muted">{{ formatWhen(event.at) }}</time>
                                <p v-if="event.detail">{{ event.detail }}</p>
                            </li>
                        </ol>
                        <p v-else class="submission-muted">No review events yet</p>
                        <Button v-if="hiddenHistory > 0" size="small" severity="secondary" text
                            :label="historyOpen ? 'Hide earlier events' : `Show earlier events (${hiddenHistory})`"
                            :aria-expanded="historyOpen" aria-controls="submission-history-events" @click="historyOpen = !historyOpen" />
                    </section>
                </aside>
            </div>
            <Dialog v-model:visible="flagOpen" header="Flag an issue" class="submission-review-dialog"
                modal block-scroll :closable="!busy" :close-on-escape="!busy">
                <form class="submission-dialog-form" @submit.prevent="submitFlag">
                    <p class="submission-muted">{{ report.reference }}</p>
                    <Message v-if="actionError" size="small" severity="error">{{ actionError }}</Message>
                    <div class="submission-dialog-field">
                        <label id="submission-flag-category-label" for="submission-flag-category">Category</label>
                        <Select v-model="flagCategory" input-id="submission-flag-category" aria-labelledby="submission-flag-category-label"
                            size="small" :options="flagCategories" />
                    </div>
                    <div class="submission-dialog-field">
                        <label for="submission-flag-section">Section or photo (optional)</label>
                        <InputText id="submission-flag-section" v-model="flagSection" size="small" maxlength="120" autocomplete="off" />
                    </div>
                    <div class="submission-dialog-field">
                        <label for="submission-flag-reason">What needs attention?</label>
                        <Textarea id="submission-flag-reason" v-model="flagReason" size="small" rows="4" maxlength="2000" required />
                    </div>
                    <div class="submission-dialog-actions">
                        <Button type="button" size="small" label="Cancel" severity="secondary" outlined :disabled="busy" @click="flagOpen = false" />
                        <Button type="submit" size="small" label="Save flag" :loading="busy" />
                    </div>
                </form>
            </Dialog>
            <Dialog v-model:visible="resolveOpen" header="Resolve flag" class="submission-review-dialog"
                modal block-scroll :closable="!busy" :close-on-escape="!busy">
                <form class="submission-dialog-form" @submit.prevent="resolveFlag">
                    <div>
                        <p class="submission-muted">{{ report.reference }}</p>
                        <p class="mt-1"><strong>{{ resolveTarget?.category }}</strong></p>
                        <p>{{ resolveTarget?.reason }}</p>
                    </div>
                    <Message v-if="actionError" size="small" severity="error">{{ actionError }}</Message>
                    <div class="submission-dialog-field">
                        <label for="submission-flag-resolution">Resolution</label>
                        <Textarea id="submission-flag-resolution" v-model="resolution" size="small" rows="4" maxlength="2000" required />
                    </div>
                    <div class="submission-dialog-actions">
                        <Button type="button" size="small" label="Cancel" severity="secondary" outlined :disabled="busy" @click="resolveOpen = false" />
                        <Button type="submit" size="small" label="Save resolution" :loading="busy" />
                    </div>
                </form>
            </Dialog>
            <Dialog v-model:visible="returnOpen" header="Return report for correction" class="submission-review-dialog"
                modal block-scroll :closable="!busy" :close-on-escape="!busy">
                <form class="submission-dialog-form" @submit.prevent="returnReport">
                    <div>
                        <p class="submission-muted">{{ report.reference }}</p>
                        <p class="mt-1">The resident will see your reason and can resubmit after making corrections.</p>
                    </div>
                    <Message v-if="actionError" size="small" severity="error">{{ actionError }}</Message>
                    <div class="submission-dialog-field">
                        <label for="submission-return-reason">What needs to be corrected?</label>
                        <Textarea id="submission-return-reason" v-model="returnReason" size="small" rows="5" maxlength="5000" required />
                    </div>
                    <div class="submission-dialog-actions">
                        <Button type="button" size="small" label="Cancel" severity="secondary" outlined :disabled="busy" @click="returnOpen = false" />
                        <Button type="submit" size="small" label="Return report" severity="danger" :disabled="!canReturn" :loading="busy" />
                    </div>
                </form>
            </Dialog>
            <Dialog v-model:visible="signOpen" :header="signTitle" class="submission-review-dialog"
                modal block-scroll :closable="!busy" :close-on-escape="!busy">
                <form class="submission-dialog-form" @submit.prevent="signAndAccept">
                    <p class="submission-muted">{{ report.reference }} · {{ report.resident_name || 'Resident' }} · {{ report.incident_name || 'Incident' }}</p>
                    <Message v-if="actionError" size="small" severity="error">{{ actionError }}</Message>
                    <p>{{ officerName }} · {{ officerRole }} · {{ assignment }}</p>
                    <p v-if="signMode === 'secretary'">I have checked this BDRRM-reviewed resident report and forward it to the Barangay Captain for endorsement.</p>
                    <p v-else-if="signMode === 'captain'">I endorse this secretary-checked resident report for handoff to RDANA assessment.</p>
                    <p v-else>I have reviewed this resident submission and accept it for handoff to the next barangay office.</p>
                    <SignaturePad
                        v-if="signOpen"
                        ref="pad"
                        manual
                        :label="signMode === 'secretary' ? 'Secretary signature' : (signMode === 'captain' ? 'Captain signature' : 'BDRRM signature')"
                        lead="Draw your signature. It is saved with this report and does not replace your profile signature."
                        @pointerup="refreshPreview"
                    />
                    <div class="submission-signature-actions">
                        <Button type="button" size="small" label="Clear signature" severity="secondary" outlined @click="clearSignature" />
                        <Button type="button" size="small" label="Use saved signature" severity="secondary" outlined @click="chooseSaved" />
                    </div>
                    <img v-if="useSaved && savedPreview" :src="savedPreview" alt="Saved signature preview" class="bdrrm-sign-preview">
                    <img v-else-if="signPreview" :src="signPreview" alt="Signature preview" class="bdrrm-sign-preview">
                    <div class="submission-confirm">
                        <Checkbox v-model="signConfirmed" input-id="submission-sign-confirm" binary />
                        <label for="submission-sign-confirm">{{ signMode === 'secretary' ? 'I confirm this checking decision.' : (signMode === 'captain' ? 'I confirm this endorsement.' : 'I confirm this review and acceptance.') }}</label>
                    </div>
                    <div class="submission-dialog-actions">
                        <Button type="button" size="small" label="Cancel" severity="secondary" outlined :disabled="busy" @click="signOpen = false" />
                        <Button type="submit" size="small" :label="signSubmitLabel" :disabled="!signConfirmed" :loading="busy" />
                    </div>
                </form>
            </Dialog>
        </template>
    </section>
</template>

<style scoped>
.submission-detail {
    display: grid;
    gap: 1rem;
    min-width: 0;
}

.submission-links {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 0.25rem 1rem;
}

.submission-links a {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    min-height: var(--submission-control);
    color: var(--resident-navy);
    font-weight: 600;
}

.submission-back-link :deep(svg) {
    transform: rotate(180deg);
}

.submission-links a:hover {
    text-decoration: underline;
}

.submission-overview,
.submission-surface {
    min-width: 0;
    border: 1px solid var(--submission-line);
    border-radius: var(--submission-radius);
    background: var(--resident-card);
}

.submission-overview {
    padding: 1rem 1.25rem;
}

.submission-identity {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem 0.75rem;
}

.submission-detail h2 {
    color: var(--resident-ink);
    font-size: 1.5rem;
    font-weight: 700;
    line-height: 1.25;
    overflow-wrap: anywhere;
}

.submission-facts {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.75rem 1rem;
    margin-block-start: 0.75rem;
}

.submission-facts dt,
.submission-muted {
    color: var(--p-surface-600);
    font-size: 0.75rem;
}

.submission-facts dd {
    font-weight: 500;
}

.submission-facts dd,
.submission-review p,
.submission-dialog-form p {
    overflow-wrap: anywhere;
}

.submission-workbench {
    display: grid;
    gap: 1rem;
    align-items: start;
    min-width: 0;
}

.submission-section-heading {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    min-height: 3.25rem;
    padding: 0.625rem 1rem;
    border-bottom: 1px solid var(--submission-line);
}

.submission-section-heading h3 {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.9375rem;
    font-weight: 700;
    scroll-margin-block-start: 6rem;
}

.submission-section-heading h3 :deep(svg) {
    color: var(--resident-navy);
}

.submission-document :deep(.resident-report-view) {
    min-width: 0;
}

.submission-document :deep(.resident-report-zoom) {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.25rem;
    min-height: 2.75rem;
    padding: 0.25rem 1rem;
    border-bottom: 1px solid var(--submission-line);
    color: var(--p-surface-700);
    font-variant-numeric: tabular-nums;
}

.submission-document :deep(.resident-report-zoom button) {
    display: inline-grid;
    place-items: center;
    min-width: var(--submission-control);
    min-height: var(--submission-control);
    padding-inline: 0.5rem;
    border-radius: var(--submission-radius);
    cursor: pointer;
    font-weight: 500;
}

.submission-document :deep(.resident-report-zoom button:hover) {
    background: var(--resident-wash);
    color: var(--resident-navy);
}

.submission-document :deep(.resident-report-zoom span:first-of-type) {
    min-width: 3ch;
    text-align: center;
}

.submission-document :deep(.resident-report-zoom span:nth-of-type(2)) {
    margin-inline-start: auto;
}

.submission-document :deep(.resident-report-host) {
    min-height: 14rem;
    max-height: clamp(17rem, 63dvh, 51rem);
    overflow: auto;
    scrollbar-gutter: stable;
    overscroll-behavior-inline: contain;
    padding: 0.875rem 0.5rem 1rem;
    border-radius: 0 0 var(--submission-radius) var(--submission-radius);
    background: var(--p-surface-100);
}

.submission-document :deep(.resident-report-stage) {
    display: grid;
    justify-items: center;
    gap: 0.875rem;
    width: max-content;
    min-width: 100%;
}

.submission-document :deep(.resident-report-sheet) {
    display: block;
    max-width: none;
    background: var(--resident-card);
    box-shadow: 0 2px 8px rgb(15 23 42 / 12%);
}

.submission-download-error,
.submission-document :deep(.resident-error) {
    margin: 0.75rem 1rem;
}

.submission-document :deep(.resident-error) {
    color: var(--p-red-800);
}

.submission-decision,
.submission-review-group {
    display: grid;
    gap: 0.5rem;
    padding: 0.75rem 1rem;
}

.submission-review-group + .submission-review-group {
    border-top: 1px solid var(--submission-line);
}

.submission-decision {
    gap: 0.75rem;
    border-bottom: 1px solid var(--submission-line);
    background: var(--resident-wash);
}

.submission-decision-actions,
.submission-review-heading,
.submission-signature-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.375rem;
}

.submission-sign-blocked {
    color: var(--p-amber-900);
}

.submission-review-heading h4 {
    margin-inline-end: auto;
}

.submission-review-group h4,
.submission-dialog-form :deep(.resident-signature h3) {
    font-size: 0.875rem;
    font-weight: 700;
}

.submission-flags,
.submission-history {
    display: grid;
    gap: 0.5rem;
    padding: 0;
    list-style: none;
}

.submission-flags li {
    display: grid;
    justify-items: start;
    gap: 0.25rem;
    width: 100%;
    padding: 0.625rem 0.75rem;
    border: 1px solid var(--p-amber-200);
    border-radius: var(--submission-radius);
    background: var(--p-amber-50);
}

.submission-flags li .p-button {
    margin-top: 0.25rem;
    background: var(--resident-card);
}

.submission-history li {
    display: grid;
    gap: 0.125rem;
    padding-inline-start: 0.75rem;
    border-inline-start: 2px solid var(--p-indigo-200);
}

.submission-history strong {
    overflow-wrap: anywhere;
}

.submission-review-group > .p-button {
    justify-self: start;
}

:global(.submission-review-dialog.p-dialog) {
    width: min(32rem, calc(100% - 2rem));
}

.submission-dialog-form,
.submission-dialog-field {
    display: grid;
    gap: 0.375rem;
}

.submission-dialog-form {
    gap: 0.875rem;
    color: var(--p-surface-800);
    font-size: 0.875rem;
    line-height: 1.4;
}

.submission-dialog-field label {
    font-size: 0.8125rem;
    font-weight: 600;
}

.submission-dialog-form :is(.p-button, .p-select, .p-inputtext, .p-textarea) {
    border-radius: var(--p-border-radius-sm);
}

.submission-dialog-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 0.5rem;
}

.submission-confirm {
    display: flex;
    align-items: flex-start;
    gap: 0.5rem;
}

.submission-dialog-form :deep(.resident-signature) {
    display: grid;
    gap: 0.375rem;
}

.submission-dialog-form :deep(.resident-signature canvas) {
    width: 100%;
    height: 9rem;
    border: 1px solid var(--p-surface-300);
    border-radius: var(--p-border-radius-sm);
    background: var(--p-surface-0);
    touch-action: none;
}

.submission-dialog-form :deep(.resident-signature-actions) {
    display: none;
}

.submission-links a:focus-visible,
.submission-section-heading h3:focus-visible,
.submission-document :deep(.resident-report-zoom button:focus-visible) {
    outline: 2px solid var(--resident-blue);
    outline-offset: 2px;
}

@container (min-width: 850px) {
    .submission-links .submission-review-link {
        display: none;
    }

    .submission-facts {
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }

    .submission-workbench {
        grid-template-columns: minmax(0, 1fr) minmax(18rem, 22rem);
    }
}

@media (max-width: 479px) {
    .submission-overview {
        padding-inline: 0.875rem;
    }

    .submission-detail h2 {
        font-size: 1.25rem;
    }

    .submission-section-heading,
    .submission-review-group,
    .submission-decision {
        padding-inline: 0.75rem;
    }
}

@media (pointer: coarse) {
    .submission-dialog-form :is(.p-button, .p-inputtext, .p-select) {
        min-height: 2.75rem;
    }

    .submission-dialog-form :is(.p-inputtext, .p-textarea) {
        font-size: 1rem;
    }
}
</style>
