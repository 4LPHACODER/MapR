<script setup>
import { computed, inject, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
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
const busy = ref(false);
const menuOpen = ref(false);
const viewer = ref(null);
const flagDialog = ref(null);
const returnDialog = ref(null);
const signDialog = ref(null);
const pad = ref(null);
const flagCategory = ref('Photographs');
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
const resolveDialog = ref(null);
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
const wideReview = ref(typeof window !== 'undefined' && window.matchMedia('(min-width: 1024px)').matches);
const reviewOpen = ref(false);
const historyOpen = ref(false);
const menuButton = ref(null);
const panelOpen = computed(() => wideReview.value || reviewOpen.value);
const historyNewest = computed(() => [...(report.value?.timeline || [])].reverse());
const historyVisible = computed(() => (historyOpen.value ? historyNewest.value : historyNewest.value.slice(0, 3)));
const hiddenHistory = computed(() => Math.max(0, historyNewest.value.length - 3));
let stopReviewMedia = () => {};

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
    if (route.query.panel === 'flag') flagDialog.value?.showModal();
    if (route.query.panel === 'return' && canReview.value) returnDialog.value?.showModal();
}

async function downloadPdf() {
    if (busy.value || !report.value) return;
    busy.value = true;
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
        busy.value = false;
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
        flagDialog.value?.close();
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
    resolveDialog.value?.showModal();
}

async function resolveFlag() {
    if (busy.value || !resolveTarget.value) return;
    const typed = resolution.value;
    busy.value = true;
    actionError.value = '';
    try {
        await window.axios.post(`${apiBase}/flags/${resolveTarget.value.id}/resolve`, { resolution: typed.trim() });
        resolveDialog.value?.close();
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
        returnDialog.value?.close();
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
    actionError.value = '';
    try {
        const { data } = await window.axios.post(`${apiBase}/submissions/${submissionId.value}/start-review`);
        report.value = data.data;
        await viewer.value?.reload();
    } catch (exception) {
        actionError.value = exception.response?.data?.message || 'The review could not be started.';
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
    signDialog.value?.showModal();
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
        signDialog.value?.close();
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

function showHistory() {
    menuOpen.value = false;
    reviewOpen.value = true;
    nextTick(() => document.getElementById('bdrrm-review-history')?.scrollIntoView({ block: 'start' }));
}

function onPanelToggle(event) {
    if (!wideReview.value) reviewOpen.value = event.target.open;
}

async function toggleActions() {
    menuOpen.value = !menuOpen.value;
    if (!menuOpen.value) return;
    await nextTick();
    document.querySelector('#bdrrm-action-menu [role="menuitem"]:not(:disabled)')?.focus();
}

function closeActions(restore = true) {
    menuOpen.value = false;
    if (restore) menuButton.value?.focus();
}

function onActionMenuKey(event) {
    const items = [...event.currentTarget.querySelectorAll('[role="menuitem"]:not(:disabled)')];
    const index = items.indexOf(document.activeElement);
    if (event.key === 'Escape') {
        event.preventDefault();
        closeActions();
        return;
    }
    if ((event.key !== 'ArrowDown' && event.key !== 'ArrowUp') || !items.length) return;
    event.preventDefault();
    const next = event.key === 'ArrowDown' ? (index + 1) % items.length : (index - 1 + items.length) % items.length;
    items[next]?.focus();
}

function onDocumentPointer(event) {
    if (!menuOpen.value) return;
    const menu = document.getElementById('bdrrm-action-menu');
    if (menu?.contains(event.target) || menuButton.value?.contains(event.target)) return;
    closeActions(false);
}

function onDocumentKey(event) {
    if (event.key === 'Escape' && menuOpen.value) closeActions();
}

watch(submissionId, () => {
    menuOpen.value = false;
    load();
}, { immediate: true });


onMounted(() => {
    const media = window.matchMedia('(min-width: 1024px)');
    const sync = () => {
        wideReview.value = media.matches;
    };
    sync();
    media.addEventListener('change', sync);
    stopReviewMedia = () => media.removeEventListener('change', sync);
    document.addEventListener('pointerdown', onDocumentPointer);
    document.addEventListener('keydown', onDocumentKey);
});

onBeforeUnmount(() => {
    stopReviewMedia();
    document.removeEventListener('pointerdown', onDocumentPointer);
    document.removeEventListener('keydown', onDocumentKey);
    if (signPreviewUrl) URL.revokeObjectURL(signPreviewUrl);
    if (savedPreview.value) URL.revokeObjectURL(savedPreview.value);
});
</script>

<template>
    <section class="bdrrm-submissions bdrrm-submission-detail">
        <p v-if="phase === 'loading'" class="submission-status">Loading submission…</p>
        <div v-else-if="phase === 'error'" class="submission-status" role="alert">
            <p>{{ error }}</p>
            <RouterLink class="submission-back" :to="{ path: '/submissions', query: backQuery }">Back to submissions</RouterLink>
        </div>
        <template v-else-if="report">
            <header class="bdrrm-detail-toolbar">
                <RouterLink class="submission-back" :to="{ path: '/submissions', query: backQuery }">
                    <MapIcon name="chevron" />
                    {{ backLabel }}
                </RouterLink>
                <div class="bdrrm-detail-identity">
                    <h2>{{ report.reference }}</h2>
                    <span class="submission-badge" :data-status="report.status">{{ report.label }}</span>
                </div>
                <div class="bdrrm-detail-actions">
                    <button v-if="canStartReview" type="button" class="submission-primary" :disabled="busy" @click="startReview">
                        <MapIcon name="clipboard" /> Start review
                    </button>
                    <button v-if="canCheck" type="button" class="submission-primary" :disabled="busy" @click="openSign('secretary')">
                        <MapIcon name="pen-line" /> Check and Sign
                    </button>
                    <button v-if="canEndorse" type="button" class="submission-primary" :disabled="busy" @click="openSign('captain')">
                        <MapIcon name="file-check-2" /> Endorse and Sign
                    </button>
                    <button v-if="canCheck" type="button" class="submission-quiet" :disabled="busy" @click="returnDialog?.showModal()">
                        <MapIcon name="rotate-ccw" /> Return for correction
                    </button>
                    <button type="button" class="submission-quiet" :disabled="busy" @click="downloadPdf">
                        <MapIcon name="download" /> {{ busy ? 'Preparing PDF…' : 'Download PDF' }}
                    </button>
                    <div class="submission-overflow">
                        <button
                            ref="menuButton"
                            type="button"
                            class="submission-quiet"
                            aria-haspopup="menu"
                            :aria-expanded="menuOpen"
                            aria-controls="bdrrm-action-menu"
                            @click="toggleActions"
                        >
                            Actions <MapIcon name="chevron" class="submission-chevron-down" />
                        </button>
                        <div v-if="menuOpen" id="bdrrm-action-menu" class="submission-menu" role="menu" @keydown="onActionMenuKey">
                            <button v-if="canStartReview" type="button" role="menuitem" :disabled="busy" @click="startReview(); closeActions(false)">
                                <MapIcon name="clipboard" /> Start review
                            </button>
                            <button type="button" role="menuitem" :disabled="!canSign || openFlags > 0" :title="!canSign ? 'Start the review before signing.' : (openFlags > 0 ? 'Resolve open flags before accepting.' : '')" @click="openSign('bdrrm'); closeActions(false)">
                                <MapIcon name="clipboard" /> Sign and Accept
                            </button>
                            <button v-if="canCheck" type="button" role="menuitem" :disabled="busy" @click="openSign('secretary'); closeActions(false)">
                                <MapIcon name="pen-line" /> Check and Sign
                            </button>
                            <button v-if="canEndorse" type="button" role="menuitem" :disabled="busy" @click="openSign('captain'); closeActions(false)">
                                <MapIcon name="file-check-2" /> Endorse and Sign
                            </button>
                            <button type="button" role="menuitem" @click="flagDialog?.showModal(); closeActions(false)">
                                <MapIcon name="flag" /> Flag an Issue
                            </button>
                            <button type="button" role="menuitem" :disabled="!canReturn" :title="canReturn ? undefined : 'This report is not waiting for a return.'" @click="returnDialog?.showModal(); closeActions(false)">
                                <MapIcon name="undo" /> Return for Correction
                            </button>
                            <RouterLink v-if="mapLink" role="menuitem" :to="mapLink" @click="closeActions(false)">
                                <MapIcon name="pin" /> View reported location
                            </RouterLink>
                            <button v-else type="button" role="menuitem" disabled>No reported map point</button>
                            <button type="button" role="menuitem" @click="showHistory">
                                <MapIcon name="history" /> Review history
                            </button>
                        </div>
                    </div>
                </div>
            </header>
            <p v-if="downloadError" class="submission-alert" role="alert">{{ downloadError }}</p>
            <div class="bdrrm-review-layout">
                <ReportPdfViewer ref="viewer" presentation="review" :document-url="documentUrl" />
                <details class="bdrrm-review-disclosure" :open="panelOpen" @toggle="onPanelToggle">
                    <summary>
                        <span>Review</span>
                        <span class="submission-badge" :data-status="report.status">{{ report.label }}</span>
                        <span>{{ report.bdrrm_endorsement ? (report.bdrrm_endorsement.signer_name || 'Signed') : 'Not signed yet' }}</span>
                        <span>{{ openFlags }} open {{ openFlags === 1 ? 'flag' : 'flags' }}</span>
                    </summary>
                    <div class="bdrrm-review-body">
                        <section class="bdrrm-review-section">
                            <h3><MapIcon name="clipboard" /> Review status</h3>
                            <span class="submission-badge" :data-status="report.status">{{ report.label }}</span>
                            <p v-if="report.return_reason">{{ report.return_reason }}</p>
                            <p v-else-if="openFlags > 0" class="submission-note">Resolve open flags before accepting this report.</p>
                        </section>
                        <section class="bdrrm-review-section">
                            <h3><MapIcon name="check" /> BDRRM signature</h3>
                            <p v-if="report.bdrrm_endorsement">
                                {{ report.bdrrm_endorsement.signer_name || 'Officer' }}
                                <span class="submission-note">{{ formatWhen(report.bdrrm_endorsement.signed_at) }}</span>
                            </p>
                            <img
                                v-if="report.bdrrm_endorsement?.has_image"
                                class="bdrrm-signature-image"
                                :src="`${apiBase}/submissions/${report.id}/review-signature`"
                                alt="BDRRM reviewer signature"
                            >
                            <p v-else class="submission-note">Not signed yet.</p>
                        </section>
                        <section class="bdrrm-review-section">
                            <h3><MapIcon name="flag" /> Flags</h3>
                            <p>{{ openFlags }} open {{ openFlags === 1 ? 'flag' : 'flags' }}</p>
                            <ul v-if="openFlags > 0" class="submission-timeline">
                                <li v-for="flag in (report.flags || []).filter((item) => item.status === 'open')" :key="flag.id">
                                    <span class="submission-dot" aria-hidden="true"></span>
                                    <strong>{{ flag.category }}</strong>
                                    <p>{{ flag.reason }}</p>
                                    <button type="button" class="submission-quiet" :disabled="busy" @click="openResolve(flag)">Resolve</button>
                                </li>
                            </ul>
                            <p v-else class="submission-note">No open flags</p>
                        </section>
                        <section id="bdrrm-review-history" class="bdrrm-review-section">
                            <h3><MapIcon name="history" /> History</h3>
                            <ol class="submission-timeline">
                                <li v-for="(event, index) in historyVisible" :key="`${event.at}-${index}`">
                                    <span class="submission-dot" aria-hidden="true"></span>
                                    <strong>{{ event.title }}</strong>
                                    <span>{{ formatWhen(event.at) }}</span>
                                    <p v-if="event.detail">{{ event.detail }}</p>
                                </li>
                            </ol>
                            <button v-if="hiddenHistory > 0" type="button" class="submission-quiet" @click="historyOpen = !historyOpen">
                                {{ historyOpen ? 'Hide earlier events' : `Show earlier events (${hiddenHistory})` }}
                            </button>
                        </section>
                        <section class="bdrrm-review-section">
                            <h3><MapIcon name="pin" /> Location</h3>
                            <RouterLink v-if="mapLink" class="submission-quiet" :to="mapLink">
                                <MapIcon name="pin" /> View reported location
                            </RouterLink>
                            <p v-else class="submission-note">No reported map point</p>
                        </section>
                    </div>
                </details>
            </div>
            <dialog ref="flagDialog" class="bdrrm-dialog" @close="actionError = ''">
                <form @submit.prevent="submitFlag">
                    <h3>Flag an issue</h3>
                    <p class="submission-note">{{ report.reference }}</p>
                    <p v-if="actionError" class="submission-alert" role="alert">{{ actionError }}</p>
                    <label>
                        <span>Category</span>
                        <select v-model="flagCategory">
                            <option>Photographs</option>
                            <option>Description</option>
                            <option>Location</option>
                            <option>Other</option>
                        </select>
                    </label>
                    <label>
                        <span>Section or photo, if useful</span>
                        <input v-model="flagSection" maxlength="120" autocomplete="off">
                    </label>
                    <label>
                        <span>What needs attention?</span>
                        <textarea v-model="flagReason" rows="4" maxlength="2000" required />
                    </label>
                    <div class="bdrrm-dialog-actions">
                        <button type="submit" class="submission-primary" :disabled="busy" :aria-busy="busy">{{ busy ? 'Saving…' : 'Save flag' }}</button>
                        <button type="button" class="submission-quiet" @click="flagDialog?.close()">Cancel</button>
                    </div>
                </form>
            </dialog>
            <dialog ref="resolveDialog" class="bdrrm-dialog">
                <form @submit.prevent="resolveFlag">
                    <h3>Resolve flag</h3>
                    <p class="submission-note">{{ report.reference }}</p>
                    <p v-if="actionError" class="submission-alert" role="alert">{{ actionError }}</p>
                    <label>
                        <span>Resolution</span>
                        <textarea v-model="resolution" rows="4" maxlength="2000" required />
                    </label>
                    <div class="bdrrm-dialog-actions">
                        <button type="submit" class="submission-primary" :disabled="busy" :aria-busy="busy">{{ busy ? 'Saving…' : 'Save resolution' }}</button>
                        <button type="button" class="submission-quiet" @click="resolveDialog?.close()">Cancel</button>
                    </div>
                </form>
            </dialog>
            <dialog ref="returnDialog" class="bdrrm-dialog">
                <form @submit.prevent="returnReport">
                    <h3>Return Report for Correction</h3>
                    <p class="submission-note">{{ report.reference }}</p>
                    <p v-if="actionError" class="submission-alert" role="alert">{{ actionError }}</p>
                    <label>
                        <span>What needs to be corrected?</span>
                        <textarea v-model="returnReason" rows="5" maxlength="5000" required />
                    </label>
                    <div class="bdrrm-dialog-actions">
                        <button type="submit" class="submission-primary" :disabled="busy || !canReturn" :aria-busy="busy">{{ busy ? 'Returning…' : 'Return report' }}</button>
                        <button type="button" class="submission-quiet" @click="returnDialog?.close()">Cancel</button>
                    </div>
                </form>
            </dialog>
            <dialog ref="signDialog" class="bdrrm-dialog" @close="signOpen = false">
                <form @submit.prevent="signAndAccept">
                    <h3>{{ signTitle }}</h3>
                    <p class="submission-note">{{ report.reference }} · {{ report.resident_name || 'Resident' }} · {{ report.incident_name || 'Incident' }}</p>
                    <p v-if="actionError" class="submission-alert" role="alert">{{ actionError }}</p>
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
                    <div class="bdrrm-dialog-actions">
                        <button type="button" class="submission-quiet" @click="clearSignature">Clear</button>
                        <button type="button" class="submission-quiet" @click="chooseSaved">Use saved signature</button>
                    </div>
                    <img v-if="useSaved && savedPreview" :src="savedPreview" alt="Saved signature preview" class="bdrrm-sign-preview">
                    <img v-else-if="signPreview" :src="signPreview" alt="Signature preview" class="bdrrm-sign-preview">
                    <label class="bdrrm-confirm">
                        <input v-model="signConfirmed" type="checkbox">
                        <span>{{ signMode === 'secretary' ? 'I confirm this checking decision.' : (signMode === 'captain' ? 'I confirm this endorsement.' : 'I confirm this review and acceptance.') }}</span>
                    </label>
                    <div class="bdrrm-dialog-actions">
                        <button type="submit" class="submission-primary" :disabled="busy || !signConfirmed" :aria-busy="busy">{{ busy ? 'Signing…' : signSubmitLabel }}</button>
                        <button type="button" class="submission-quiet" @click="signDialog?.close()">Cancel</button>
                    </div>
                </form>
            </dialog>
        </template>
    </section>
</template>
